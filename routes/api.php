<?php

use App\Http\Controllers\Api\V1\DealerController;
use App\Http\Controllers\Api\V1\EnquiryController;
use App\Http\Controllers\Api\V1\PhotoController;
use App\Http\Controllers\Api\V1\VehicleController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\SetApiLocale;
use Illuminate\Support\Facades\Route;

/*
 * Public REST API v1 (JSON:API). Authenticated by dealer API tokens (Settings → API & webhooks).
 */
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('photos/{tenant}/{listing}/{document}', PhotoController::class)->middleware(['signed', 'throttle:600,1'])->name('photo');

    Route::middleware([AuthenticateApiToken::class.':listings:read', 'throttle:api', SetApiLocale::class])->group(function () {
        Route::get('vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
        Route::get('vehicles/{vehicle}', [VehicleController::class, 'show'])->name('vehicles.show');
        Route::get('dealer', DealerController::class)->name('dealer');
    });

    Route::post('enquiries', [EnquiryController::class, 'store'])
        ->middleware([AuthenticateApiToken::class.':enquiries:write', 'throttle:api-enquiries'])
        ->name('enquiries.store');
});
