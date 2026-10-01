<?php

use App\Domain\Organization\Actions\ManageMembership;
use App\Domain\Organization\Actions\ManageOrgUnits;
use App\Domain\Organization\Exceptions\OrganizationRuleViolation;
use App\Domain\Organization\Models\OrgMembership;
use App\Domain\Organization\Models\OrgMembershipHistory;
use App\Domain\Organization\OrgStructure;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\OrgFixture;

beforeEach(fn () => $this->org = OrgFixture::build());

function managerOf($user): ?int
{
    return OrgMembership::query()->find($user->person_id)?->manager_person_id;
}

it('makes the unit head the default manager, and the parent head the manager of a head (Д-11)', function () {
    $o = $this->org;

    expect(managerOf($o->a1))->toBe($o->headA->person_id)
        ->and(managerOf($o->headA))->toBe($o->regionHead->person_id)
        ->and(managerOf($o->regionHead))->toBe($o->orgHead->person_id)
        ->and(managerOf($o->orgHead))->toBeNull()
        ->and(app(OrgStructure::class)->managerChain($o->a1->person_id))
        ->toBe([$o->headA->person_id, $o->regionHead->person_id, $o->orgHead->person_id]);
});

it('lets the branch head change the manager of own staff, not of another branch', function () {
    $o = $this->org;

    app(ManageMembership::class)->changeManager($o->headA, $o->a2->person, $o->a1->person);
    expect(managerOf($o->a2))->toBe($o->a1->person_id)
        ->and(journalCount('org.manager.changed'))->toBeGreaterThan(0);

    expect(fn () => app(ManageMembership::class)->changeManager($o->headA, $o->b1->person, $o->a1->person))
        ->toThrow(AuthorizationException::class);
});

it('does not accept a manager from another unit, nor a subordinate as manager', function () {
    $o = $this->org;

    expect(fn () => app(ManageMembership::class)->changeManager($o->headA, $o->a1->person, $o->b1->person))
        ->toThrow(OrganizationRuleViolation::class);

    app(ManageMembership::class)->changeManager($o->headA, $o->a2->person, $o->a1->person);
    expect(fn () => app(ManageMembership::class)->changeManager($o->regionHead, $o->a1->person, $o->a2->person))
        ->toThrow(OrganizationRuleViolation::class);
});

it('counts the whole chain below as subordinates (Д-11 п. 4)', function () {
    $o = $this->org;
    app(ManageMembership::class)->changeManager($o->headA, $o->a2->person, $o->a1->person);

    expect(app(OrgStructure::class)->subordinates($o->headA->person_id))->toContain($o->a1->person_id, $o->a2->person_id)
        ->and(app(OrgStructure::class)->subordinates($o->regionHead->person_id))->toContain($o->a2->person_id);
});

it('moves members who were not re-configured to a new head; a manual choice stays', function () {
    $o = $this->org;
    app(ManageMembership::class)->changeManager($o->headA, $o->a2->person, $o->a1->person);
    $newHead = $o->member($o->branchA);

    app(ManageMembership::class)->assignHead($o->admin, $o->branchA->fresh(), $newHead->person);

    expect(managerOf($o->a1))->toBe($newHead->person_id)
        ->and(managerOf($o->headA))->toBe($newHead->person_id)
        ->and(managerOf($o->a2))->toBe($o->a1->person_id)
        ->and(managerOf($newHead))->toBe($o->regionHead->person_id);
});

it('keeps exactly one unit: placing twice is refused, a transfer keeps history and resets the manager', function () {
    $o = $this->org;

    expect(fn () => app(ManageMembership::class)->place($o->admin, $o->a1->person, $o->branchB))
        ->toThrow(OrganizationRuleViolation::class);

    app(ManageMembership::class)->transfer($o->admin, $o->a1->person, $o->branchB, 'Reorganizare');

    expect(OrgMembership::query()->find($o->a1->person_id)->org_unit_id)->toBe($o->branchB->id)
        ->and(managerOf($o->a1))->toBe($o->headB->person_id)
        ->and(OrgMembershipHistory::query()->where('person_id', $o->a1->person_id)->value('org_unit_id'))->toBe($o->branchA->id)
        ->and(journalCount('org.membership.transferred'))->toBe(1);
});

it('does not transfer a unit head until another head is appointed', function () {
    $o = $this->org;

    expect(fn () => app(ManageMembership::class)->transfer($o->admin, $o->headA->person, $o->branchB, 'x'))
        ->toThrow(OrganizationRuleViolation::class);
});

it('does not move a unit into its own branch', function () {
    $o = $this->org;

    expect(fn () => app(ManageOrgUnits::class)->move($o->admin, $o->regional, $o->branchA))
        ->toThrow(OrganizationRuleViolation::class);

    app(ManageOrgUnits::class)->move($o->admin, $o->branchB, $o->root);
    expect($o->branchB->fresh()->path)->toBe($o->root->path.$o->branchB->id.'/');
});
