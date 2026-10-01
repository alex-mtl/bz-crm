<?php

use App\Domain\Access\Models\UserRole;
use App\Domain\Identity\Enums\ApplicationStatus;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\RegistrationApplication;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\AccountLinkHint;
use App\Domain\People\Models\Person;
use App\Filament\Auth\Register;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

function registerThroughForm(string $email = 'maria@example.test'): void
{
    Livewire::test(Register::class)
        ->fillForm([
            'first_name' => 'Maria',
            'last_name' => 'Rusu',
            'email' => $email,
            'password' => 'Str0ng-passphrase!',
            'passwordConfirmation' => 'Str0ng-passphrase!',
        ])
        ->call('register')
        ->assertHasNoFormErrors();
}

it('creates an applicant with zero rights and a pending application (ФО §6.1)', function () {
    Notification::fake();

    registerThroughForm();

    $user = User::query()->where('email', 'maria@example.test')->sole();
    expect($user->status)->toBe(UserStatus::PendingApproval)
        ->and($user->person->person_type)->toBe('applicant')
        ->and($user->person->fullName())->toContain('Maria')
        ->and(UserRole::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(RegistrationApplication::query()->where('user_id', $user->id)->sole()->status)->toBe(ApplicationStatus::Pending)
        ->and(journalCount('identity.registration.submitted'))->toBe(1)
        ->and(auth()->id())->toBe($user->id);

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('does not link a registration to an existing card, only leaves a hint (Д-10)', function () {
    Notification::fake();
    Person::factory()->employee()->create(['first_name' => 'Maria', 'last_name' => 'Rusu', 'email' => 'maria@example.test']);

    registerThroughForm();

    $user = User::query()->where('email', 'maria@example.test')->sole();
    $hint = AccountLinkHint::query()->sole();
    expect($hint->new_person_id)->toBe($user->person_id)
        ->and(Person::query()->count())->toBe(2)
        ->and(journalCount('people.link_hint.created'))->toBe(1);
});

it('rejects a second registration with a login e-mail already in use', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    Livewire::test(Register::class)
        ->fillForm([
            'first_name' => 'X', 'email' => 'taken@example.test',
            'password' => 'Str0ng-passphrase!', 'passwordConfirmation' => 'Str0ng-passphrase!',
        ])
        ->call('register')
        ->assertHasFormErrors(['email']);
});

it('shows an applicant only the status page with the party site link', function () {
    $user = User::factory()->pending()->create();
    RegistrationApplication::query()->create(['user_id' => $user->id, 'channel' => 'email', 'status' => ApplicationStatus::Pending, 'submitted_at' => now()]);

    $this->actingAs($user)->get('/admin/profile')->assertRedirect(route('account.status'));
    $this->actingAs($user)->get(route('account.status'))
        ->assertOk()
        ->assertSee('data-test="application-pending"', escape: false)
        ->assertSee(config('app.party_site_url'));
});

it('shows a rejected applicant the reason', function () {
    $user = User::factory()->rejected()->create();
    RegistrationApplication::query()->create([
        'user_id' => $user->id, 'channel' => 'email', 'status' => ApplicationStatus::Rejected,
        'submitted_at' => now(), 'rejection_reason' => 'Date incomplete',
    ]);

    $this->actingAs($user)->get(route('account.status'))
        ->assertSee('data-test="application-rejected"', escape: false)
        ->assertSee('Date incomplete');
});

it('sends an active user with everything in order from the status page to the panel', function () {
    $this->actingAs(User::factory()->create())->get(route('account.status'))->assertRedirect('/admin');
});

it('confirms the e-mail through the signed link and records it', function () {
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

    $this->actingAs($user)->get($url)->assertRedirect(route('account.status'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and(journalCount('identity.email.verified'))->toBe(1);
});

it('refuses a verification link that belongs to another user', function () {
    $user = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $other->id, 'hash' => sha1($other->email)]);

    $this->actingAs($user)->get($url)->assertForbidden();
    expect($other->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('signs the applicant out from the status page', function () {
    $this->actingAs(User::factory()->pending()->create())
        ->post(route('account.logout'))
        ->assertRedirect(route('filament.admin.auth.login'));

    $this->assertGuest();
});

it('lets a guest switch the interface language', function () {
    $this->get(route('locale.switch', 'ru'))->assertRedirect();
    $this->get('/admin/login')->assertSee('lang="ru"', escape: false);

    $this->get(route('locale.switch', 'xx'))->assertNotFound();
});
