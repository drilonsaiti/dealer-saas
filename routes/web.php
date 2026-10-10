<?php

use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\SigningController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Customer signing by link (no login; the token identifies the signer).
Route::middleware('throttle:30,1')->prefix('sign/{token}')->name('signing.')->group(function () {
    Route::get('/', [SigningController::class, 'show'])->name('show');
    Route::post('/code', [SigningController::class, 'sendCode'])->middleware('throttle:5,10')->name('code');
    Route::post('/verify', [SigningController::class, 'verify'])->name('verify');
    Route::post('/', [SigningController::class, 'sign'])->name('sign');
});

// Personal calendar subscription (secret link, no login).
Route::get('/calendar/{token}', CalendarFeedController::class)->middleware('throttle:60,1')->where('token', '[A-Za-z0-9_]+(\\.ics)?')->name('calendar.feed');

// Customer portal (secret link per sale, no login).
Route::middleware('throttle:60,1')->prefix('portal/{token}')->name('portal.')->where(['token' => 'cp_[A-Za-z0-9]+'])->group(function () {
    Route::get('/', [PortalController::class, 'show'])->name('show');
    Route::get('/documents/{document}', [PortalController::class, 'download'])->name('download');
    Route::post('/upload', [PortalController::class, 'upload'])->middleware('throttle:20,10')->name('upload');
});
