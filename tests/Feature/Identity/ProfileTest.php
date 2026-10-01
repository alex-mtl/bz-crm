<?php

use App\Domain\Identity\Models\SocialIdentity;
use App\Filament\Auth\EditProfile;
use Livewire\Livewire;

it('lets a user change their name and interface language, journaled', function () {
    $user = userWithRoles('employee');
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->fillForm(['first_name' => 'Ionela', 'last_name' => 'Nouă', 'locale' => 'ru'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->person->fullName())->toContain('Ionela')
        ->and($user->fresh()->locale)->toBe('ru')
        ->and(journalCount('identity.profile.updated'))->toBe(1);

    $this->get('/admin')->assertSee('lang="ru"', escape: false);
});

it('does not disconnect the only sign-in method of an account without a password', function () {
    $user = userWithRoles('employee');
    $user->forceFill(['password' => null])->save();
    $identity = SocialIdentity::query()->create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-1', 'linked_at' => now()]);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)->call('disconnect', $identity->id)->assertNotified();

    expect(SocialIdentity::query()->whereKey($identity->id)->exists())->toBeTrue();
});

it('disconnects a provider when a password remains', function () {
    $user = userWithRoles('employee');
    $identity = SocialIdentity::query()->create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-2', 'linked_at' => now()]);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)->call('disconnect', $identity->id);

    expect(SocialIdentity::query()->whereKey($identity->id)->exists())->toBeFalse()
        ->and(journalCount('identity.provider.unlinked'))->toBe(1);
});
