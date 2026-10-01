<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Contracts\OAuthGateway;
use App\Domain\Identity\Models\User;
use App\Http\Impersonation\ImpersonationSession;
use App\Infrastructure\ExternalAuth\SocialiteOAuthGateway;
use App\Support\Settings\SystemSettings;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires domain ports to infrastructure adapters: a domain module never names an adapter itself.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OAuthGateway::class, SocialiteOAuthGateway::class);
    }

    public function boot(): void
    {
        Event::listen(MigrationsEnded::class, fn () => SystemSettings::flush());
        // Signing out during an impersonation ends it (Д-19).
        Event::listen(Logout::class, function (): void {
            if (session()->isStarted()) {
                app(ImpersonationSession::class)->endOnSignOut(session()->driver());
            }
        });

        // A reset link sent by a domain action (forced reset) opens the panel's reset page.
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => Filament::getPanel('admin')->getResetPasswordUrl($token, $user));
    }
}
