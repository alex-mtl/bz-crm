<?php

use App\Domain\Access\Admission\DecideApplication;
use App\Domain\Access\Admission\InviteUser;
use App\Domain\Access\Admission\RevokeInvitation;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Exceptions\PermissionEscalation;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\UserRole;
use App\Domain\Identity\Actions\DismissLinkHint;
use App\Domain\Identity\Actions\LinkAccounts;
use App\Domain\Identity\Actions\SetUserActive;
use App\Domain\Identity\Enums\ApplicationStatus;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\RegistrationApplication;
use App\Domain\Identity\Models\SocialIdentity;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\ApplicationDecided;
use App\Domain\Identity\Notifications\InvitationSent;
use App\Domain\People\Enums\LinkHintStatus;
use App\Domain\People\Models\AccountLinkHint;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

function applicantWithApplication(): RegistrationApplication
{
    $user = User::factory()->pending()->create();

    return RegistrationApplication::query()->create([
        'user_id' => $user->id, 'channel' => 'email', 'status' => ApplicationStatus::Pending, 'submitted_at' => now(),
    ]);
}

function roleCodesOf(User $user): array
{
    return UserRole::query()->where('user_id', $user->id)->with('role')->get()->pluck('role.code')->sort()->values()->all();
}

// --- Invitations ---

it('invites by e-mail and the invited person gets an active account with the invited roles', function () {
    Notification::fake();
    $hr = userWithRoles('hr');

    ['token' => $token] = app(InviteUser::class)($hr, 'Nou@Example.test', ['employee']);

    Notification::assertSentOnDemand(InvitationSent::class);
    expect(journalCount('admission.invitation.sent'))->toBe(1);

    $this->get(route('invitation.accept', $token))->assertOk()->assertSee('nou@example.test');
    $this->post(route('invitation.store', $token), [
        'first_name' => 'Nou', 'last_name' => 'Angajat',
        'password' => 'Str0ng-passphrase!', 'password_confirmation' => 'Str0ng-passphrase!',
    ])->assertRedirect('/admin');

    $user = User::query()->where('email', 'nou@example.test')->sole();
    expect($user->status)->toBe(UserStatus::Active)
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(roleCodesOf($user))->toBe(['employee'])
        ->and(auth()->id())->toBe($user->id)
        ->and(journalCount('admission.invitation.accepted'))->toBe(1);

    // One-time link.
    $this->post('/account/logout');
    $this->get(route('invitation.accept', $token))->assertSee('data-test="invitation-unusable"', escape: false);
});

it('does not let an inviter put a role wider than their own into an invitation (Д-17)', function () {
    $unitHead = userWithRoles('unit_head');

    expect(fn () => app(InviteUser::class)($unitHead, 'x@example.test', ['super_admin']))->toThrow(PermissionEscalation::class);
    expect(journalCount('access.escalation.denied'))->toBe(1);
});

it('does not let a user without users.invite invite anyone', function () {
    expect(fn () => app(InviteUser::class)(userWithRoles('employee'), 'x@example.test', []))->toThrow(AuthorizationException::class);
});

it('re-checks the inviter\'s rights on acceptance: roles the inviter has lost are not granted', function () {
    Notification::fake();
    $hr = userWithRoles('hr', 'catalog_admin');
    ['token' => $token] = app(InviteUser::class)($hr, 'late@example.test', ['employee', 'catalog_admin']);

    UserRole::query()->where('user_id', $hr->id)->where('role_id', Role::query()->where('code', 'catalog_admin')->value('id'))->delete();
    app(AuthorizationService::class)->forget($hr);

    $this->post(route('invitation.store', $token), [
        'first_name' => 'Late', 'password' => 'Str0ng-passphrase!', 'password_confirmation' => 'Str0ng-passphrase!',
    ]);

    expect(roleCodesOf(User::query()->where('email', 'late@example.test')->sole()))->toBe(['employee']);
});

it('refuses a revoked or expired invitation', function () {
    Notification::fake();
    $hr = userWithRoles('hr');
    ['invitation' => $revoked, 'token' => $revokedToken] = app(InviteUser::class)($hr, 'r@example.test', []);
    app(RevokeInvitation::class)($hr, $revoked);
    ['invitation' => $expired, 'token' => $expiredToken] = app(InviteUser::class)($hr, 'e@example.test', []);
    $expired->update(['expires_at' => now()->subMinute()]);

    foreach ([$revokedToken, $expiredToken, 'not-a-token'] as $token) {
        $this->get(route('invitation.accept', $token))->assertSee('data-test="invitation-unusable"', escape: false);
    }
    expect(journalCount('admission.invitation.revoked'))->toBe(1);
});

// --- Applications ---

it('approves an application with roles within the reviewer\'s own rights and notifies the applicant', function () {
    Notification::fake();
    $application = applicantWithApplication();

    app(DecideApplication::class)->approve(userWithRoles('hr'), $application, ['volunteer'], 'volunteer');

    $user = $application->user->fresh();
    expect($user->status)->toBe(UserStatus::Active)
        ->and($user->person->person_type)->toBe('volunteer')
        ->and(roleCodesOf($user))->toBe(['volunteer'])
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Approved)
        ->and(journalCount('admission.application.approved'))->toBe(1);
    Notification::assertSentTo($user, ApplicationDecided::class, fn (ApplicationDecided $n) => $n->approved);
});

it('rejects only with a reason, which the applicant then sees', function () {
    Notification::fake();
    $application = applicantWithApplication();
    $hr = userWithRoles('hr');

    expect(fn () => app(DecideApplication::class)->reject($hr, $application, '  '))->toThrow(IdentityRuleViolation::class);

    app(DecideApplication::class)->reject($hr, $application, 'Nu locuiți în Moldova');

    expect($application->user->fresh()->status)->toBe(UserStatus::Rejected)
        ->and($application->fresh()->rejection_reason)->toBe('Nu locuiți în Moldova');
    Notification::assertSentTo($application->user, ApplicationDecided::class);
});

it('does not let an employee decide applications, nor HR grant a role wider than theirs', function () {
    $application = applicantWithApplication();

    expect(fn () => app(DecideApplication::class)->approve(userWithRoles('employee'), $application, []))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DecideApplication::class)->approve(userWithRoles('hr'), $application, ['super_admin']))->toThrow(PermissionEscalation::class)
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Pending);
});

// --- Two accounts of one person (Д-10) ---

it('links two accounts manually: sign-in methods move, the duplicate account is closed', function () {
    $existing = User::factory()->create();
    $new = User::factory()->pending()->create();
    RegistrationApplication::query()->create(['user_id' => $new->id, 'channel' => 'oauth:google', 'status' => ApplicationStatus::Pending, 'submitted_at' => now()]);
    SocialIdentity::query()->create(['user_id' => $new->id, 'provider' => 'google', 'provider_user_id' => 'g-9', 'linked_at' => now()]);
    $hint = AccountLinkHint::query()->create([
        'new_person_id' => $new->person_id, 'existing_person_id' => $existing->person_id, 'reasons' => ['email'], 'status' => LinkHintStatus::Open,
    ]);

    app(LinkAccounts::class)(userWithRoles('hr'), $hint);

    expect(SocialIdentity::query()->where('provider_user_id', 'g-9')->value('user_id'))->toBe($existing->id)
        ->and($new->fresh()->status)->toBe(UserStatus::Deactivated)
        ->and($hint->fresh()->status)->toBe(LinkHintStatus::Linked)
        ->and(journalCount('identity.accounts.linked'))->toBe(1);
});

it('lets a reviewer dismiss a hint, and not act on it twice', function () {
    $hint = AccountLinkHint::query()->create([
        'new_person_id' => User::factory()->pending()->create()->person_id,
        'existing_person_id' => User::factory()->create()->person_id,
        'reasons' => ['name'], 'status' => LinkHintStatus::Open,
    ]);
    $hr = userWithRoles('hr');

    app(DismissLinkHint::class)($hr, $hint);

    expect($hint->fresh()->status)->toBe(LinkHintStatus::Dismissed)
        ->and(fn () => app(LinkAccounts::class)($hr, $hint->fresh()))->toThrow(IdentityRuleViolation::class);
});

// --- Deactivation ---

it('deactivates a user, terminating their sessions, and never oneself', function () {
    $hr = userWithRoles('hr');
    $target = User::factory()->create();
    $epoch = $target->fresh()->session_epoch;

    app(SetUserActive::class)($hr, $target, false);

    expect($target->fresh()->status)->toBe(UserStatus::Deactivated)
        ->and($target->fresh()->session_epoch)->toBe($epoch + 1)
        ->and(journalCount('identity.user.deactivated'))->toBe(1)
        ->and(fn () => app(SetUserActive::class)($hr, $hr, false))->toThrow(IdentityRuleViolation::class);
});
