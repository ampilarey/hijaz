<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\ReportController;
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

// Lists, manifests, cards and tags: rendered on demand for staff who may see lists, and audited.
Route::middleware(['web', 'auth'])->prefix('lists')->name('lists.')->group(function (): void {
    Route::get('/trips/{trip}/manifest', [OperationsController::class, 'manifest'])->name('manifest');
    Route::get('/trips/{trip}/payments', [OperationsController::class, 'payments'])->name('payments');
    Route::get('/trips/{trip}/documents', [OperationsController::class, 'documents'])->name('documents');
    Route::get('/buses/{busGroup}', [OperationsController::class, 'busList'])->name('bus');
    Route::get('/hotels/{tripHotel}/rooms', [OperationsController::class, 'roomList'])->name('rooms');
    Route::get('/excursions/{tripExcursion}', [OperationsController::class, 'excursion'])->name('excursion');
    Route::get('/cards/{kind}', [OperationsController::class, 'cards'])->name('cards');
});

// Reports: one route, the report named in the query, PDF or CSV, audited.
Route::middleware(['web', 'auth'])->get('/reports', [ReportController::class, 'show'])->name('reports.show');
