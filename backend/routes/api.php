<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SponsorController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/sponsor-register', [SponsorController::class, 'registerWithSponsor']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::prefix('sponsor')->group(function () {
        Route::get('/sponsees', [SponsorController::class, 'mySponsees']);
        Route::get('/sponsors', [SponsorController::class, 'mySponsors']);
        Route::patch('/{sponsorship}/status', [SponsorController::class, 'updateSponsorshipStatus']);
    });
});
