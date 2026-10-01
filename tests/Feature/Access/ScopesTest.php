<?php

use App\Domain\Access\Actions\AssignRole;
use App\Domain\Access\Actions\CreateRole;
use App\Domain\Access\Actions\ManageTerritoryGrants;
use App\Domain\Access\Actions\SetRolePermissions;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Exceptions\AccessRuleViolation;
use App\Domain\Access\Exceptions\PermissionEscalation;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\TerritoryGrant;
use App\Domain\Access\Scopes\ScopeType;
use App\Domain\Access\TerritorialAccess;
use App\Domain\Organization\Actions\ManageMembership;
use App\Domain\Organization\Actions\ManageOrgUnits;
use App\Domain\People\Models\Person;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\OrgFixture;

beforeEach(fn () => $this->org = OrgFixture::build());

function auth2(): AuthorizationService
{
    $service = app(AuthorizationService::class);
    $service->forget();

    return $service;
}

it('scopes a unit head to own unit and children', function () {
    $o = $this->org;

    expect(auth2()->can($o->headA, 'people.update', $o->a1->person))->toBeTrue()
        ->and(auth2()->can($o->headA, 'people.update', $o->b1->person))->toBeFalse()
        ->and(auth2()->can($o->regionHead, 'people.update', $o->b1->person))->toBeTrue()
        ->and(auth2()->can($o->regionHead, 'people.update', $o->balti1->person))->toBeFalse()
        ->and(auth2()->can($o->orgHead, 'people.update', $o->balti1->person))->toBeTrue();
});

it('applies the same scope in SQL lists', function () {
    $o = $this->org;

    $visible = auth2()->scopeQuery($o->regionHead, 'people.update', Person::query())->pluck('id')->all();

    expect($visible)->toContain($o->a1->person_id, $o->b1->person_id, $o->headA->person_id)
        ->not->toContain($o->balti1->person_id, $o->central1->person_id);
});

it('scopes by territory: a territory covers its subtree; Moldova covers everything', function () {
    $o = $this->org;
    app(AssignRole::class)($o->admin, $o->central1, Role::query()->where('code', 'unit_head')->sole(), scope: ScopeType::Territory, scopeId: $o->chisinau->id);

    expect(auth2()->can($o->central1, 'people.update', $o->b1->person))->toBeTrue()
        ->and(auth2()->can($o->central1, 'people.update', $o->balti1->person))->toBeFalse();

    $countryWide = $o->member($o->central);
    app(AssignRole::class)($o->admin, $countryWide, Role::query()->where('code', 'unit_head')->sole(), scope: ScopeType::Territory, scopeId: $o->moldova->id);
    expect(auth2()->can($countryWide, 'people.update', $o->balti1->person))->toBeTrue();
});

it('computes effective territories from the unit and direct grants, with their origin (Д-3)', function () {
    $o = $this->org;
    app(ManageTerritoryGrants::class)->grant($o->regionHead, $o->a1->person, $o->botanica, 'Ajutor în campanie', now()->addDays(10));

    $explained = app(TerritorialAccess::class)->explain($o->a1->person_id);
    expect(collect($explained)->pluck('source')->all())->toBe(['unit', 'grant'])
        ->and(app(TerritorialAccess::class)->covers($o->a1->person_id, $o->botanica))->toBeTrue()
        ->and(app(TerritorialAccess::class)->paths($o->central1->person_id))->toBe([]);
});

it('lets only the manager chain or a holder of the right in scope grant territories', function () {
    $o = $this->org;
    $grants = app(ManageTerritoryGrants::class);

    expect($grants->mayManageFor($o->headA, $o->a1->person))->toBeTrue()
        ->and($grants->mayManageFor($o->regionHead, $o->a1->person))->toBeTrue()
        ->and($grants->mayManageFor($o->headB, $o->a1->person))->toBeFalse()
        ->and($grants->mayManageFor($o->a2, $o->a1->person))->toBeFalse();

    expect(fn () => $grants->grant($o->headB, $o->a1->person, $o->botanica, 'x'))->toThrow(AuthorizationException::class);
});

it('grants only within own access, with a reason; refusals are journaled', function () {
    $o = $this->org;
    $grants = app(ManageTerritoryGrants::class);

    expect(fn () => $grants->grant($o->headA, $o->a1->person, $o->botanica, 'x'))->toThrow(AccessRuleViolation::class)
        ->and(journalCount('access.territory.grant_denied'))->toBe(1)
        ->and(fn () => $grants->grant($o->regionHead, $o->a1->person, $o->botanica, '  '))->toThrow(AccessRuleViolation::class);

    $grants->grant($o->regionHead, $o->a1->person, $o->botanica, 'Campanie');
    expect(journalCount('access.territory.granted'))->toBe(1);
});

it('expires a grant by the scheduler and journals it', function () {
    $o = $this->org;
    app(ManageTerritoryGrants::class)->grant($o->regionHead, $o->a1->person, $o->botanica, 'Campanie', now()->addDay());

    $this->travel(2)->days();
    Artisan::call('access:expire');

    expect(TerritoryGrant::query()->sole()->end_reason)->toBe('expired')
        ->and(journalCount('access.territory.expired'))->toBe(1)
        ->and(app(TerritorialAccess::class)->covers($o->a1->person_id, $o->botanica))->toBeFalse();
});

it('recomputes inherited territories on transfer and keeps direct grants', function () {
    $o = $this->org;
    app(ManageTerritoryGrants::class)->grant($o->orgHead, $o->a1->person, $o->baltiTerritory, 'Detașare');

    app(ManageMembership::class)->transfer($o->admin, $o->a1->person, $o->branchB, 'Reorganizare');
    app(TerritorialAccess::class)->forget();

    $paths = app(TerritorialAccess::class)->paths($o->a1->person_id);
    expect($paths)->toContain($o->botanica->path, $o->baltiTerritory->path)->not->toContain($o->centru->path);
});

it('follows a change of the unit territories at once', function () {
    $o = $this->org;
    app(ManageOrgUnits::class)->setTerritories($o->admin, $o->branchA, [$o->centru->id, $o->botanica->id]);

    expect(app(TerritorialAccess::class)->covers($o->a1->person_id, $o->botanica))->toBeTrue();
});

it('refuses a role with a wider scope than the granter holds (Д-17)', function () {
    $o = $this->org;
    $unitHead = Role::query()->where('code', 'unit_head')->sole();

    app(AssignRole::class)($o->regionHead, $o->a1, $unitHead, scope: ScopeType::OrgUnit, scopeId: $o->branchA->id);

    expect(fn () => app(AssignRole::class)($o->headA, $o->a2, $unitHead, scope: ScopeType::OrgUnit, scopeId: $o->regional->id))
        ->toThrow(PermissionEscalation::class)
        ->and(fn () => app(AssignRole::class)($o->regionHead, $o->a2, $unitHead))
        ->toThrow(PermissionEscalation::class);
});

it('lets a delegation act until its end date only', function () {
    $o = $this->org;
    app(AssignRole::class)->delegate($o->regionHead, $o->b1, Role::query()->where('code', 'unit_head')->sole(), now()->addDays(3), 'Concediu', ScopeType::OrgUnit, $o->branchB->id);

    expect(auth2()->can($o->b1, 'people.update', $o->headB->person))->toBeTrue();

    $this->travel(4)->days();
    Artisan::call('access:expire');
    expect(auth2()->can($o->b1, 'people.update', $o->headB->person))->toBeFalse()
        ->and(journalCount('access.role.expired'))->toBe(1);
});

it('lets an explicit deny win over an allow from another role', function () {
    $o = $this->org;
    $role = app(CreateRole::class)($o->admin, 'no_people', ['ro' => 'Fără oameni'], 'ro');
    app(SetRolePermissions::class)($o->admin, $role, [], ['people.read']);
    app(AssignRole::class)($o->admin, $o->a1, $role);

    expect(auth2()->can($o->a1, 'people.read', $o->a2->person))->toBeFalse();
});

it('gives inherited permissions through role inheritance', function () {
    $o = $this->org;
    $child = app(CreateRole::class)($o->admin, 'deputy', ['ro' => 'Adjunct'], 'ro');
    $child->update(['inherits_role_id' => Role::query()->where('code', 'unit_head')->value('id')]);
    app(AssignRole::class)($o->admin, $o->a1, $child, scope: ScopeType::OwnUnit);

    expect(auth2()->can($o->a1, 'people.update', $o->a2->person))->toBeTrue()
        ->and(auth2()->can($o->a1, 'people.update', $o->b1->person))->toBeFalse();
});
