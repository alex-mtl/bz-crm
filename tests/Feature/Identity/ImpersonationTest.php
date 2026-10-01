<?php

use App\Domain\Audit\Enums\ActingAs;
use App\Domain\Audit\EventJournal;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Identity\Actions\SetUserActive;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Impersonations;
use App\Domain\Identity\Models\Impersonation;
use App\Domain\Identity\Notifications\ImpersonationNotice;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Http\Impersonation\ImpersonationSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Д-19: impersonation — super admin only, with a reason, at most 30 minutes, journaled, the user notified.
 */

function startImpersonation($admin, $target): void
{
    test()->actingAs($admin);
    Livewire::test(ViewUser::class, ['record' => $target->getKey()])
        ->callAction('impersonate', data: ['reason' => 'Nu vede sarcinile'])
        ->assertHasNoActionErrors()
        ->assertRedirect('/admin');
}

it('lets the super admin act through an employee account, with a banner, and tells the employee', function () {
    Notification::fake();
    $admin = userWithRoles('super_admin');
    $target = userWithRoles('employee');

    startImpersonation($admin, $target);

    expect(auth()->id())->toBe($target->id)
        ->and(Impersonation::query()->sole()->reason)->toBe('Nu vede sarcinile')
        ->and(JournalEntry::query()->where('event_type', 'identity.impersonation.started')->sole()->actor_user_id)->toBe($admin->id)
        ->and(JournalEntry::query()->where('event_type', 'auth.login.succeeded')->where('subject_id', (string) $target->id)->exists())->toBeFalse();
    Notification::assertSentTo($target, ImpersonationNotice::class);

    $this->get('/admin')->assertOk()->assertSee(__('admin.impersonation.leave'));
});

it('journals actions during the impersonation as the impersonator, on behalf of the account', function () {
    $admin = userWithRoles('super_admin');
    $target = userWithRoles('employee');
    $impersonation = app(Impersonations::class)->begin($admin, $target, 'Diagnostic');
    $this->actingAs($target);

    app(Impersonations::class)->applyToContext($impersonation);
    $entry = app(EventJournal::class)->record('audit.viewed', $target);

    expect($entry->actor_user_id)->toBe($admin->id)
        ->and($entry->acting_as)->toBe(ActingAs::Impersonation)
        ->and($entry->acting_as_ref)->toBe((string) $impersonation->id)
        ->and($entry->context['on_behalf_of_user_id'])->toBe($target->id);
});

it('returns to the own account from the banner', function () {
    $admin = userWithRoles('super_admin');
    $target = userWithRoles('employee');
    startImpersonation($admin, $target);

    $this->post(route('impersonation.leave'))->assertRedirect('/admin/users/'.$target->id);

    $ended = JournalEntry::query()->where('event_type', 'identity.impersonation.ended')->sole();
    expect(auth()->id())->toBe($admin->id)
        ->and(Impersonation::query()->sole()->end_reason)->toBe(Impersonation::END_STOPPED)
        ->and($ended->actor_user_id)->toBe($admin->id)
        ->and($ended->acting_as)->toBe(ActingAs::Impersonation);
});

it('ends the impersonation after 30 minutes', function () {
    $admin = userWithRoles('super_admin');
    $target = userWithRoles('employee');
    startImpersonation($admin, $target);

    $this->travel(31)->minutes();
    $this->get('/admin/users')->assertRedirect('/admin');

    expect(auth()->id())->toBe($admin->id)
        ->and(Impersonation::query()->sole()->end_reason)->toBe(Impersonation::END_EXPIRED);
});

it('ends the impersonation when the impersonator is deactivated', function () {
    $admin = userWithRoles('super_admin');
    $target = userWithRoles('employee');
    startImpersonation($admin, $target);
    app(SetUserActive::class)(userWithRoles('super_admin'), $admin, false);

    $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));

    expect(auth()->check())->toBeFalse()
        ->and(Impersonation::query()->sole()->ended_at)->not->toBeNull();
});

it('ends the impersonation on sign-out', function () {
    $admin = userWithRoles('super_admin');
    startImpersonation($admin, userWithRoles('employee'));

    $this->post(route('filament.admin.auth.logout'));

    expect(Impersonation::query()->sole()->end_reason)->toBe(Impersonation::END_SIGNED_OUT)
        ->and(auth()->check())->toBeFalse()
        ->and(session()->has(ImpersonationSession::KEY))->toBeFalse();
});

it('allows impersonation to nobody but the super admin by default', function () {
    $target = userWithRoles('employee');

    foreach (['org_head', 'security', 'hr'] as $role) {
        expect(fn () => app(Impersonations::class)->begin(userWithRoles($role), $target, 'x'))->toThrow(AuthorizationException::class);
    }

    $this->actingAs(userWithRoles('security'));
    Livewire::test(ViewUser::class, ['record' => $target->getKey()])->assertActionHidden('impersonate');
});

it('requires a reason, an active user, not oneself and not another impersonator', function () {
    $admin = userWithRoles('super_admin');
    $impersonations = app(Impersonations::class);
    $inactive = userWithRoles('employee');
    app(SetUserActive::class)($admin, $inactive, false);

    expect(fn () => $impersonations->begin($admin, userWithRoles('employee'), '  '))->toThrow(IdentityRuleViolation::class)
        ->and(fn () => $impersonations->begin($admin, $inactive->fresh(), 'x'))->toThrow(IdentityRuleViolation::class)
        ->and(fn () => $impersonations->begin($admin, $admin, 'x'))->toThrow(IdentityRuleViolation::class)
        ->and(fn () => $impersonations->begin($admin, userWithRoles('super_admin'), 'x'))->toThrow(IdentityRuleViolation::class)
        ->and(Impersonation::query()->count())->toBe(0);
});
