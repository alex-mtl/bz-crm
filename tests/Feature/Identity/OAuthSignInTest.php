<?php

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\AuthProvider;
use App\Domain\Identity\Models\RegistrationApplication;
use App\Domain\Identity\Models\SocialIdentity;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\AccountLinkHint;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Tests\Support\FakeOAuthGateway;

beforeEach(function () {
    AuthProvider::query()->create([
        'code' => 'google', 'driver' => 'google', 'display_name' => 'Google',
        'client_id' => 'test-client', 'client_secret' => 'test-secret', 'is_enabled' => true, 'sort_order' => 1,
    ]);
    $this->gateway = FakeOAuthGateway::install();
});

it('redirects to an enabled provider and 404s for an unknown or disabled one', function () {
    $this->get(route('oauth.redirect', 'google'))->assertRedirect('https://provider.example/authorize?client=google');
    $this->get(route('oauth.redirect', 'yahoo'))->assertNotFound();

    AuthProvider::query()->where('code', 'google')->update(['is_enabled' => false]);
    $this->get(route('oauth.redirect', 'google'))->assertNotFound();
});

it('turns an unknown external account into a new applicant with zero rights', function () {
    $this->gateway->returns('g-1', 'new.person@example.test');

    $this->get(route('oauth.callback', 'google'))->assertRedirect('/admin');

    $user = User::query()->where('email', 'new.person@example.test')->sole();
    expect($user->status)->toBe(UserStatus::PendingApproval)
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(RegistrationApplication::query()->where('user_id', $user->id)->value('channel'))->toBe('oauth:google')
        ->and(auth()->id())->toBe($user->id);
    $this->get('/admin')->assertRedirect(route('account.status'));
});

it('never merges into an existing account with the same e-mail — a new applicant plus a hint (Д-10)', function () {
    $existing = User::factory()->create(['email' => 'same@example.test']);
    $this->gateway->returns('g-2', 'same@example.test');

    $this->get(route('oauth.callback', 'google'));

    $newUser = User::query()->whereKeyNot($existing->id)->sole();
    expect($newUser->email)->toBeNull()
        ->and($newUser->routeNotificationForMail())->toBe('same@example.test')
        ->and(AccountLinkHint::query()->where('existing_person_id', $existing->person_id)->exists())->toBeTrue()
        ->and(SocialIdentity::query()->where('user_id', $existing->id)->exists())->toBeFalse();
});

it('signs in a known external account', function () {
    $user = User::factory()->create();
    SocialIdentity::query()->create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-3', 'linked_at' => now()]);
    $this->gateway->returns('g-3', 'whatever@example.test');

    $this->get(route('oauth.callback', 'google'))->assertRedirect('/admin');

    expect(auth()->id())->toBe($user->id)->and(journalCount('auth.login.succeeded'))->toBe(1);
});

it('refuses a deactivated account', function () {
    $user = User::factory()->deactivated()->create();
    SocialIdentity::query()->create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-4', 'linked_at' => now()]);
    $this->gateway->returns('g-4', null);

    $this->get(route('oauth.callback', 'google'))->assertRedirect(route('filament.admin.auth.login'));
    $this->assertGuest();
});

it('asks for the second factor when the account has two-factor authentication', function () {
    $mfa = AppAuthentication::make();
    $secret = $mfa->generateSecret();
    $user = User::factory()->create(['app_authentication_secret' => $secret]);
    $user->saveAppAuthenticationSecret($secret);
    SocialIdentity::query()->create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-5', 'linked_at' => now()]);
    $this->gateway->returns('g-5', null);

    $this->get(route('oauth.callback', 'google'))->assertRedirect(route('oauth.two-factor'));
    $this->assertGuest();

    $this->post(route('oauth.two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->post(route('oauth.two-factor.verify'), ['code' => $mfa->getCurrentCode($user, $secret)])->assertRedirect('/admin');
    expect(auth()->id())->toBe($user->id);
});

it('connects a provider to the signed-in user from the profile', function () {
    $user = User::factory()->create();
    $this->gateway->returns('g-6', 'other@example.test');

    $this->actingAs($user)->get(route('oauth.callback', 'google'))->assertRedirect(route('filament.admin.auth.profile'));

    expect(SocialIdentity::query()->where('user_id', $user->id)->where('provider_user_id', 'g-6')->exists())->toBeTrue()
        ->and(journalCount('identity.provider.linked'))->toBe(1)
        ->and(User::query()->count())->toBe(1);
});

it('refuses to connect an external account that belongs to someone else', function () {
    $owner = User::factory()->create();
    SocialIdentity::query()->create(['user_id' => $owner->id, 'provider' => 'google', 'provider_user_id' => 'g-7', 'linked_at' => now()]);
    $this->gateway->returns('g-7', null);

    $this->actingAs(User::factory()->create())
        ->get(route('oauth.callback', 'google'))
        ->assertSessionHasErrors('oauth');
});
