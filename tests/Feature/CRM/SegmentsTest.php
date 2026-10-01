<?php

use App\Domain\CRM\Actions\ManageLeads;
use App\Domain\CRM\Actions\RecordInteraction;
use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CRM\Models\Segment;
use App\Domain\CRM\Segments;
use App\Domain\CustomObjects\CustomFields;
use App\Domain\CustomObjects\Models\CustomField;
use App\Domain\Profiles\Actions\ManageProfile;
use App\Domain\Tasks\Models\Task;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\CrmFixture;

/*
 * ФО §6.9.4 — segments and saved filters: "all supporters of sector 3 older than 30".
 */

beforeEach(function () {
    $this->crm = CrmFixture::build();
    $this->org = $this->crm->org;
    $this->segments = app(Segments::class);
});

function bornYearsAgo($test, $person, int $years): void
{
    app(ManageProfile::class)->updateFor($test->org->admin, $person, ['birth_date' => today()->subYears($years)->subDay()->toDateString()]);
}

it('selects by type, territory with everything below it, and age', function () {
    $o = $this->org;
    $older = $this->crm->supporter($o->centru);
    $younger = $this->crm->supporter($o->centru);
    $olderInBotanica = $this->crm->supporter($o->botanica);
    $olderInBalti = $this->crm->supporter($o->baltiTerritory);
    $partner = $this->crm->supporter($o->centru, null, ['person_type' => 'partner']);
    foreach ([[$older, 45], [$younger, 22], [$olderInBotanica, 31], [$olderInBalti, 50], [$partner, 40]] as [$person, $age]) {
        bornYearsAgo($this, $person, $age);
    }

    $ids = fn (array $criteria) => $this->segments->preview($o->admin, $criteria)->pluck('people.id')->all();

    expect($ids(['person_types' => ['supporter'], 'territory_id' => $o->chisinau->id, 'age_from' => 30]))
        ->toEqualCanonicalizing([$older->id, $olderInBotanica->id])
        ->and($ids(['person_types' => ['supporter'], 'territory_id' => $o->centru->id, 'age_to' => 30]))->toBe([$younger->id])
        ->and($ids(['territory_id' => $o->centru->id, 'age_from' => 30]))->toEqualCanonicalizing([$older->id, $partner->id])
        // People of the structure are found by the territories of their unit.
        ->and($ids(['person_types' => ['employee'], 'territory_id' => $o->botanica->id]))->toEqualCanonicalizing([$o->headB->person_id, $o->b1->person_id]);
});

it('selects by pipeline stage, by interactions and by a custom field', function () {
    $o = $this->org;
    [$a, $b, $c] = [$this->crm->supporter($o->centru), $this->crm->supporter($o->centru), $this->crm->supporter($o->centru)];
    $lead = app(ManageLeads::class)->create($o->admin, $this->crm->pipeline, $a);
    app(ManageLeads::class)->move($o->admin, $lead, $this->crm->stage('meeting'));
    app(ManageLeads::class)->create($o->admin, $this->crm->pipeline, $b);
    app(RecordInteraction::class)($o->admin, $c, 'event_visit', ['occurred_at' => now()->subDays(3)]);
    app(CustomFields::class)->saveDefinition($o->admin, CustomField::PERSON, ['names' => ['ro' => 'Are mașină'], 'code' => 'has_car', 'field_type' => 'bool'], 'ro');
    app(CustomFields::class)->store(CustomField::PERSON, $b->id, ['has_car' => 'da']);

    $ids = fn (array $criteria) => $this->segments->preview($o->admin, $criteria)->pluck('people.id')->all();

    expect($ids(['pipeline_id' => $this->crm->pipeline->id]))->toEqualCanonicalizing([$a->id, $b->id])
        ->and($ids(['pipeline_id' => $this->crm->pipeline->id, 'stage_id' => $this->crm->stage('meeting')->id]))->toBe([$a->id])
        ->and($ids(['interaction_kind' => 'event_visit', 'interaction_days' => 7]))->toBe([$c->id])
        ->and($ids(['interaction_kind' => 'event_visit', 'interaction_days' => 1]))->toBe([])
        ->and($ids(['custom' => ['has_car' => '1']]))->toBe([$b->id])
        ->and(fn () => $ids(['stage_id' => 5]))->toThrow(CrmRuleViolation::class)
        ->and(fn () => $ids(['age_from' => 40, 'age_to' => 30]))->toThrow(CrmRuleViolation::class);
});

it('computes a shared segment for each reader inside their own scope', function () {
    $o = $this->org;
    $this->crm->supporter($o->centru, $o->branchA);
    $this->crm->supporter($o->baltiTerritory, $o->balti);
    $volunteer = userWithRoles('volunteer');

    $segment = $this->segments->save($this->crm->hr, ['name' => 'Susținători', 'criteria' => ['person_types' => ['supporter']], 'visibility' => 'shared']);

    expect($this->segments->people($this->crm->hr, $segment)->count())->toBe(2)
        ->and($this->segments->people($o->headA, $segment)->count())->toBe(2)   // employees read the whole registry (catalog §3.2)
        ->and(fn () => $this->segments->people($volunteer, $segment))->toThrow(AuthorizationException::class)
        ->and($this->segments->preview($volunteer, $segment->criteria)->count())->toBe(0);
});

it('keeps a personal filter to its owner; a shared segment takes segments.manage to create and segments.read to see', function () {
    $o = $this->org;
    $own = $this->segments->save($o->a1, ['name' => 'Ai mei', 'criteria' => ['search' => 'Ion', 'age_from' => '', 'languages' => []]]);

    expect($own)->visibility->toBe('personal')->criteria->toBe(['search' => 'Ion'])
        ->and($this->segments->visibleTo($o->a1)->pluck('id')->all())->toBe([$own->id])
        ->and($this->segments->visibleTo($o->a2)->count())->toBe(0)
        ->and(fn () => $this->segments->people($o->a2, $own))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->segments->save($o->a1, ['name' => 'Comun', 'criteria' => [], 'visibility' => 'shared']))->toThrow(AuthorizationException::class);

    $shared = $this->segments->save($o->headA, ['name' => 'Comun', 'criteria' => ['person_types' => ['supporter']], 'visibility' => 'shared']);
    expect($this->segments->visibleTo($this->crm->operator)->pluck('id')->all())->toBe([$shared->id])
        ->and($this->segments->visibleTo($o->a2)->count())->toBe(0)
        // Another branch head cannot change it; the region head above its owner can.
        ->and(fn () => $this->segments->save($o->headB, ['name' => 'Altfel', 'criteria' => []], $shared))->toThrow(AuthorizationException::class)
        ->and($this->segments->save($o->regionHead, ['name' => 'Comun, redenumit', 'criteria' => ['person_types' => ['supporter']]], $shared)->name)->toBe('Comun, redenumit');

    $this->segments->delete($o->headA, $shared->fresh());
    expect(Segment::query()->pluck('id')->all())->toBe([$own->id])
        ->and(journalCount('crm.segment.deleted'))->toBe(1);
});

it('creates one task per person of the segment, the person being its subject', function () {
    $o = $this->org;
    $people = [$this->crm->supporter($o->centru, $o->branchA), $this->crm->supporter($o->centru, $o->branchA)];
    $this->crm->supporter($o->botanica, $o->branchB);
    $segment = $this->segments->save($o->headA, ['name' => 'Centru', 'criteria' => ['person_types' => ['supporter'], 'territory_id' => $o->centru->id]]);

    $created = $this->segments->createTasks($o->headA, $segment, ['title' => 'Invitați la întâlnire', 'type_code' => 'call'], [$o->a1->person_id]);

    expect($created)->toBe(2)
        ->and(Task::query()->where('title', 'Invitați la întâlnire')->pluck('subject_person_id')->all())->toEqualCanonicalizing(array_map(fn ($p) => $p->id, $people))
        ->and(journalCount('crm.segment.tasks_created'))->toBe(1);
});
