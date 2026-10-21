<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Scans leave the private disk only through here: signed, signed-in, audited.
Route::middleware(['web', 'auth', 'signed'])->group(function (): void {
    Route::get('/documents/{version}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::get('/documents/{version}/preview', [DocumentController::class, 'preview'])->name('documents.preview');
});

// Invoices and receipts are rendered on demand for signed-in staff; nothing is stored.
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/bookings/{booking}/invoice', [InvoiceController::class, 'invoice'])->name('bookings.invoice');
    Route::get('/excursion-bookings/{excursionBooking}/invoice', [InvoiceController::class, 'excursionInvoice'])->name('excursion-bookings.invoice');
    Route::get('/payments/{payment}/receipt', [InvoiceController::class, 'receipt'])->name('payments.receipt');
});
