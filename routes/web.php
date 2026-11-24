<?php

use App\Http\Controllers\PageController;
use App\Http\Controllers\PublicPageController;
use Illuminate\Support\Facades\Route;

// The owner's pages (empty shells that load their data with the owner's key).
Route::get('/', [PageController::class, 'home']);
Route::get('/sites/{id}', [PageController::class, 'workspace'])->whereNumber('id');

// The published website, as visitors see it.
Route::get('/s/{slug}', [PublicPageController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->middleware('throttle:120,1');
