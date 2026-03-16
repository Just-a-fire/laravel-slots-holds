<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\HoldController;
// Route Model Binding для удобства использования, т.к. разница несущественная
Route::get('/slots/availability', AvailabilityController::class);
Route::post('/slots/{id}/hold', [HoldController::class, 'hold']);
Route::post('/holds/{id}/confirm', [HoldController::class, 'confirm']);
Route::delete('/holds/{id}', [HoldController::class, 'cancel']);
