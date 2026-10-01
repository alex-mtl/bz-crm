<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use App\Domain\Audit\EventJournal;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use App\Domain\Identity\Listeners\RecordAuthenticationEvents;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class IdentityServiceProvider extends ServiceProvider
{
    public function boot(EventTypeRegistry $types): void
    {
        $types->register(
            new EventType('identity.registration.submitted', EventCategory::Access, EventSeverity::Notice),
            new EventType('identity.email.verified', EventCategory::Security),
            new EventType('identity.profile.updated', EventCategory::Data),
            new EventType('identity.password.changed', EventCategory::Security, EventSeverity::Notice),
            new EventType('identity.provider.linked', EventCategory::Security, EventSeverity::Notice),
            new EventType('identity.provider.unlinked', EventCategory::Security, EventSeverity::Notice),
            new EventType('identity.user.deactivated', EventCategory::Security, EventSeverity::Warning),
            new EventType('identity.user.reactivated', EventCategory::Security, EventSeverity::Notice),
            new EventType('identity.password.reset_completed', EventCategory::Security, EventSeverity::Notice),
            new EventType('identity.password.reset_forced', EventCategory::Security, EventSeverity::Warning),
            new EventType('identity.two_factor.enabled', EventCategory::Security, EventSeverity::Notice),
            new EventType('identity.two_factor.disabled', EventCategory::Security, EventSeverity::Warning),
            new EventType('identity.two_factor.reset', EventCategory::Security, EventSeverity::Warning),
            new EventType('identity.accounts.linked', EventCategory::Security, EventSeverity::Warning),
            new EventType('identity.auth_provider.saved', EventCategory::Admin, EventSeverity::Notice),
            new EventType('auth.login.succeeded', EventCategory::Security),
            new EventType('auth.login.failed', EventCategory::Security, EventSeverity::Notice),
            new EventType('auth.login.failed_series', EventCategory::Security, EventSeverity::Warning),
            new EventType('auth.login.new_device', EventCategory::Security, EventSeverity::Notice),
            new EventType('auth.logout', EventCategory::Security),
            new EventType('auth.demo_login', EventCategory::Security, EventSeverity::Notice),
            new EventType('auth.sessions.terminated', EventCategory::Security, EventSeverity::Warning),
            new EventType('identity.impersonation.started', EventCategory::Security, EventSeverity::Warning),
            new EventType('identity.impersonation.ended', EventCategory::Security, EventSeverity::Notice),
        );

        Event::listen(Login::class, [RecordAuthenticationEvents::class, 'handleLogin']);
        Event::listen(Failed::class, [RecordAuthenticationEvents::class, 'handleFailed']);
        Event::listen(Logout::class, [RecordAuthenticationEvents::class, 'handleLogout']);
        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            if ($event->user instanceof User) {
                app(EventJournal::class)->record('identity.password.reset_completed', $event->user);
            }
        });
    }
}
