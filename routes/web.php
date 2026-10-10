<?php

use App\Http\Controllers\CalendarFeedController;
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
