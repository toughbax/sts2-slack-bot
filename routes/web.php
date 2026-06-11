<?php

use App\Http\Controllers\Slack\CommandController;
use App\Http\Controllers\Slack\OptionsController;
use App\Http\Middleware\VerifySlackSignature;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(VerifySlackSignature::class)->prefix('slack')->group(function () {
    Route::post('command', CommandController::class);
    Route::post('options', OptionsController::class);
});
