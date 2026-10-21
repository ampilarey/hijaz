<?php

namespace App\Services\Invoices;

use App\Models\Account;
use App\Models\Booking;
use App\Models\ExcursionBooking;
use App\Models\Payment;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/** Invoices and receipts, rendered on demand and never stored. */
final class Documents
{
    public function invoice(Booking $booking): PdfDocument
    {
        return Pdf::loadView('pdf.invoice', [
            'booking' => $booking->load(['customer', 'trip', 'lines', 'pilgrims.pilgrim', 'schedule']),
            'payments' => $booking->payments()->succeeded()->with('allocations')->get(),
            'issuer' => $this->issuer(),
            'bankAccounts' => Account::query()->where('show_on_invoices', true)->where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function excursionInvoice(ExcursionBooking $xb): PdfDocument
    {
        return Pdf::loadView('pdf.excursion-invoice', [
            'xb' => $xb->load(['customer', 'excursion', 'participants.bookingPilgrim.pilgrim', 'booking.trip']),
            'payments' => $xb->payments()->succeeded()->get(),
            'issuer' => $this->issuer(),
        ]);
    }

    public function receipt(Payment $payment): PdfDocument
    {
        return Pdf::loadView('pdf.receipt', [
            'payment' => $payment->load(['payable', 'allocations', 'account']),
            'issuer' => $this->issuer(),
        ]);
    }

    public function invoiceFilename(Booking $booking): string
    {
        return 'invoice-'.strtolower($booking->reference).'.pdf';
    }

    public function excursionInvoiceFilename(ExcursionBooking $xb): string
    {
        return 'invoice-'.strtolower($xb->reference).'.pdf';
    }

    public function receiptFilename(Payment $payment): string
    {
        return ($payment->isRefund() ? 'refund-' : 'receipt-').strtolower($payment->reference).'.pdf';
    }

    /** @return array<string, string|null> */
    public function issuer(): array
    {
        return [
            'name' => (string) Settings::get(Settings::COMPANY_NAME),
            'registration' => (string) Settings::get(Settings::COMPANY_REGISTRATION),
            'address' => (string) Settings::get(Settings::COMPANY_ADDRESS),
            'email' => (string) Settings::get(Settings::COMPANY_EMAIL),
            'phone' => (string) Settings::get(Settings::COMPANY_PHONES),
        ];
    }
}
