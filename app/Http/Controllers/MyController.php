<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Agreement;
use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\Document;
use App\Models\Payment;
use App\Services\Documents\DocumentWallet;
use App\Services\Documents\ReaderManager;
use App\Services\Invoices\CustomerPapers;
use App\Services\Invoices\Documents;
use App\Services\Payments\SlipIntake;
use App\Services\SelfService\Agreements;
use App\Services\SelfService\Gatekeeper;
use App\Support\AuditContext;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The customer portal at /my: their booking, what is paid and owed, which
 * copies are on file, the agreement to sign and the travel letters to
 * download. The office's link lets a customer in; from inside they set a
 * password and sign in after that with their mobile number. Everything
 * they upload goes the same way the office's uploads do, read and waiting
 * for staff.
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

    public function locked(): View|RedirectResponse
    {
        return $this->gate->current() !== null ? redirect()->route('my.home') : view('my.locked');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30'], 'password' => ['required', 'string', 'max:200']]);
        $customer = $this->gate->attempt($data['phone'], $data['password']);

        if ($customer === null) {
            return back()->withInput($request->only('phone'))->with('problem', 'That mobile number and password do not match. Use the link the office sent you to set a password first.');
        }

        $this->gate->signIn($customer);

        return redirect()->route('my.home');
    }

    public function password(Request $request): RedirectResponse
    {
        $customer = $this->gate->customer();

        if ($customer === null) {
            return redirect()->route('my.locked');
        }

        if (blank($customer->contactPhone())) {
            return back()->with('problem', 'The office has no mobile number for you, so a password cannot be set. Call the office.');
        }

        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'max:200', 'confirmed']]);
        AuditContext::use(AuditContext::SELF);

        try {
            $this->gate->setPassword($customer, $data['password']);
        } finally {
            AuditContext::reset();
        }

        return back()->with('done', 'Password set. Next time, sign in with '.$customer->contactPhone().' and this password.');
    }

    public function choose(Request $request): RedirectResponse
    {
        $booking = Booking::query()->find((int) $request->input('booking_id'));

        return $booking !== null && $this->gate->choose($booking) ? redirect()->route('my.home') : abort(404);
    }

    public function agreement(): View|RedirectResponse
    {
        $booking = $this->gate->current();

        if ($booking === null) {
            return redirect()->route('my.locked');
        }

        $agreement = Agreement::for($booking);
        $agreement->refreshWording();

        return view('my.agreement', ['booking' => $booking->load(['customer', 'trip', 'pilgrims.pilgrim']), 'agreement' => $agreement->fresh()]);
    }

    public function sign(Request $request, Agreements $agreements): RedirectResponse
    {
        $booking = $this->gate->current();

        if ($booking === null) {
            return redirect()->route('my.locked');
        }

        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'signature' => ['required', 'string'], 'agree' => ['accepted']]);
        AuditContext::use(AuditContext::SELF);

        try {
            $agreements->signOnScreen(Agreement::for($booking), $data['name'], $data['signature'], $request->ip(), $request->userAgent());
        } catch (\InvalidArgumentException $e) {
            return back()->withInput($request->only('name'))->with('problem', $e->getMessage());
        } finally {
            AuditContext::reset();
        }

        return redirect()->route('my.home')->with('done', 'Thank you. The agreement is signed; you can download a copy below.');
    }

    public function agreementPdf(CustomerPapers $papers): Response|RedirectResponse
    {
        $booking = $this->gate->current();

        return $booking === null ? redirect()->route('my.locked') : $papers->agreement($booking)->stream($papers->agreementFilename($booking));
    }

    public function letters(CustomerPapers $papers, ?BookingPilgrim $bookingPilgrim = null): Response|RedirectResponse
    {
        $booking = $this->gate->current();

        if ($booking === null) {
            return redirect()->route('my.locked');
        }

        if ($bookingPilgrim !== null && $bookingPilgrim->booking_id !== $booking->getKey()) {
            abort(404);
        }

        $travellers = $bookingPilgrim ? collect([$bookingPilgrim]) : $booking->pilgrims()->get();

        return $papers->letters(new Collection($travellers->all()))->stream($papers->lettersFilename($booking, $bookingPilgrim));
    }

    public function home(): View|RedirectResponse
    {
        $booking = $this->gate->current();

        if ($booking === null) {
            return redirect()->route('my.locked')->with('problem', 'Open the link the office sent you to see your booking.');
        }

        $booking->load(['trip.hotels', 'trip.flights', 'customer', 'pilgrims.pilgrim.documents.versions', 'pilgrims.group.outboundFlight', 'pilgrims.group.returnFlight', 'pilgrims.outboundFlight', 'pilgrims.returnFlight', 'pilgrims.busGroup', 'schedule']);
        $customer = $this->gate->customer();

        return view('my.home', [
            'booking' => $booking,
            'customer' => $customer,
            'signedIn' => $this->gate->signedInCustomer() !== null,
            'bookings' => $this->gate->signedInCustomer() ? $this->gate->bookingsOf($customer) : collect(),
            'agreement' => Agreement::for($booking),
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
            'kind' => ['required', 'in:copy,slip,agreement'],
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
            if ($data['kind'] === 'agreement') {
                $agreement = Agreement::for($booking);

                if ($agreement->isSigned()) {
                    return back()->with('problem', 'The agreement is already signed.');
                }

                if ($wallet->tooManyToday($booking, Document::AGREEMENT)) {
                    return back()->with('problem', 'Too many uploads today. Try again tomorrow or call the office.');
                }

                app(Agreements::class)->recordScan($agreement, $bytes, $file->getClientOriginalName(), (string) $file->getMimeType());

                return back()->with('done', 'Signed agreement received. Thank you.');
            }

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
        $this->gate->signOut();

        return redirect()->route('my.locked')->with('done', 'You are signed out.');
    }
}
