<?php

use App\Domain\Access\Actions\AssignRole;
use App\Domain\Access\Actions\SetRolePermissions;
use App\Domain\Access\Admission\AcceptInvitation;
use App\Domain\Access\Admission\InviteUser;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Exceptions\PermissionEscalation;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\RolePermission;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\Invitation;
use Database\Seeders\Demo\Personas;

/*
 * IMPLEMENTATION-PLAN 1.4 — roles and rights, on the demo world (docs/demo/README.md § Права).
 */

it('keeps the role constructor closed to people without administration rights', function (string $persona) {
    $this->actingAs(Personas::user($persona))->get('/admin/roles/create')->assertForbidden();
})->with(['branch_a_employee_1', 'branch_a_head', 'hr', 'volunteer']);

it('opens the role constructor to the super admin', function () {
    $this->actingAs(Personas::user('super_admin'))->get('/admin/roles/create')->assertOk();
});

it('refuses to grant more than you have (Д-17), and journals the attempt', function () {
    $regionHead = Personas::user('chisinau_head');
    $before = journalCount('access.escalation.denied');

    // The HR role contains rights a unit head does not have (e.g. users.approve).
    expect(fn () => app(AssignRole::class)($regionHead, Personas::user('branch_a_employee_1'), Role::query()->where('code', 'hr')->sole()))
        ->toThrow(PermissionEscalation::class)
        ->and(fn () => app(InviteUser::class)($regionHead, 'someone@example.test', ['hr']))
        ->toThrow(PermissionEscalation::class)
        ->and(journalCount('access.escalation.denied'))->toBe($before + 2);
});

it('shows the demo world\'s refused escalation in the journal', function () {
    $entry = JournalEntry::query()->where('event_type', 'access.escalation.denied')->oldest('id')->sole();

    expect($entry->actor_user_id)->toBe(Personas::user('branch_a_head')->id);
});

it('gives reserved rights to nobody by default; an explicit grant is journaled', function () {
    $reserved = collect(app(PermissionRegistry::class)->all())->filter->reserved->map->code->values()->all();
    expect($reserved)->not->toBeEmpty()
        ->and(RolePermission::query()->whereIn('permission_code', $reserved)->exists())->toBeFalse();

    $role = Role::query()->where('code', 'security')->sole();
    $current = $role->permissions()->pluck('permission_code')->all();
    app(SetRolePermissions::class)(Personas::user('super_admin'), $role, [...$current, $reserved[0]]);

    expect(JournalEntry::query()->where('event_type', 'access.reserved_permission.granted')->latest('id')->value('new_values'))
        ->toBe(['permission' => $reserved[0]]);
});

it('records who approved an application and which roles were granted', function () {
    $entry = JournalEntry::query()->where('event_type', 'admission.application.approved')->sole();

    expect($entry->actor_user_id)->toBe(Personas::user('hr')->id)
        ->and($entry->new_values['roles'])->toBe(['volunteer']);
});

it('does not accept an expired invitation', function () {
    $hr = Personas::user('hr');
    ['token' => $token] = app(InviteUser::class)($hr, 'late.person@example.test', ['volunteer'], validDays: 1);
    $this->travel(2)->days();

    expect(fn () => app(AcceptInvitation::class)($token, 'Late', null, 'Str0ng-passphrase!', 'ro'))->toThrow(IdentityRuleViolation::class);
    $this->get(route('invitation.accept', $token))->assertSee('data-test="invitation-unusable"', escape: false);
});

it('has invitations in every state', function () {
    $states = Invitation::query()->get()->map->state()->countBy()->all();

    expect($states)->toHaveKeys(['accepted', 'active', 'expired', 'revoked']);
});

it('gives the acting branch head the role until its end date; an expired delegation grants nothing', function () {
    $authorization = app(AuthorizationService::class);

    expect($authorization->can(Personas::user('branch_b_acting_head'), 'users.invite'))->toBeTrue()
        ->and($authorization->can(Personas::user('branch_b_employee_2'), 'users.invite'))->toBeFalse();

    $this->travel(31)->days();
    $authorization->forget();
    expect($authorization->can(Personas::user('branch_b_acting_head'), 'users.invite'))->toBeFalse();
});
