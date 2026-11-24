<?php

use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\PublishController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\SectionController;
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

    // Read the sources and write the site (a background job), and answer the owner's questions.
    Route::post('/generate', [SiteController::class, 'generate'])->middleware('throttle:6,1');
    Route::post('/questions/{question}/answer', [QuestionController::class, 'answer'])->scopeBindings();

    // Edit or regenerate one section of the site, and put the site online or take it offline.
    Route::patch('/sections/{key}', [SectionController::class, 'update'])->where('key', 'hero|about|services|contact');
    Route::post('/sections/{key}/regenerate', [SectionController::class, 'regenerate'])
        ->where('key', 'hero|about|services|contact')->middleware('throttle:10,1');
    Route::post('/publish', [PublishController::class, 'publish']);
    Route::delete('/publish', [PublishController::class, 'unpublish']);
});

// What visitors see. No token: it only works for sites that have been published.
Route::prefix('public/{slug}')->where(['slug' => '[a-z0-9-]+'])->middleware('throttle:120,1')->group(function () {
    Route::get('/', [PublicSiteController::class, 'show']);
    Route::get('/photos/{photo}', [PublicSiteController::class, 'photo'])->whereNumber('photo');
});
