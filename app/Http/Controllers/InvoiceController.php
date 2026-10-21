<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\ExcursionBooking;
use App\Models\Payment;
use App\Observers\AuditObserver;
use App\Services\Invoices\Documents;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Invoices and receipts, rendered when asked for and never stored. Staff
 * only, through the policy; the audit trail notes who printed what.
 */
class InvoiceController extends Controller
{
    public function __construct(private readonly Documents $documents) {}

    public function invoice(Booking $booking, Request $request): Response
    {
        abort_unless($request->user()?->can('view', $booking), 403);
        AuditObserver::record($booking, AuditLog::DOWNLOADED, null, ['document' => 'invoice']);

        return $this->documents->invoice($booking)->stream($this->documents->invoiceFilename($booking));
    }

    public function excursionInvoice(ExcursionBooking $excursionBooking, Request $request): Response
    {
        abort_unless($request->user()?->can('view', $excursionBooking), 403);
        AuditObserver::record($excursionBooking, AuditLog::DOWNLOADED, null, ['document' => 'invoice']);

        return $this->documents->excursionInvoice($excursionBooking)->stream($this->documents->excursionInvoiceFilename($excursionBooking));
    }

    public function receipt(Payment $payment, Request $request): Response
    {
        abort_unless($request->user()?->can('download', $payment), 403);
        abort_unless($payment->status === Payment::SUCCEEDED, 404);
        AuditObserver::record($payment, AuditLog::DOWNLOADED, null, ['document' => 'receipt']);

        return $this->documents->receipt($payment)->stream($this->documents->receiptFilename($payment));
    }
}
