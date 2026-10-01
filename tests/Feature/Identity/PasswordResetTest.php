<?php

use App\Domain\Identity\Actions\ForcePasswordReset;
use App\Domain\Identity\Models\User;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPassword;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Notifications\ResetPassword as LaravelResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

it('resets a forgotten password through the e-mailed link and journals it', function () {
    Notification::fake();
    $user = User::factory()->create();

    Livewire::test(RequestPasswordReset::class)->fillForm(['email' => $user->email])->call('request');
    Notification::assertSentTo($user, FilamentResetPassword::class);

    $token = Password::broker()->createToken($user);
    Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
        ->fillForm(['password' => 'N3w-passphrase!', 'passwordConfirmation' => 'N3w-passphrase!'])
        ->call('resetPassword')
        ->assertHasNoFormErrors();

    expect(Hash::check('N3w-passphrase!', $user->fresh()->password))->toBeTrue()
        ->and(journalCount('identity.password.reset_completed'))->toBe(1);
});

it('sends a forced reset link that opens the panel\'s reset page and signs the user out everywhere', function () {
    Notification::fake();
    $user = User::factory()->create();
    $epoch = $user->fresh()->session_epoch;

    app(ForcePasswordReset::class)(userWithRoles('security'), $user);

    expect($user->fresh()->password)->toBeNull()
        ->and($user->fresh()->session_epoch)->toBe($epoch + 1)
        ->and(journalCount('identity.password.reset_forced'))->toBe(1);
    Notification::assertSentTo($user, LaravelResetPassword::class, function (LaravelResetPassword $notification) use ($user): bool {
        $url = $notification->toMail($user)->actionUrl;

        return str_contains($url, '/admin/password-reset/reset') && str_contains($url, 'signature=');
    });
});

it('does not let a user without users.password.reset force a reset', function () {
    expect(fn () => app(ForcePasswordReset::class)(userWithRoles('hr'), User::factory()->create()))
        ->toThrow(AuthorizationException::class);
});
