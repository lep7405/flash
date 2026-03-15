<?php

use App\Http\Controllers\FlashController;
use Illuminate\Support\Facades\Route;

Route::get('/flashes', [FlashController::class, 'index']);
Route::post('/flashes', [FlashController::class, 'store']);
Route::put('/flashes/{flash}', [FlashController::class, 'update']);
