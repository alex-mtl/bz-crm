<?php

use App\Domain\Access\Actions\ManageTerritoryGrants;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Exceptions\AccessRuleViolation;
use App\Domain\Access\Models\TerritoryGrant;
use App\Domain\Access\TerritorialAccess;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Geo\Actions\ImportTerritories;
use App\Domain\Geo\Models\Territory;
use App\Domain\Organization\Actions\ManageMembership;
use App\Domain\People\Models\AccountLinkHint;
use App\Domain\People\Models\Person;
use App\Domain\Tasks\Models\Task;
use Database\Seeders\Demo\Personas;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;

/*
 * IMPLEMENTATION-PLAN 2f — territories, org structure, territorial access (docs/demo/README.md § Территории).
 */

function personaSees(string $viewer, string $owner, string $code = 'people.read'): bool
{
    app(AuthorizationService::class)->forget();

    return app(AuthorizationService::class)->can(Personas::user($viewer), $code, Personas::user($owner)->person);
}

function visiblePeople(string $viewer): array
{
    app(AuthorizationService::class)->forget();

    return app(AuthorizationService::class)->scopeQuery(Personas::user($viewer), 'people.update', Person::query())->pluck('id')->all();
}

it('holds the full official territory reference, also without the demo world', function () {
    expect(Territory::query()->where('level', 'country')->count())->toBe(1)
        ->and(Territory::query()->where('level', 'macro_region')->count())->toBe(6)
        ->and(Territory::query()->where('level', 'district')->count())->toBe(37)
        ->and(Territory::query()->whereIn('level', ['locality', 'sector'])->count())->toBe(1684)
        ->and(Territory::query()->where(fn ($q) => $q->where('name_ro', '')->orWhere('name_ru', '')->orWhere('name_en', ''))->count())->toBe(0);

    $stats = app(ImportTerritories::class)();
    // The official reference stays as it was; the demo world adds its own invented polling districts below it (phase 7).
    expect($stats['created'])->toBe(0)
        ->and(Territory::query()->where('level', '!=', Territory::ELECTORAL_AREA)->count())->toBe(1728)
        ->and(Territory::query()->where('level', Territory::ELECTORAL_AREA)->count())->toBe(4);
});

it('finds territories by their traditional forms but shows official names (Д-9)', function () {
    $found = Territory::query()->search('Бельцы')->pluck('name_ru')->all();

    expect($found)->toContain('Бэлць');
});

it('lets a grant on "Moldova" cover any territory, and on "Nord" not the South', function () {
    $moldova = Personas::territory('md');
    $nord = Territory::query()->where('code', 'mr-nord')->sole();

    expect($moldova->covers(Personas::territory('cahul')))->toBeTrue()
        ->and($nord->covers(Personas::territory('balti')))->toBeTrue()
        ->and($nord->covers(Personas::territory('cahul')))->toBeFalse();
});

it('scopes the region head to both Chișinău branches but not Bălți — also in lists and counters', function () {
    expect(personaSees('chisinau_head', 'branch_a_employee_1', 'people.update'))->toBeTrue()
        ->and(personaSees('chisinau_head', 'branch_b_employee_1', 'people.update'))->toBeTrue()
        ->and(personaSees('chisinau_head', 'balti_employee_2', 'people.update'))->toBeFalse();

    $visible = visiblePeople('chisinau_head');
    expect($visible)->toContain(Personas::user('branch_a_employee_1')->person_id, Personas::user('branch_b_employee_1')->person_id)
        ->not->toContain(Personas::user('balti_employee_2')->person_id);

    $tasks = app(AuthorizationService::class)->scopeQuery(Personas::user('chisinau_head'), 'tasks.read', Task::query());
    expect((clone $tasks)->where('org_unit_id', Personas::unit('balti')->id)->count())->toBe(0)
        ->and((clone $tasks)->where('org_unit_id', Personas::unit('branch_b')->id)->count())->toBeGreaterThan(0)
        ->and(Task::query()->where('org_unit_id', Personas::unit('balti')->id)->count())->toBeGreaterThan(0);
});

it('gives HR of the central office access by the organization axis, not by territory', function () {
    expect(app(TerritorialAccess::class)->paths(Personas::user('hr')->person_id))->toBe([])
        ->and(personaSees('hr', 'balti_employee_2', 'people.update'))->toBeTrue();
});

it('lets the North–South employee inherit Bălți and Cahul, but not Chișinău', function () {
    $access = app(TerritorialAccess::class);
    $andrian = Personas::user('north_south_employee')->person_id;

    expect($access->covers($andrian, Personas::territory('balti')))->toBeTrue()
        ->and($access->covers($andrian, Personas::territory('cahul')))->toBeTrue()
        ->and($access->covers($andrian, Personas::territory('chisinau')))->toBeFalse();
});

it('lets Sergiu see sector Botanica through his grant, not the other sectors; revoked and expired grants give nothing', function () {
    $access = app(TerritorialAccess::class);

    expect($access->covers(Personas::user('branch_a_employee_3')->person_id, Personas::territory('chisinau/sectorul-botanica')))->toBeTrue()
        ->and($access->covers(Personas::user('branch_a_employee_3')->person_id, Personas::territory('chisinau/sectorul-buiucani')))->toBeFalse()
        ->and($access->covers(Personas::user('branch_a_employee_1')->person_id, Personas::territory('chisinau/sectorul-buiucani')))->toBeFalse()
        ->and($access->covers(Personas::user('branch_b_employee_1')->person_id, Personas::territory('chisinau/sectorul-centru')))->toBeFalse();

    $grant = TerritoryGrant::query()->where('person_id', Personas::user('branch_a_employee_3')->person_id)->whereNull('ended_at')->sole();
    app(ManageTerritoryGrants::class)->revoke(Personas::user('chisinau_head'), $grant, 'Test');
    $access->forget();
    expect($access->covers(Personas::user('branch_a_employee_3')->person_id, Personas::territory('chisinau/sectorul-botanica')))->toBeFalse();
});

it('lets the direct manager or someone above with the right grant territories — not a peer or a neighbour head', function () {
    $grants = app(ManageTerritoryGrants::class);
    $ion = Personas::user('branch_a_employee_1')->person;

    expect($grants->mayManageFor(Personas::user('branch_a_employee_2'), $ion))->toBeTrue()   // Maria is Ion's manager
        ->and($grants->mayManageFor(Personas::user('chisinau_head'), $ion))->toBeTrue()
        ->and($grants->mayManageFor(Personas::user('branch_a_employee_3'), $ion))->toBeFalse()
        ->and($grants->mayManageFor(Personas::user('branch_b_head'), $ion))->toBeFalse();
});

it('keeps grants within the giver\'s own access, requires a reason and expires by schedule (Д-3)', function () {
    $grants = app(ManageTerritoryGrants::class);
    $maria = Personas::user('branch_a_employee_2')->person;
    $botanica = Personas::territory('chisinau/sectorul-botanica');

    expect(fn () => $grants->grant(Personas::user('branch_a_head'), $maria, $botanica, 'x'))->toThrow(AccessRuleViolation::class)
        ->and(fn () => $grants->grant(Personas::user('chisinau_head'), $maria, $botanica, ' '))->toThrow(AccessRuleViolation::class)
        ->and(fn () => $grants->grant(Personas::user('branch_b_head'), $maria, $botanica, 'x'))->toThrow(AuthorizationException::class);

    $grants->grant(Personas::user('chisinau_head'), $maria, $botanica, 'Ajutor', now()->addDays(2));
    $this->travel(3)->days();
    Artisan::call('access:expire');
    app(TerritorialAccess::class)->forget();

    expect(app(TerritorialAccess::class)->covers($maria->id, $botanica))->toBeFalse()
        ->and(JournalEntry::query()->where('event_type', 'access.territory.expired')->count())->toBe(2);
});

it('recomputes inherited territories on transfer, keeping direct grants', function () {
    $sergiu = Personas::user('branch_a_employee_3');
    app(ManageMembership::class)->transfer(Personas::user('hr'), $sergiu->person, Personas::unit('balti'), 'Mutare');
    app(TerritorialAccess::class)->forget();
    $paths = app(TerritorialAccess::class)->paths($sergiu->person_id);

    expect($paths)->toContain(Personas::territory('balti')->path, Personas::territory('chisinau/sectorul-botanica')->path)
        ->not->toContain(Personas::territory('chisinau/sectorul-centru')->path);
});

it('journals every grant and revocation with giver, receiver, territory and reason', function () {
    $entry = JournalEntry::query()->where('event_type', 'access.territory.granted')->latest('id')->first();

    expect($entry->actor_user_id)->toBe(Personas::user('chisinau_head')->id)
        ->and($entry->subject_id)->toBe((string) Personas::user('branch_a_employee_3')->person_id)
        ->and($entry->new_values)->toHaveKeys(['territory_id', 'reason', 'expires_at'])
        ->and(JournalEntry::query()->where('event_type', 'access.territory.grant_denied')->value('actor_user_id'))->toBe(Personas::user('branch_a_head')->id);
});

it('shows where each territory comes from in the simulator (plan 2b)', function () {
    $rows = collect(app(TerritorialAccess::class)->explain(Personas::user('branch_a_employee_3')->person_id));

    expect($rows->pluck('source')->all())->toBe(['unit', 'grant'])
        ->and($rows->last()['grant']->granted_by_user_id)->toBe(Personas::user('chisinau_head')->id);

    $this->actingAs(Personas::user('security'))->get('/admin/access-simulator')->assertOk();
    $this->flushSession();
    $this->actingAs(Personas::user('branch_a_head'))->get('/admin/access-simulator')->assertForbidden();
});

it('addresses the "second account" hint to the existing person\'s manager, not to another branch head (Д-10)', function () {
    $can = fn (string $who) => app(AuthorizationService::class)->scopeQuery(Personas::user($who), 'people.link_hints.read',
        AccountLinkHint::query())->where('existing_person_id', Personas::user('branch_a_employee_2')->person_id)->exists();

    expect($can('branch_a_head'))->toBeTrue()->and($can('branch_b_head'))->toBeFalse();
});
