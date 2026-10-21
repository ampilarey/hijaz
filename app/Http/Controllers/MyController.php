<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\Document;
use App\Models\Payment;
use App\Services\Documents\DocumentWallet;
use App\Services\Documents\ReaderManager;
use App\Services\Invoices\Documents;
use App\Services\Payments\SlipIntake;
use App\Services\SelfService\Gatekeeper;
use App\Support\AuditContext;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The customer's own page at /my: their booking, what is paid and owed,
 * which copies are on file for each traveller, with uploads for the ones
 * missing and for bank slips. Reached by a one-time link; no password, no
 * identifier in any URL after the door. Everything they upload goes the
 * same way the office's uploads do, read and waiting for staff.
 */
class MyController extends Controller
{
    public function __construct(private readonly Gatekeeper $gate) {}

    public function enter(string $token): RedirectResponse
    {
        $access = $this->gate->find($token);

        if ($access === null) {
            return redirect()->route('my.locked')->with('problem', 'That link is not one of ours. Check it was copied in full.');
        }

        if (! $access->isLive()) {
            return redirect()->route('my.locked')->with('problem', $access->whyNot());
        }

        $this->gate->admit($access);

        return redirect()->route('my.home');
    }

    public function locked(): View
    {
        return view('my.locked');
    }

    public function home(): View|RedirectResponse
    {
        $booking = $this->gate->current();

        if ($booking === null) {
            return redirect()->route('my.locked')->with('problem', 'Open the link the office sent you to see your booking.');
        }

        $booking->load(['trip.hotels', 'customer', 'pilgrims.pilgrim.documents.versions', 'schedule']);

        return view('my.home', [
            'booking' => $booking,
            'payments' => $booking->payments()->whereIn('status', [Payment::SUCCEEDED, Payment::AWAITING_REVIEW, Payment::PENDING])->orderByDesc('id')->get(),
            'accounts' => Account::query()->where('show_on_invoices', true)->where('is_active', true)->orderBy('sort_order')->get(),
            'docs' => $booking->pilgrims->mapWithKeys(fn (BookingPilgrim $bp): array => [$bp->id => collect(Document::REQUIRED_FOR_PILGRIMS)->mapWithKeys(fn (string $t): array => [$t => match (true) {
                ($d = $bp->pilgrim->document($t)) === null || $d->currentVersion() === null => 'missing',
                $d->isVerified() => 'verified',
                $d->status === Document::REJECTED => 'rejected',
                default => 'received',
            }])->all()]),
        ]);
    }

    /** A copy for one traveller, or a bank slip for the booking. */
    public function upload(Request $request, DocumentWallet $wallet, ReaderManager $readers, SlipIntake $intake): RedirectResponse
    {
        $booking = $this->gate->current();

        if ($booking === null) {
            return redirect()->route('my.locked');
        }

        $data = $request->validate([
            'kind' => ['required', 'in:copy,slip'],
            'file' => ['required', 'file', 'max:'.(int) config('documents.max_kilobytes'), 'mimetypes:'.implode(',', (array) config('documents.mime_types'))],
            'type' => ['required_if:kind,copy', 'nullable', 'in:'.implode(',', Document::REQUIRED_FOR_PILGRIMS)],
            'booking_pilgrim_id' => ['required_if:kind,copy', 'nullable', 'integer'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'in:'.implode(',', Currency::ALL)],
        ]);

        $file = $request->file('file');
        $bytes = (string) file_get_contents($file->getRealPath());
        AuditContext::use(AuditContext::SELF);

        try {
            if ($data['kind'] === 'copy') {
                $bp = $booking->pilgrims()->whereKey($data['booking_pilgrim_id'])->with('pilgrim')->first();

                if ($bp === null) {
                    return back()->with('problem', 'That traveller is not on your booking.');
                }

                if ($wallet->tooManyToday($bp->pilgrim, $data['type'])) {
                    return back()->with('problem', 'Too many uploads today. Try again tomorrow or call the office.');
                }

                $version = $wallet->store($bp->pilgrim, $bytes, $file->getClientOriginalName(), (string) $file->getMimeType(), $data['type']);

                if ($version->document->isReadable()) {
                    $readers->read($version);
                }

                return back()->with('done', Document::TYPES[$data['type']].' received for '.$bp->pilgrim->name_en.'. The office checks it.');
            }

            $typed = filled($data['amount'] ?? null) ? Money::ofMajor((string) $data['amount'], (string) ($data['currency'] ?? Currency::MVR)) : null;
            $result = $intake->record($booking, $bytes, $file->getClientOriginalName(), (string) $file->getMimeType(), $typed);

            if ($result['payment'] === null) {
                return back()->with('problem', ($result['problem'] ?? 'Could not read the slip.').' Type the amount under the slip and try again.');
            }

            return back()->with('done', 'Slip received: '.$result['payment']->money()->format().'. Finance confirms it and the balance updates.');
        } finally {
            AuditContext::reset();
        }
    }

    public function invoice(Documents $documents): Response|RedirectResponse
    {
        $booking = $this->gate->current();

        return $booking === null ? redirect()->route('my.locked') : $documents->invoice($booking)->stream($documents->invoiceFilename($booking));
    }

    public function receipt(Payment $payment, Documents $documents): Response|RedirectResponse
    {
        $booking = $this->gate->current();

        if ($booking === null || $payment->payable_type !== Booking::class || $payment->payable_id !== $booking->getKey() || $payment->status !== Payment::SUCCEEDED) {
            abort(404);
        }

        return $documents->receipt($payment)->stream($documents->receiptFilename($payment));
    }

    public function leave(): RedirectResponse
    {
        session()->forget([Gatekeeper::SESSION_KEY, Gatekeeper::SESSION_KEY.'.access']);
        session()->invalidate();

        return redirect()->route('my.locked')->with('done', 'You are signed out. Open the link again whenever you like.');
    }
}
