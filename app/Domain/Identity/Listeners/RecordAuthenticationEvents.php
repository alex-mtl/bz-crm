<?php

declare(strict_types=1);

namespace App\Domain\Identity\Listeners;

use App\Domain\Audit\EventJournal;
use App\Domain\Audit\JournalContext;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserKnownDevice;
use App\Domain\Identity\Notifications\SecurityAlert;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Sign-in history for the security service (ФО §6.1) and T3 suspicious sign-in detection:
 * a new device, or a series of failed attempts → journal event + e-mail to the account owner.
 * No automatic blocking by default (ADR-007).
 */
final readonly class RecordAuthenticationEvents
{
    public const int FAILED_SERIES_THRESHOLD = 5;

    public const int FAILED_SERIES_WINDOW_MINUTES = 15;

    public function __construct(private EventJournal $journal, private JournalContext $context) {}

    public function handleLogin(Login $event): void
    {
        $user = $event->user;
        if (! $user instanceof User) {
            return;
        }

        if (session()->isStarted()) {
            session()->put('session_epoch', $user->session_epoch);
        }
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->journal->record('auth.login.succeeded', $user, [], ['remember' => $event->remember]);

        $fingerprint = hash('sha256', ($this->context->ipAddress ?? '').'|'.($this->context->userAgent ?? ''));
        $known = UserKnownDevice::query()->where('user_id', $user->id)->where('fingerprint', $fingerprint)->first();
        if ($known !== null) {
            $known->update(['last_seen_at' => now()]);

            return;
        }

        $isFirstDevice = UserKnownDevice::query()->where('user_id', $user->id)->doesntExist();
        UserKnownDevice::query()->create([
            'user_id' => $user->id,
            'fingerprint' => $fingerprint,
            'ip_address' => $this->context->ipAddress,
            'user_agent' => $this->context->userAgent !== null ? Str::limit($this->context->userAgent, 500, '') : null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        if (! $isFirstDevice) {
            $this->journal->record('auth.login.new_device', $user);
            $user->notify(new SecurityAlert(SecurityAlert::NEW_DEVICE, $this->context->ipAddress, $this->context->userAgent));
        }
    }

    public function handleFailed(Failed $event): void
    {
        $email = Str::lower((string) ($event->credentials['email'] ?? ''));
        $user = $event->user instanceof User ? $event->user : null;

        $this->journal->record('auth.login.failed', $user, [], ['email' => $email]);

        if ($email === '') {
            return;
        }
        $key = 'auth-failures:'.hash('sha256', $email);
        Cache::add($key, 0, now()->addMinutes(self::FAILED_SERIES_WINDOW_MINUTES));
        $count = (int) Cache::increment($key);

        if ($count === self::FAILED_SERIES_THRESHOLD) {
            $this->journal->record('auth.login.failed_series', $user, [], ['email' => $email, 'attempts' => $count]);
            $user ??= User::query()->where('email', $email)->first();
            $user?->notify(new SecurityAlert(SecurityAlert::FAILED_SERIES, $this->context->ipAddress, $this->context->userAgent));
        }
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->journal->record('auth.logout', $event->user);
        }
    }
}
