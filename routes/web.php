<?php

use App\Http\Controllers\Mock\ProviderAController;
use App\Http\Controllers\Mock\ProviderBController;
use App\Http\Controllers\Mock\ProviderCController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('mock/providers')->group(function () {
    Route::get('a', ProviderAController::class);
    Route::get('b', ProviderBController::class);
    Route::get('c', ProviderCController::class);
});
