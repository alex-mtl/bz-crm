<?php

use App\Http\Controllers\Api\V1\FeedController;
use Illuminate\Support\Facades\Route;

/*
 * API v1 (ADR-010). Registered in bootstrap/app.php under /api/v1 with the session of the panel, "auth" and a
 * throttle. Every endpoint goes through the same domain services as the screens — the API never has rules of its own.
 */

Route::get('/feed', [FeedController::class, 'index'])->name('feed');
Route::get('/posts/{post}', [FeedController::class, 'show'])->whereNumber('post')->name('posts.show');
