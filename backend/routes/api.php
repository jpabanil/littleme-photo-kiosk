<?php

use App\Http\Controllers\GenerateController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::post('/generate', GenerateController::class)
    ->withoutMiddleware('throttle:api')
    ->middleware('throttle:generate');
