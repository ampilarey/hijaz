<?php

use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Scans leave the private disk only through here: signed, signed-in, audited.
Route::middleware(['web', 'auth', 'signed'])->group(function (): void {
    Route::get('/documents/{version}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::get('/documents/{version}/preview', [DocumentController::class, 'preview'])->name('documents.preview');
});
