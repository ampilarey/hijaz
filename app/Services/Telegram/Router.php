<?php

namespace App\Services\Telegram;

use App\Models\Booking;
use App\Models\BusGroup;
use App\Models\Document;
use App\Models\ExchangeRate;
use App\Models\ExcursionBooking;
use App\Models\Payment;
use App\Models\Pilgrim;
use App\Models\TelegramLink;
use App\Models\TelegramUpdate;
use App\Models\Trip;
use App\Models\TripHotel;
use App\Models\User;
use App\Services\Documents\DocumentWallet;
use App\Services\Documents\ReaderManager;
use App\Services\Invoices\Documents;
use App\Services\Operations\Lists;
use App\Services\Operations\Readiness;
use App\Services\Payments\SlipIntake;
use App\Support\AuditContext;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;

/**
 * Turns one Telegram update into one reply.
 *
 * A linked chat acts as its staff account: the account is signed in for the
 * duration of the update, so policies, permissions and the audit trail see
 * a person, not a bot. An unknown chat is told how to link and nothing else.
 * Button taps go to Callbacks; a chat that owes an answer (a refusal's
 * reason) has its next message read as that answer.
 */
class Router
{
    public function __construct(
        private readonly Bot $bot,
        private readonly Linker $linker,
        private readonly DailySummary $summary,
        private readonly Callbacks $callbacks,
    ) {}

    /** @var array<string, mixed> */
    private array $currentMessage = [];

    /** @param  array<string, mixed>  $update */
    public function handle(array $update, TelegramUpdate $record): void
    {
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->handleCallback($update['callback_query'], $record);

            return;
        }

        $message = $update['message'] ?? null;
        $this->currentMessage = is_array($message) ? $message : [];

        if (! is_array($message) || ! isset($message['chat']['id'])) {
            $record->forceFill(['kind' => 'ignored', 'handled_at' => now()])->save();

            return;
        }

        $chatId = (int) $message['chat']['id'];
        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
        [$command, $argument] = $this->parse($text);

        if (isset($message['photo']) || isset($message['document'])) {
            $command = '/upload';
            $argument = $text;
        }

        $record->forceFill(['chat_id' => $chatId, 'kind' => 'message', 'command' => $command])->save();

        if ($command === '/start' && $argument !== '') {
            $this->link($chatId, $argument, $message['from'] ?? []);
            $record->forceFill(['handled_at' => now()])->save();

            return;
        }

        $link = $this->linker->linkForChat($chatId);

        if ($link === null) {
            $this->replyToStranger($chatId, $record);

            return;
        }

        $link->forceFill(['last_seen_at' => now()])->save();

        $this->as($link, function () use ($command, $argument, $link, $text): void {
            if ($command === '' && ($link->pending ?? null) !== null) {
                $this->callbacks->resume($link, $text);

                return;
            }

            $this->dispatch($command, $argument, $link);
        });

        $record->forceFill(['handled_at' => now()])->save();
    }

    /** @param  array<string, mixed>  $callback */
    private function handleCallback(array $callback, TelegramUpdate $record): void
    {
        $chatId = (int) ($callback['message']['chat']['id'] ?? $callback['from']['id'] ?? 0);
        $data = (string) ($callback['data'] ?? '');
        $record->forceFill(['chat_id' => $chatId, 'kind' => 'callback', 'command' => mb_substr(strtok($data, ':') ?: $data, 0, 40)])->save();

        $link = $chatId > 0 ? $this->linker->linkForChat($chatId) : null;

        if ($link === null) {
            $this->bot->answerCallbackQuery((string) ($callback['id'] ?? ''), 'This chat is not linked to the portal.');
            $record->forceFill(['handled_at' => now()])->save();

            return;
        }

        $link->forceFill(['last_seen_at' => now()])->save();
        $this->as($link, fn () => $this->callbacks->handle($data, $link, $callback));
        $record->forceFill(['handled_at' => now()])->save();
    }

    /** Run something as the linked person, with the audit trail knowing it was by chat. */
    private function as(TelegramLink $link, \Closure $work): void
    {
        AuditContext::use(AuditContext::TELEGRAM);
        Auth::setUser($link->user);

        try {
            $work();
        } finally {
            Auth::forgetUser();
            AuditContext::reset();
        }
    }

    private function dispatch(string $command, string $argument, TelegramLink $link): void
    {
        $user = $link->user;
        $chat = $link->chat_id;

        match ($command) {
            '/start', '/help' => $this->bot->sendMessage($chat, $this->help($user->name)),
            '/me' => $this->bot->sendMessage($chat, implode("\n", [
                '<b>'.e($user->name).'</b>',
                e($user->email),
                'Role: '.e($user->getRoleNames()->implode(', ') ?: 'none'),
                'Linked '.e($link->linked_at->diffForHumans()).'.',
            ])),
            '/today' => $this->bot->sendMessage($chat, $this->summary->for($user)),
            '/trip', '/trips' => $this->bot->sendMessage($chat, $this->trips($argument, $user)),
            '/find' => $this->bot->sendMessage($chat, $this->find($argument)),
            '/balance' => $this->bot->sendMessage($chat, $this->balance($argument, $user)),
            '/slips', '/payments' => $this->slips($user, $chat),
            '/ready' => $this->bot->sendMessage($chat, $this->ready($argument, $user)),
            '/rates' => $this->bot->sendMessage($chat, $this->rates($user)),
            '/checklist' => $this->checklist($argument, $user, $chat),
            '/bus' => $this->busList($argument, $user, $chat),
            '/room', '/rooms' => $this->roomList($argument, $user, $chat),
            '/manifest', '/list' => $this->manifest($argument, $user, $chat),
            '/invoice' => $this->invoice($argument, $user, $chat),
            '/receipt' => $this->receipt($argument, $user, $chat),
            '/upload' => $this->upload($link, $argument, $this->currentMessage),
            '/unlink' => $this->unlink($link),
            default => $this->bot->sendMessage($chat, "I don't know that one yet. Send /help for what I can do."),
        };
    }

    /** @param  array<string, mixed>  $from */
    private function link(int $chatId, string $code, array $from): void
    {
        $link = $this->linker->redeem($code, $chatId, [
            'username' => $from['username'] ?? null,
            'name' => trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: null,
        ]);

        if ($link === null) {
            $this->bot->sendMessage($chatId, 'That code is not valid any more. Open the portal, go to Telegram, and get a new one.');

            return;
        }

        $this->bot->sendMessage($chatId, 'Linked to <b>'.e($link->user->name).'</b>. '.$this->help($link->user->name, greeting: false));
    }

    private function unlink(TelegramLink $link): void
    {
        $link->forceFill(['revoked_at' => now()])->save();
        $this->bot->sendMessage($link->chat_id, 'Unlinked. This chat no longer acts as '.e($link->user->name).'. Link again from the portal whenever you like.');
    }

    private function replyToStranger(int $chatId, TelegramUpdate $record): void
    {
        // One explanation per chat per day; a stranger does not get a conversation.
        $alreadyTold = TelegramUpdate::query()
            ->where('chat_id', $chatId)
            ->where('kind', 'stranger')
            ->where('received_at', '>=', now()->subDay())
            ->exists();

        $record->forceFill(['kind' => 'stranger', 'handled_at' => now()])->save();

        if ($alreadyTold) {
            return;
        }

        $this->bot->sendMessage($chatId, 'This is the Hijaz staff bot. To use it, sign in to the portal, open Telegram in the menu, and tap the link it gives you.');
    }

    // ── Asking ───────────────────────────────────────────────────────────

    private function trips(string $argument, User $user): string
    {
        if ($argument !== '') {
            $trip = $this->tripFor($argument);

            if ($trip === null) {
                return 'No trip matches "'.e($argument).'". Send /trip for the list.';
            }

            $lines = [
                '<b>'.e($trip->name).'</b> · '.e($trip->code),
                e($trip->datesLabel()).' · '.e($trip->statusLabel()),
                'Seats: '.$trip->seatsTaken().' of '.$trip->capacity_total,
            ];

            if ($route = $trip->routeSummary()) {
                $lines[] = 'Route: '.e($route);
            }

            foreach ($trip->hotels as $hotel) {
                $lines[] = e($hotel->cityLabel()).': '.e($hotel->name).' ('.e($hotel->check_in_on->format('j M')).' – '.e($hotel->check_out_on->format('j M')).')';
            }

            if ($user->can('booking.viewAny')) {
                $bookings = $trip->bookings()->live()->get();
                $totals = [];
                $paid = [];

                foreach ($bookings as $b) {
                    foreach ($b->currencies() as $c) {
                        $totals[$c] = ($totals[$c] ?? 0) + $b->total($c)->minor;
                        $paid[$c] = ($paid[$c] ?? 0) + $b->paidIn($c)->minor;
                    }
                }

                ksort($totals);
                $lines[] = 'Bookings: '.$bookings->count().' live'.($totals === [] ? '' : ' · '.implode(' · ', array_map(fn (string $c): string => Money::ofMinor($paid[$c] ?? 0, $c)->format().' of '.Money::ofMinor($totals[$c], $c)->format().' received', array_keys($totals))));
            }

            if ($user->can('readiness.view')) {
                $blockers = app(Readiness::class)->blockers($trip);
                $lines[] = $blockers === [] ? 'Readiness: nothing blocks.' : 'Readiness: ⛔ '.implode('; ', $blockers).' (/ready '.e($trip->code).')';
            }

            $open = $trip->checklist()->open()->count();

            if ($open > 0) {
                $lines[] = $open.' checklist item'.($open === 1 ? '' : 's').' still open (/checklist '.e($trip->code).').';
            }

            return implode("\n", $lines);
        }

        $trips = Trip::query()->upcoming()->limit(10)->get();

        if ($trips->isEmpty()) {
            return 'No upcoming trips. Create one in the portal under Trips.';
        }

        return implode("\n", array_merge(['<b>Upcoming trips</b>'], $trips->map(fn (Trip $t): string => e($t->code).' · '.e($t->name).' · '.e($t->departsLabel()).' · '.$t->seatsTaken().'/'.$t->capacity_total.' seats · '.e(strtolower($t->statusLabel())))->all(), ['', 'Send /trip CODE for one trip.']));
    }

    private function find(string $argument): string
    {
        $term = trim($argument);

        if ($term === '') {
            return 'Send /find A123456 or /find part of a name.';
        }

        $query = Pilgrim::query()->with(['customer', 'documents']);

        if (preg_match('/^[Aa]\s?\d{6}$/', $term)) {
            $query->withIdNumber($term);
        } else {
            $query->where(fn ($q) => $q->where('name_en', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
        }

        $pilgrims = $query->limit(5)->get();

        if ($pilgrims->isEmpty()) {
            return 'Nobody matches "'.e($term).'".';
        }

        return $pilgrims->map(function (Pilgrim $p): string {
            $docs = collect(Document::REQUIRED_FOR_PILGRIMS)->map(fn (string $t): string => Document::TYPES[$t].': '.($p->document($t)?->status ?? 'missing'))->implode(', ');
            $booking = Auth::user()?->can('booking.viewAny') ? $p->customer?->bookings()->live()->latest('id')->first() : null;

            return implode("\n", array_filter([
                '<b>'.e($p->name_en).'</b> · #'.$p->id.' · '.e($p->registration_status),
                e($p->idNumberMasked() ?? 'no ID number').' · '.e($p->customer?->name ?? ''),
                $p->phone ? e($p->phone) : null,
                $docs,
                $booking ? e($booking->reference).' · '.e($booking->trip->code).' · '.e($booking->balanceSummary()) : null,
                $p->unexplainedMismatches() !== [] ? '⚠ ID card and passport disagree' : null,
            ]));
        })->implode("\n\n");
    }

    /** What a booking owes, by reference, ID number or customer name. */
    private function balance(string $argument, User $user): string
    {
        if (! $user->can('booking.viewAny')) {
            return 'Your account cannot see bookings.';
        }

        $term = trim($argument);

        if ($term === '') {
            return 'Send /balance HJZ-B-2027-0001, /balance A123456 or /balance part of a name.';
        }

        $query = Booking::query()->with(['customer', 'trip'])->live();

        if (preg_match('/^HJZ-?B-?\d{4}-?\d{4}$/i', $term)) {
            $query->where('reference', $this->reference($term));
        } elseif (preg_match('/^[Aa]\s?\d{6}$/', $term)) {
            $query->whereHas('pilgrims.pilgrim', fn ($p) => $p->withIdNumber($term));
        } else {
            $query->whereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
        }

        $bookings = $query->orderByDesc('id')->limit(5)->get();

        if ($bookings->isEmpty()) {
            return 'No live booking matches "'.e($term).'".';
        }

        return $bookings->map(function (Booking $b): string {
            $waiting = $b->payments()->awaitingReview()->count();

            return implode("\n", array_filter([
                '<b>'.e($b->reference).'</b> · '.e($b->customer->name).' · '.e($b->trip->code),
                e($b->statusLabel()).' · '.$b->seats.' seat'.($b->seats === 1 ? '' : 's'),
                implode(' · ', array_map(fn (string $c): string => e($b->total($c)->format()).' total, '.e($b->paidIn($c)->format()).' received', $b->currencies())) ?: 'nothing priced yet',
                '<b>'.e(ucfirst($b->balanceSummary())).'</b>',
                $waiting > 0 ? $waiting.' payment'.($waiting === 1 ? '' : 's').' waiting for Finance.' : null,
                '/invoice '.e($b->reference).' sends the invoice.',
            ]));
        })->implode("\n\n");
    }

    /** The readiness board for one trip, or the next one. */
    private function ready(string $argument, User $user): string
    {
        if (! $user->can('readiness.view')) {
            return 'Your account cannot see the readiness board.';
        }

        $trip = $this->tripFor($argument);

        if ($trip === null) {
            return $argument === '' ? 'No upcoming trip.' : 'No trip matches "'.e($argument).'".';
        }

        $concerns = app(Readiness::class)->concerns($trip);
        $lines = ['<b>'.e($trip->name).'</b> · '.e($trip->code).' · '.e($trip->departs_on ? 'departs '.$trip->departs_on->format('j M').' ('.$trip->departs_on->diffForHumans().')' : 'dates not set')];

        if ($concerns === []) {
            $lines[] = 'Ready. Nothing stands in the way.';
        }

        foreach ($concerns as $c) {
            $lines[] = ($c['severity'] === Readiness::BLOCKING ? '⛔ ' : '⚠ ').e($c['headline']);
        }

        $lines[] = '';
        $lines[] = 'Details: '.e(Alerts::portalUrl('trips/'.$trip->getKey().'/readiness'));

        return implode("\n", $lines);
    }

    /** The payments waiting to be verified, each with its buttons. */
    private function slips(User $user, int $chat): void
    {
        if (! $user->can('payment.view')) {
            $this->bot->sendMessage($chat, 'Your account cannot see payments.');

            return;
        }

        $waiting = Payment::query()->awaitingReview()->with('payable')->orderBy('id')->limit(10)->get();

        if ($waiting->isEmpty()) {
            $this->bot->sendMessage($chat, 'Nothing waiting. Every recorded payment has been verified.');

            return;
        }

        $this->bot->sendMessage($chat, '<b>'.$waiting->count().' payment'.($waiting->count() === 1 ? '' : 's').' to verify</b>'.($waiting->count() === 10 ? ' (first ten)' : ''));

        foreach ($waiting as $p) {
            $text = e($p->reference).' · '.e($p->money()->format()).' · '.e(Payment::METHODS[$p->method] ?? $p->method)."\n".'For '.e($p->payable?->payableLabel() ?? '').($p->slip() ? '' : "\nNo slip attached.");
            $buttons = $user->can('payment.verify') ? Bot::keyboard([['pay.verify:'.$p->getKey() => 'Verify', 'pay.refuse:'.$p->getKey() => 'Not received']]) : [];
            $this->bot->sendMessage($chat, $text, $buttons);
        }
    }

    private function rates(User $user): string
    {
        if (! $user->can('rate.viewAny')) {
            return 'Your account cannot see exchange rates.';
        }

        $lines = ['<b>Exchange rates in force</b>'];
        $any = false;

        foreach (ExchangeRate::pairs() as [$from, $to]) {
            $rate = ExchangeRate::inForce($from, $to);

            if ($rate !== null) {
                $any = true;
                $lines[] = e($rate->label).' · since '.e($rate->as_of->format('j M Y'));
            }
        }

        if (! $any) {
            $lines[] = 'None set. Finance sets them under Money → Exchange rates.';
        }

        return implode("\n", $lines);
    }

    /** Open checklist items on a trip, each with a tick button. */
    private function checklist(string $argument, User $user, int $chat): void
    {
        if (! $user->can('checklist.viewAny')) {
            $this->bot->sendMessage($chat, 'Your account cannot see checklists.');

            return;
        }

        $trip = $this->tripFor($argument);

        if ($trip === null) {
            $this->bot->sendMessage($chat, $argument === '' ? 'No upcoming trip.' : 'No trip matches "'.e($argument).'".');

            return;
        }

        $items = $trip->checklist()->open()->orderBy('due_on')->limit(10)->get();

        if ($items->isEmpty()) {
            $this->bot->sendMessage($chat, 'Nothing open on the checklist of '.e($trip->code).'.');

            return;
        }

        $rows = $items->map(fn ($i): array => ['check.tick:'.$i->getKey() => ($i->isOverdue() ? '⚠ ' : '☐ ').mb_substr($i->title, 0, 40).($i->due_on ? ' · '.$i->due_on->format('j M') : '')])->all();
        $this->bot->sendMessage($chat, '<b>Checklist · '.e($trip->code).'</b>'."\n".$items->count().' open. Tap one to tick it.', $user->can('checklist.tick') ? Bot::keyboard($rows) : []);
    }

    // ── PDFs by chat ─────────────────────────────────────────────────────

    private function busList(string $argument, User $user, int $chat): void
    {
        if (! $user->can('list.view')) {
            $this->bot->sendMessage($chat, 'Your account cannot see lists.');

            return;
        }

        [$which, $code] = array_pad(preg_split('/\s+/', trim($argument), 2) ?: [], 2, '');
        $trip = $this->tripFor($code);

        if ($trip === null) {
            $this->bot->sendMessage($chat, 'Send /bus 1, or /bus 1 UMR-2027-07 for another trip.');

            return;
        }

        $bus = $trip->busGroups()->where('name', 'like', '%'.$which.'%')->orWhere(fn ($q) => $q->where('trip_id', $trip->getKey())->where('name', 'Bus '.$which))->first();

        if (! $bus instanceof BusGroup) {
            $this->bot->sendMessage($chat, 'No bus "'.e($which).'" on '.e($trip->code).'. Buses: '.e($trip->busGroups()->pluck('name')->implode(', ') ?: 'none yet').'.');

            return;
        }

        $list = app(Lists::class)->busList($bus);
        $this->bot->sendDocument($chat, $list->filename.'.pdf', app(Lists::class)->pdf($list)->output(), $bus->name.' · '.$trip->code.' · '.count($list->rows).' on board');
    }

    private function roomList(string $argument, User $user, int $chat): void
    {
        if (! $user->can('list.view')) {
            $this->bot->sendMessage($chat, 'Your account cannot see lists.');

            return;
        }

        $forHotel = (bool) preg_match('/\bhotel\b/i', $argument);
        [$city, $code] = array_pad(preg_split('/\s+/', trim(preg_replace('/\bhotel\b/i', '', $argument) ?? '')) ?: [], 2, '');
        $trip = $this->tripFor((string) $code);

        if ($trip === null || $city === '') {
            $this->bot->sendMessage($chat, 'Send /room makkah or /room madinah, with a trip code after it for another trip. Add "hotel" for the hotel copy.');

            return;
        }

        $hotel = $trip->hotels()->where('city', strtolower($city))->orWhere(fn ($q) => $q->where('trip_id', $trip->getKey())->where('name', 'like', '%'.$city.'%'))->first();

        if (! $hotel instanceof TripHotel) {
            $this->bot->sendMessage($chat, 'No hotel "'.e($city).'" on '.e($trip->code).'.');

            return;
        }

        $list = app(Lists::class)->roomList($hotel, $forHotel);
        $this->bot->sendDocument($chat, $list->filename.'.pdf', app(Lists::class)->pdf($list)->output(), $list->title.' · '.$trip->code);
    }

    private function manifest(string $argument, User $user, int $chat): void
    {
        if (! $user->can('list.view')) {
            $this->bot->sendMessage($chat, 'Your account cannot see lists.');

            return;
        }

        $words = preg_split('/\s+/', trim($argument)) ?: [];
        $preset = 'office';
        $code = '';

        foreach ($words as $w) {
            if (array_key_exists(strtolower($w), Lists::PRESETS)) {
                $preset = strtolower($w);
            } elseif ($w !== '') {
                $code = $w;
            }
        }

        $trip = $this->tripFor($code);

        if ($trip === null) {
            $this->bot->sendMessage($chat, 'Send /manifest, or /manifest airline UMR-2027-07. Shapes: '.implode(', ', array_keys(Lists::PRESETS)).'.');

            return;
        }

        $list = app(Lists::class)->manifest($trip, $preset);
        $this->bot->sendDocument($chat, $list->filename.'.pdf', app(Lists::class)->pdf($list)->output(), $list->title.' · '.$trip->code.' · '.count($list->rows).' travellers');
    }

    private function invoice(string $argument, User $user, int $chat): void
    {
        if (! $user->can('booking.view')) {
            $this->bot->sendMessage($chat, 'Your account cannot see bookings.');

            return;
        }

        $ref = $this->reference($argument);
        $booking = Booking::query()->where('reference', $ref)->first();
        $xb = $booking === null ? ExcursionBooking::query()->where('reference', $ref)->first() : null;

        if ($booking === null && $xb === null) {
            $this->bot->sendMessage($chat, 'Send /invoice HJZ-B-2027-0001 (or an HJZ-X reference for an additional trip).');

            return;
        }

        $docs = app(Documents::class);

        if ($booking !== null) {
            $this->bot->sendDocument($chat, $docs->invoiceFilename($booking), $docs->invoice($booking)->output(), $booking->reference.' · '.$booking->customer->name.' · '.$booking->balanceSummary());
        } else {
            $this->bot->sendDocument($chat, $docs->excursionInvoiceFilename($xb), $docs->excursionInvoice($xb)->output(), $xb->reference.' · '.$xb->payableLabel());
        }
    }

    private function receipt(string $argument, User $user, int $chat): void
    {
        if (! $user->can('payment.download')) {
            $this->bot->sendMessage($chat, 'Your account cannot send receipts.');

            return;
        }

        $payment = Payment::query()->where('reference', $this->reference($argument))->first();

        if (! $payment instanceof Payment) {
            $this->bot->sendMessage($chat, 'Send /receipt HJZ-P-2027-0001.');

            return;
        }

        if ($payment->status !== Payment::SUCCEEDED) {
            $this->bot->sendMessage($chat, e($payment->reference).' is '.e(strtolower($payment->statusLabel())).'; a receipt exists only for a verified payment.');

            return;
        }

        $docs = app(Documents::class);
        $this->bot->sendDocument($chat, $docs->receiptFilename($payment), $docs->receipt($payment)->output(), $payment->reference.' · '.$payment->money()->format().' · forward this to the customer');
    }

    // ── Uploading ────────────────────────────────────────────────────────

    /**
     * A photo or file sent to the bot. With a booking reference in the
     * caption ("HJZ-B-2027-0001 10000") it is a bank slip, recorded as a
     * payment waiting for Finance. With a pilgrim's ID number ("A123456
     * passport") it is a copy, stored and read as an upload in the portal
     * would be.
     *
     * @param  array<string, mixed>  $message
     */
    private function upload(TelegramLink $link, string $caption, array $message): void
    {
        if (isset($message['photo'])) {
            $photos = $message['photo'];
            $largest = end($photos);
            $fileId = (string) $largest['file_id'];
            $name = 'telegram-'.$largest['file_unique_id'].'.jpg';
            $mime = 'image/jpeg';
        } else {
            $fileId = (string) $message['document']['file_id'];
            $name = (string) ($message['document']['file_name'] ?? 'telegram-file');
            $mime = (string) ($message['document']['mime_type'] ?? 'application/octet-stream');
        }

        if (! in_array($mime, (array) config('documents.mime_types'), true)) {
            $this->bot->sendMessage($link->chat_id, 'Send a photo, or a PDF or JPEG file.');

            return;
        }

        if (preg_match('/HJZ-?[BX]-?\d{4}-?\d{4}/i', $caption, $m)) {
            $this->uploadSlip($link, $caption, $this->reference($m[0]), $fileId, $name, $mime);

            return;
        }

        if (! $link->user->can('document.create')) {
            $this->bot->sendMessage($link->chat_id, 'Your account cannot upload copies.');

            return;
        }

        if (! preg_match('/([Aa]\s?\d{6})/', $caption, $m)) {
            $this->bot->sendMessage($link->chat_id, "Add the pilgrim's ID number to the caption, like <b>A123456 passport</b> (types: id, passport, photo, visa), or a booking reference for a bank slip, like <b>HJZ-B-2027-0001 10000</b>.");

            return;
        }

        $pilgrim = Pilgrim::findByIdNumber($m[1]);

        if ($pilgrim === null) {
            $this->bot->sendMessage($link->chat_id, 'No pilgrim has the ID number '.e(strtoupper($m[1])).'. Register them in the portal first.');

            return;
        }

        $type = match (true) {
            (bool) preg_match('/passport/i', $caption) => Document::PASSPORT,
            (bool) preg_match('/photo/i', $caption) => Document::PHOTO,
            (bool) preg_match('/visa/i', $caption) => Document::VISA,
            (bool) preg_match('/vacc/i', $caption) => Document::VACCINATION,
            default => Document::ID_CARD,
        };

        $bytes = $this->fetch($fileId);
        $wallet = app(DocumentWallet::class);
        $version = $wallet->store($pilgrim, $bytes, $name, $mime, $type);
        $reply = Document::TYPES[$type].' saved for <b>'.e($pilgrim->name_en).'</b>.';

        if ($version->document->isReadable()) {
            $x = app(ReaderManager::class)->read($version);
            $reply .= ' '.count($x->fields ?? []).' fields read; accept them in the portal or from the alert.';
        }

        $this->bot->sendMessage($link->chat_id, $reply);
    }

    private function uploadSlip(TelegramLink $link, string $caption, string $reference, string $fileId, string $name, string $mime): void
    {
        if (! $link->user->can('payment.create')) {
            $this->bot->sendMessage($link->chat_id, 'Your account cannot record payments.');

            return;
        }

        $payable = Booking::query()->where('reference', $reference)->first() ?? ExcursionBooking::query()->where('reference', $reference)->first();

        if ($payable === null) {
            $this->bot->sendMessage($link->chat_id, 'No booking has the reference '.e($reference).'.');

            return;
        }

        $typed = null;

        if (preg_match('/\b(MVR|USD|SAR)?\s*([\d,]+(?:\.\d{1,2})?)\s*(MVR|USD|SAR)?\b/i', preg_replace('/HJZ-?[BX]-?\d{4}-?\d{4}/i', '', $caption) ?? '', $m)) {
            $currency = strtoupper($m[1] ?: ($m[3] ?: Currency::MVR));

            try {
                $typed = Money::ofMajor(str_replace(',', '', $m[2]), $currency);
            } catch (\InvalidArgumentException) {
                $typed = null;
            }
        }

        $result = app(SlipIntake::class)->record($payable, $this->fetch($fileId), $name, $mime, $typed);

        if ($result['payment'] === null) {
            $this->bot->sendMessage($link->chat_id, e($result['problem'] ?? 'Could not record the slip.').' Put the amount in the caption, like <b>'.e($reference).' 10000</b>.');

            return;
        }

        $payment = $result['payment'];
        $this->bot->sendMessage($link->chat_id, implode("\n", array_filter([
            '✅ <b>'.e($payment->reference).'</b> recorded: '.e($payment->money()->format()).' for '.e($payable->payableLabel()).'.',
            'Finance verifies it next.',
            $result['warnings'] !== [] ? '⚠ '.e(implode(' ', $result['warnings'])) : null,
        ])));
    }

    private function fetch(string $fileId): string
    {
        $info = $this->bot->getFile($fileId);

        return $this->bot->download((string) ($info['file_path'] ?? ''));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** The trip named, or the next one when nothing is. */
    private function tripFor(string $argument): ?Trip
    {
        $argument = trim($argument);

        if ($argument === '') {
            return Trip::query()->upcoming()->first();
        }

        return Trip::query()->where('code', strtoupper($argument))->orWhere('name', 'like', '%'.$argument.'%')->orderByDesc('departs_on')->first();
    }

    /** "hjzb20270001" → "HJZ-B-2027-0001". */
    private function reference(string $text): string
    {
        $text = strtoupper(trim($text));

        return preg_match('/HJZ-?([BPX])-?(\d{4})-?(\d{4})/', $text, $m) ? 'HJZ-'.$m[1].'-'.$m[2].'-'.$m[3] : $text;
    }

    private function help(string $name, bool $greeting = true): string
    {
        $lines = $greeting ? ['Hello '.e($name).'. I can:', ''] : ['I can:', ''];

        return implode("\n", array_merge($lines, [
            '/today — this morning\'s summary',
            '/trip — upcoming trips, or /trip UMR-2027-07 for one with money and readiness',
            '/ready — what blocks the next trip, or /ready UMR-2027-07',
            '/find A123456 — a pilgrim by ID number or name',
            '/balance HJZ-B-2027-0001 — what a booking owes (or by ID number or name)',
            '/slips — payments waiting, with Verify / Not received buttons',
            '/checklist — open items on the next trip, tap to tick',
            '/rates — exchange rates in force',
            '/bus 1, /room makkah, /manifest airline — lists as PDF',
            '/invoice HJZ-B-2027-0001, /receipt HJZ-P-2027-0001 — PDFs to forward',
            'Send a photo with "A123456 passport" as the caption to upload a copy, or "HJZ-B-2027-0001 10000" for a bank slip',
            '/me — who this chat acts as',
            '/unlink — stop acting as you',
            '/help — this list',
        ]));
    }

    /** @return array{string, string} */
    private function parse(string $text): array
    {
        if (! str_starts_with($text, '/')) {
            return ['', $text];
        }

        $parts = preg_split('/\s+/', $text, 2) ?: [$text];
        $command = strtolower(preg_replace('/@\w+$/', '', $parts[0]) ?? $parts[0]);

        return [$command, trim($parts[1] ?? '')];
    }
}
