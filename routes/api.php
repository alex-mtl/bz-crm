<?php

use App\Http\Controllers\Api\V1\FeedController;
use App\Http\Controllers\Api\V1\FieldController;
use Illuminate\Support\Facades\Route;

/*
 * API v1 (ADR-010). Registered in bootstrap/app.php under /api/v1 with the session of the panel, "auth" and a
 * throttle. Every endpoint goes through the same domain services as the screens — the API never has rules of its own.
 */

Route::get('/feed', [FeedController::class, 'index'])->name('feed');
Route::get('/posts/{post}', [FeedController::class, 'show'])->whereNumber('post')->name('posts.show');

// The agitator's phone (ТЗ §33–34, ADR-013): the houses to keep offline and the queue of offline operations.
Route::prefix('field')->name('field.')->group(function (): void {
    Route::get('/session', [FieldController::class, 'session'])->name('session');
    Route::get('/snapshot', [FieldController::class, 'snapshot'])->name('snapshot');
    Route::post('/sync', [FieldController::class, 'sync'])->name('sync');
    // Voluntary location sharing (Д-22): the person's own device only.
    Route::get('/location', [FieldController::class, 'location'])->name('location');
    Route::post('/location/start', [FieldController::class, 'startSharing'])->name('location.start');
    Route::post('/location/stop', [FieldController::class, 'stopSharing'])->name('location.stop');
    Route::post('/location', [FieldController::class, 'point'])->name('location.point');
});
