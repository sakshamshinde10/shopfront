<?php

use App\Http\Controllers\SiteController;
use App\Http\Controllers\SourceController;
use Illuminate\Support\Facades\Route;

// Creating a site returns a secret edit token. Every other route here needs it
// as "Authorization: Bearer <token>", so there are no accounts to manage.
Route::post('/sites', [SiteController::class, 'store'])->middleware('throttle:10,1');

Route::middleware('site.owner')->prefix('sites/{site}')->group(function () {
    Route::get('/', [SiteController::class, 'show']);

    Route::post('/sources', [SourceController::class, 'store'])->middleware('throttle:30,1');
    Route::post('/files', [SourceController::class, 'upload'])->middleware('throttle:10,1');
    Route::delete('/sources/{source}', [SourceController::class, 'destroy'])->scopeBindings();
});
