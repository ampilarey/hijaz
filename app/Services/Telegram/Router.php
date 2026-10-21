<?php

namespace App\Services\Telegram;

use App\Models\Document;
use App\Models\Pilgrim;
use App\Models\TelegramLink;
use App\Models\TelegramUpdate;
use App\Models\Trip;
use App\Services\Documents\DocumentWallet;
use App\Services\Documents\ReaderManager;
use App\Support\AuditContext;
use Illuminate\Support\Facades\Auth;

/**
 * Turns one Telegram update into one reply.
 *
 * A linked chat acts as its staff account: the account is signed in for the
 * duration of the update, so policies, permissions and the audit trail see
 * a person, not a bot. An unknown chat is told how to link and nothing else.
 */
class Router
{
    public function __construct(
        private readonly Bot $bot,
        private readonly Linker $linker,
        private readonly DailySummary $summary,
    ) {}

    /** @var array<string, mixed> */
    private array $currentMessage = [];

    /** @param  array<string, mixed>  $update */
    public function handle(array $update, TelegramUpdate $record): void
    {
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

        AuditContext::use(AuditContext::TELEGRAM);
        Auth::setUser($link->user);

        try {
            $this->dispatch($command, $argument, $link);
        } finally {
            Auth::forgetUser();
            AuditContext::reset();
        }

        $record->forceFill(['handled_at' => now()])->save();
    }

    private function dispatch(string $command, string $argument, TelegramLink $link): void
    {
        $user = $link->user;

        match ($command) {
            '/start', '/help' => $this->bot->sendMessage($link->chat_id, $this->help($user->name)),
            '/me' => $this->bot->sendMessage($link->chat_id, implode("\n", [
                '<b>'.e($user->name).'</b>',
                e($user->email),
                'Role: '.e($user->getRoleNames()->implode(', ') ?: 'none'),
                'Linked '.e($link->linked_at->diffForHumans()).'.',
            ])),
            '/today' => $this->bot->sendMessage($link->chat_id, $this->summary->for($user)),
            '/trip', '/trips' => $this->bot->sendMessage($link->chat_id, $this->trips($argument)),
            '/find' => $this->bot->sendMessage($link->chat_id, $this->find($argument)),
            '/upload' => $this->upload($link, $argument, $this->currentMessage),
            '/unlink' => $this->unlink($link),
            default => $this->bot->sendMessage($link->chat_id, "I don't know that one yet. Send /help for what I can do."),
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

    private function trips(string $argument): string
    {
        if ($argument !== '') {
            $trip = Trip::query()->where('code', strtoupper($argument))
                ->orWhere('name', 'like', '%'.$argument.'%')->orderByDesc('departs_on')->first();

            if ($trip === null) {
                return 'No trip matches "'.e($argument).'". Send /trip for the list.';
            }

            $lines = [
                '<b>'.e($trip->name).'</b> · '.e($trip->code),
                e($trip->departs_on->format('j M')).' – '.e($trip->returns_on->format('j M Y')).' · '.e($trip->statusLabel()),
                'Seats: '.$trip->seatsTaken().' of '.$trip->capacity_total,
            ];

            if ($route = $trip->routeSummary()) {
                $lines[] = 'Route: '.e($route);
            }

            foreach ($trip->hotels as $hotel) {
                $lines[] = e($hotel->cityLabel()).': '.e($hotel->name).' ('.e($hotel->check_in_on->format('j M')).' – '.e($hotel->check_out_on->format('j M')).')';
            }

            $open = $trip->checklist()->open()->count();

            if ($open > 0) {
                $lines[] = $open.' checklist item'.($open === 1 ? '' : 's').' still open.';
            }

            return implode("\n", $lines);
        }

        $trips = Trip::query()->upcoming()->limit(10)->get();

        if ($trips->isEmpty()) {
            return 'No upcoming trips. Create one in the portal under Trips.';
        }

        return implode("\n", array_merge(['<b>Upcoming trips</b>'], $trips->map(fn (Trip $t): string => e($t->code).' · '.e($t->name).' · '.e($t->departs_on->format('j M')).' · '.$t->seatsTaken().'/'.$t->capacity_total.' seats · '.e(strtolower($t->statusLabel())))->all(), ['', 'Send /trip CODE for one trip.']));
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

            return implode("\n", array_filter([
                '<b>'.e($p->name_en).'</b> · #'.$p->id.' · '.e($p->registration_status),
                e($p->idNumberMasked() ?? 'no ID number').' · '.e($p->customer?->name ?? ''),
                $p->phone ? e($p->phone) : null,
                $docs,
                $p->unexplainedMismatches() !== [] ? '⚠ ID card and passport disagree' : null,
            ]));
        })->implode("\n\n");
    }

    /**
     * A photo or file sent to the bot with the pilgrim's ID number in the
     * caption, and optionally the type: "A123456 passport". Stored exactly
     * as an upload in the portal would be, and read.
     *
     * @param  array<string, mixed>  $message
     */
    private function upload(TelegramLink $link, string $caption, array $message): void
    {
        if (! $link->user->can('document.create')) {
            $this->bot->sendMessage($link->chat_id, 'Your account cannot upload copies.');

            return;
        }

        if (! preg_match('/([Aa]\s?\d{6})/', $caption, $m)) {
            $this->bot->sendMessage($link->chat_id, "Add the pilgrim's ID number to the caption, like <b>A123456 passport</b>. Types: id, passport, photo, visa.");

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

        $info = $this->bot->getFile($fileId);
        $bytes = $this->bot->download((string) ($info['file_path'] ?? ''));

        $wallet = app(DocumentWallet::class);
        $version = $wallet->store($pilgrim, $bytes, $name, $mime, $type);
        $reply = Document::TYPES[$type].' saved for <b>'.e($pilgrim->name_en).'</b>.';

        if ($version->document->isReadable()) {
            $x = app(ReaderManager::class)->read($version);
            $reply .= ' '.count($x->fields ?? []).' fields read; accept them in the portal.';
        }

        $this->bot->sendMessage($link->chat_id, $reply);
    }

    private function help(string $name, bool $greeting = true): string
    {
        $lines = $greeting ? ['Hello '.e($name).'. I can:', ''] : ['I can:', ''];

        return implode("\n", array_merge($lines, [
            '/today — this morning\'s summary',
            '/trip — upcoming trips, or /trip UMR-2027-07 for one',
            '/find A123456 — a pilgrim by ID number or name',
            'Send a photo with "A123456 passport" as the caption to upload a copy',
            '/me — who this chat acts as',
            '/unlink — stop acting as you',
            '/help — this list',
            '',
            'More commands arrive with each phase: trips, pilgrims, payments.',
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
