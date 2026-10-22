<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\TripGroup;
use App\Observers\AuditObserver;
use App\Services\Invoices\CustomerPapers;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The agreement and the travel letters, for signed-in staff who may see the booking. Every print is audited. */
class CustomerPapersController extends Controller
{
    public function __construct(private readonly CustomerPapers $papers) {}

    public function agreement(Booking $booking, Request $request): Response
    {
        abort_unless($request->user()?->can('view', $booking), 403);
        AuditObserver::record($booking, AuditLog::DOWNLOADED, null, ['document' => 'agreement']);

        return $this->papers->agreement($booking)->stream($this->papers->agreementFilename($booking));
    }

    public function letters(Booking $booking, Request $request): Response
    {
        abort_unless($request->user()?->can('view', $booking), 403);
        AuditObserver::record($booking, AuditLog::DOWNLOADED, null, ['document' => 'travel letters']);

        return $this->papers->letters($booking->pilgrims()->get())->stream($this->papers->lettersFilename($booking));
    }

    public function letter(BookingPilgrim $bookingPilgrim, Request $request): Response
    {
        abort_unless($request->user()?->can('view', $bookingPilgrim->booking), 403);
        AuditObserver::record($bookingPilgrim->booking, AuditLog::DOWNLOADED, null, ['document' => 'travel letter', 'traveller' => $bookingPilgrim->pilgrim->name_en]);

        return $this->papers->letters(new Collection([$bookingPilgrim]))->stream($this->papers->lettersFilename($bookingPilgrim->booking, $bookingPilgrim));
    }

    public function groupLetters(TripGroup $tripGroup, Request $request): Response
    {
        abort_unless($request->user()?->can('list.view'), 403);
        AuditObserver::record($tripGroup->trip, AuditLog::DOWNLOADED, null, ['document' => 'travel letters', 'group' => $tripGroup->name]);

        return $this->papers->letters($tripGroup->members()->get())->stream('travel-letters-'.strtolower($tripGroup->trip->code).'-'.str($tripGroup->name)->slug().'.pdf');
    }
}
