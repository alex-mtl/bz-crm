<?php

use App\Domain\Audit\JournalContext;
use App\Domain\Identity\Actions\TerminateUserSessions;
use App\Domain\Identity\Listeners\RecordAuthenticationEvents;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\SecurityAlert;
use Filament\Auth\Pages\Login;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function attemptLogin(string $email, string $password = 'password'): Testable
{
    return Livewire::test(Login::class)
        ->fillForm(['email' => $email, 'password' => $password])
        ->call('authenticate');
}

it('signs in an active user and records it', function () {
    $user = User::factory()->create();

    attemptLogin($user->email)->assertHasNoFormErrors();

    expect(auth()->id())->toBe($user->id)
        ->and(journalCount('auth.login.succeeded'))->toBe(1)
        ->and($user->fresh()->last_login_at)->not->toBeNull();
});

it('lets an applicant sign in — the panel then shows only the status page', function () {
    $user = User::factory()->pending()->create();

    attemptLogin($user->email)->assertHasNoFormErrors();

    expect(auth()->id())->toBe($user->id);
});

it('does not let a deactivated user sign in (ФО §6.1)', function () {
    $user = User::factory()->deactivated()->create();

    attemptLogin($user->email)->assertHasFormErrors(['email']);

    $this->assertGuest();
    expect(journalCount('auth.login.failed'))->toBe(1);
});

it('warns the owner about a sign-in from a new device, but not about the first one', function () {
    Notification::fake();
    $user = User::factory()->create();

    attemptLogin($user->email);
    auth()->logout();
    Notification::assertNothingSent();

    app(JournalContext::class)->userAgent = 'Another browser';
    attemptLogin($user->email);

    expect(journalCount('auth.login.new_device'))->toBe(1);
    Notification::assertSentTo($user, SecurityAlert::class, fn (SecurityAlert $n) => $n->kind === SecurityAlert::NEW_DEVICE);
});

it('reports a series of failed attempts once, without blocking the account', function () {
    Notification::fake();
    $user = User::factory()->create();

    foreach (range(1, RecordAuthenticationEvents::FAILED_SERIES_THRESHOLD) as $attempt) {
        attemptLogin($user->email, 'wrong-'.$attempt);
    }

    expect(journalCount('auth.login.failed'))->toBe(RecordAuthenticationEvents::FAILED_SERIES_THRESHOLD)
        ->and(journalCount('auth.login.failed_series'))->toBe(1)
        ->and($user->fresh()->isActive())->toBeTrue();
    Notification::assertSentToTimes($user, SecurityAlert::class, 1);
});

it('signs out every session of a user whose sessions were terminated', function () {
    $user = User::factory()->create();

    $oldEpoch = $user->fresh()->session_epoch;
    $this->actingAs($user)->withSession(['session_epoch' => $oldEpoch])->get('/admin')->assertOk();

    app(TerminateUserSessions::class)->terminate($user, 'test');

    // A session opened before the termination still carries the old epoch.
    $this->actingAs($user->fresh())->withSession(['session_epoch' => $oldEpoch])->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    $this->assertGuest();
    expect(journalCount('auth.sessions.terminated'))->toBe(1);
});
