<?php

use App\Http\Controllers\Api\FuelMobileController;
use Illuminate\Support\Facades\Route;

// Laravel ->withRouting(api:...) adds the /api prefix automatically.
Route::prefix('fuel/v1')->group(function (): void {
    Route::post('/auth/token', [FuelMobileController::class, 'login'])
        ->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
        Route::post('/auth/logout', [FuelMobileController::class, 'logout']);
        Route::get('/bootstrap', [FuelMobileController::class, 'bootstrap']);
        Route::get('/dispenses', [FuelMobileController::class, 'index']);
        Route::post('/dispenses/manual', [FuelMobileController::class, 'storeManual']);
        Route::post('/dispenses/automatic', [FuelMobileController::class, 'storeAutomatic']);
    });
});
