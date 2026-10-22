<?php

namespace App\Services\Invoices;

use App\Models\Agreement;
use App\Models\Booking;
use App\Models\BookingPilgrim;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Collection;

/**
 * What a customer is given besides the invoice: the agreement for the
 * booking, and a travel acceptance letter for each traveller once their
 * group's flights are set. Rendered on demand, never stored.
 */
final class CustomerPapers
{
    public function __construct(private readonly Documents $documents) {}

    public function agreement(Booking $booking): PdfDocument
    {
        $agreement = Agreement::for($booking);
        $agreement->refreshWording();

        return Pdf::loadView('pdf.agreement', [
            'booking' => $booking->load(['customer', 'trip', 'pilgrims.pilgrim']),
            'agreement' => $agreement->fresh(),
            'issuer' => $this->documents->issuer(),
        ]);
    }

    public function agreementFilename(Booking $booking): string
    {
        return 'agreement-'.strtolower($booking->reference).'.pdf';
    }

    /** A traveller gets a letter once their booking is confirmed and they have a flight out. */
    public static function letterReady(BookingPilgrim $bp): bool
    {
        return $bp->booking->status === Booking::CONFIRMED && $bp->flightOut() !== null;
    }

    /** @param  Collection<int, BookingPilgrim>  $travellers  one letter a page */
    public function letters(Collection $travellers): PdfDocument
    {
        $travellers->loadMissing(['pilgrim', 'booking.customer', 'booking.trip.hotels', 'booking.trip.flights', 'group.outboundFlight', 'group.returnFlight', 'busGroup', 'outboundFlight', 'returnFlight']);

        return Pdf::loadView('pdf.acceptance-letter', [
            'travellers' => $travellers->filter(fn (BookingPilgrim $bp): bool => self::letterReady($bp))->values(),
            'issuer' => $this->documents->issuer(),
        ]);
    }

    public function lettersFilename(Booking $booking, ?BookingPilgrim $one = null): string
    {
        return 'travel-letter-'.strtolower($booking->reference).($one ? '-'.str($one->pilgrim->name_en)->slug() : '').'.pdf';
    }
}
