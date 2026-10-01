<?php

use App\Domain\Access\Actions\AssignRole;
use App\Domain\Access\Actions\CreateRole;
use App\Domain\Access\Actions\SetRolePermissions;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\RolePermission;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Actions\ManageMembership;
use App\Domain\Organization\Exceptions\OrganizationRuleViolation;
use App\Domain\Organization\Models\OrgMembership;
use App\Domain\Organization\Models\OrgMembershipHistory;
use App\Domain\Organization\OrgStructure;
use App\Domain\Profiles\ProfileAccess;
use Database\Seeders\Demo\Personas;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;

/*
 * IMPLEMENTATION-PLAN 2f — direct manager (Д-11), delegation, deny, permission catalog (docs/demo/README.md § Оргструктура).
 */

function managerOfPersona(string $key): ?int
{
    return OrgMembership::query()->find(Personas::user($key)->person_id)?->manager_person_id;
}

it('gives a new member of a branch its head as manager', function () {
    $newcomer = User::factory()->create();
    app(ManageMembership::class)->place(Personas::user('super_admin'), $newcomer->person, Personas::unit('branch_b'));

    expect(OrgMembership::query()->find($newcomer->person_id)->manager_person_id)->toBe(Personas::user('branch_b_head')->person_id);
});

it('lets the branch A head change the manager of own staff, not of branch B', function () {
    $membership = app(ManageMembership::class);

    $membership->changeManager(Personas::user('branch_a_head'), Personas::user('branch_a_employee_3')->person, Personas::user('branch_a_employee_2')->person);
    expect(managerOfPersona('branch_a_employee_3'))->toBe(Personas::user('branch_a_employee_2')->person_id);

    expect(fn () => $membership->changeManager(Personas::user('branch_a_head'), Personas::user('branch_b_employee_1')->person, Personas::user('branch_a_employee_2')->person))
        ->toThrow(AuthorizationException::class);
});

it('moves the "by relation" access with the manager: Maria sees Ion\'s HR assessment, Ana no longer by that ground', function () {
    $access = app(ProfileAccess::class);
    $ion = Personas::user('branch_a_employee_1')->person;

    expect(managerOfPersona('branch_a_employee_1'))->toBe(Personas::user('branch_a_employee_2')->person_id)
        ->and($access->canReadLayer(Personas::user('branch_a_employee_2'), $ion, 'hr'))->toBeTrue()
        ->and($access->canReadLayer(Personas::user('branch_a_head'), $ion, 'hr'))->toBeFalse();
});

it('journals the manager change: who, when, from whom to whom', function () {
    $entry = JournalEntry::query()->where('event_type', 'org.manager.changed')->where('subject_id', (string) Personas::user('branch_a_employee_1')->person_id)->sole();

    expect($entry->actor_user_id)->toBe(Personas::user('branch_a_head')->id)
        ->and($entry->old_values['manager_person_id'])->toBe(Personas::user('branch_a_head')->person_id)
        ->and($entry->new_values['manager_person_id'])->toBe(Personas::user('branch_a_employee_2')->person_id);
});

it('moves not re-configured members to a new branch head; Ion stays with Maria', function () {
    $newHead = Personas::user('google_user');
    app(ManageMembership::class)->assignHead(Personas::user('org_head'), Personas::unit('branch_a'), $newHead->person);

    expect(managerOfPersona('branch_a_employee_3'))->toBe($newHead->person_id)
        ->and(managerOfPersona('branch_a_head'))->toBe($newHead->person_id)
        ->and(managerOfPersona('branch_a_employee_1'))->toBe(Personas::user('branch_a_employee_2')->person_id);
});

it('makes the regional head the manager of branch heads; "subordinates" covers the whole chain', function () {
    expect(managerOfPersona('branch_a_head'))->toBe(Personas::user('chisinau_head')->person_id)
        ->and(app(OrgStructure::class)->subordinates(Personas::user('chisinau_head')->person_id))
        ->toContain(Personas::user('branch_a_employee_1')->person_id);
});

it('does not accept a manager from another unit', function () {
    expect(fn () => app(ManageMembership::class)->changeManager(Personas::user('branch_a_head'),
        Personas::user('branch_a_employee_3')->person, Personas::user('branch_b_employee_1')->person))->toThrow(OrganizationRuleViolation::class);
});

it('keeps exactly one unit; a transfer changes unit, default manager and history (Tatiana)', function () {
    $tatiana = Personas::user('balti_employee_1');

    expect(OrgMembership::query()->where('person_id', $tatiana->person_id)->count())->toBe(1)
        ->and(OrgMembership::query()->find($tatiana->person_id)->org_unit_id)->toBe(Personas::unit('balti')->id)
        ->and(managerOfPersona('balti_employee_1'))->toBe(Personas::user('balti_head')->person_id)
        ->and(OrgMembershipHistory::query()->where('person_id', $tatiana->person_id)->value('org_unit_id'))->toBe(Personas::unit('branch_b')->id)
        ->and(fn () => app(ManageMembership::class)->place(Personas::user('super_admin'), $tatiana->person, Personas::unit('branch_a')))
        ->toThrow(OrganizationRuleViolation::class);
});

it('lets the acting head act until the end of the delegation, then not; the expired one gives nothing', function () {
    $authorization = app(AuthorizationService::class);
    $olga = Personas::user('branch_b_employee_1')->person;

    expect($authorization->can(Personas::user('branch_b_acting_head'), 'people.update', $olga))->toBeTrue()
        ->and($authorization->can(Personas::user('branch_b_employee_2'), 'people.update', $olga))->toBeFalse();

    $this->travel(6)->days();
    Artisan::call('access:expire');
    $authorization->forget();

    expect($authorization->can(Personas::user('branch_b_acting_head'), 'people.update', $olga))->toBeFalse()
        ->and(JournalEntry::query()->where('event_type', 'access.role.expired')->count())->toBeGreaterThanOrEqual(2);
});

it('lets an explicit deny win over an allow from another role', function () {
    $admin = Personas::user('super_admin');
    $role = app(CreateRole::class)($admin, 'no_tasks', ['ro' => 'Fără sarcini'], 'ro');
    app(SetRolePermissions::class)($admin, $role, [], ['tasks.read']);
    app(AssignRole::class)($admin, Personas::user('branch_a_head'), $role);

    expect(app(AuthorizationService::class)->can(Personas::user('branch_a_head'), 'tasks.read'))->toBeFalse();
});

it('assigns every non-reserved permission code to at least one role', function () {
    foreach (app(PermissionRegistry::class)->all() as $definition) {
        if ($definition->reserved) {
            continue;
        }
        expect(RolePermission::query()->where('permission_code', $definition->code)->exists())->toBeTrue("{$definition->code} is not in any role");
    }
});

it('journals role scopes and delegations with giver, receiver, scope and reason', function () {
    $delegation = JournalEntry::query()->where('event_type', 'access.role.delegated')->latest('id')->first();

    expect($delegation->actor_user_id)->toBe(Personas::user('branch_b_head')->id)
        ->and($delegation->new_values)->toMatchArray(['role' => 'unit_head', 'scope' => 'org_unit', 'reason' => 'Concediu medical']);
});
