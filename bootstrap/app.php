<?php

use App\Http\Middleware\ApplyImpersonation;
use App\Http\Middleware\AssignJournalContext;
use App\Http\Middleware\EnsureSessionEpoch;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // API v1 (ADR-010): the session of the panel, the same middleware as the screens, plus a throttle.
        then: fn () => Route::middleware(['web', 'auth', 'throttle:120,1'])->prefix('api/v1')->name('api.v1.')
            ->group(__DIR__.'/../routes/api.php'),
    )
    // WebSocket channels (ADR-012): authorized through the session of the panel.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'auth']])
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, so Filament and Livewire requests also get a journal context.
        $middleware->append(AssignJournalContext::class);
        $middleware->web(append: [SetLocale::class, EnsureSessionEpoch::class, ApplyImpersonation::class]);
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
