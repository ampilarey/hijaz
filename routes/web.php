<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LeaderController;
use App\Http\Controllers\MyController;
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

// The ground pages' service worker, served with the scope header; the web server serves the file itself when it can.
Route::get('/ground-sw.js', fn () => response()->file(public_path('ground-sw.js'), ['Content-Type' => 'application/javascript', 'Service-Worker-Allowed' => '/ground/', 'Cache-Control' => 'no-cache']))->name('ground.sw');

// The group leader's phone pages, small enough to work from a local copy; writes go through the outbox.
Route::middleware(['web', 'auth'])->prefix('ground')->name('ground.')->group(function (): void {
    Route::get('/', [LeaderController::class, 'index'])->name('index');
    Route::post('/sync', [LeaderController::class, 'sync'])->name('sync');
    Route::get('/trips/{trip}', [LeaderController::class, 'trip'])->name('trip');
    Route::get('/trips/{trip}/snapshot', [LeaderController::class, 'snapshot'])->name('snapshot');
    Route::post('/trips/{trip}/counts', [LeaderController::class, 'startCount'])->name('counts.start');
    Route::get('/trips/{trip}/counts/{rollCall}', [LeaderController::class, 'count'])->name('count');
    Route::post('/trips/{trip}/incidents', [LeaderController::class, 'raise'])->name('incidents.raise');
});

// The customer's own booking page, by one-time link. No account, no id in any URL after the door.
Route::middleware(['web', 'throttle:60,1'])->prefix('my')->name('my.')->group(function (): void {
    Route::get('/', [MyController::class, 'home'])->name('home');
    Route::get('/locked', [MyController::class, 'locked'])->name('locked');
    Route::get('/enter/{token}', [MyController::class, 'enter'])->name('enter');
    Route::post('/upload', [MyController::class, 'upload'])->middleware('throttle:20,1')->name('upload');
    Route::get('/invoice', [MyController::class, 'invoice'])->name('invoice');
    Route::get('/receipts/{payment}', [MyController::class, 'receipt'])->name('receipt');
    Route::post('/leave', [MyController::class, 'leave'])->name('leave');
});
