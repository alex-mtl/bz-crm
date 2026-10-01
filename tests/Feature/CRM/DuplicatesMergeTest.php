<?php

use App\Domain\Audit\Models\JournalEntry;
use App\Domain\CRM\Actions\ManageAppeals;
use App\Domain\CRM\Actions\ManageLeads;
use App\Domain\CRM\Actions\ManageRelations;
use App\Domain\CRM\Actions\MergePeople;
use App\Domain\CRM\Actions\RecordInteraction;
use App\Domain\CRM\Duplicates;
use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CRM\Models\Appeal;
use App\Domain\CRM\Models\DuplicateCandidate;
use App\Domain\CRM\Models\Interaction;
use App\Domain\CRM\Models\Lead;
use App\Domain\CRM\Models\PersonMerge;
use App\Domain\CRM\Models\PersonRelation;
use App\Domain\People\Actions\ManagePeople;
use App\Domain\People\Models\PersonStatusHistory;
use App\Domain\Tasks\Actions\ManageTasks;
use App\Domain\Tasks\Models\Task;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\CrmFixture;

/*
 * ФО §6.9.1, ТЗ §27 — possible duplicates by phone / e-mail / name and merging with history preserved.
 */

beforeEach(function () {
    $this->crm = CrmFixture::build();
    $this->org = $this->crm->org;
    $this->duplicates = app(Duplicates::class);
});

it('queues a pair as soon as a matching card appears, with the reasons of the match', function () {
    $first = $this->crm->supporter(null, null, ['phone' => '069 555 111', 'email' => 'vera@example.org']);
    $second = $this->crm->supporter(null, null, ['phone' => '+373 69 555 111']);
    $third = $this->crm->supporter(null, null, ['first_name' => $first->first_name, 'last_name' => $first->last_name]);
    $this->crm->supporter(null, null, ['phone' => '069 999 000']);

    $pairs = DuplicateCandidate::query()->orderBy('id')->get();

    expect($pairs)->toHaveCount(2)
        ->and($pairs[0]->only(['person_a_id', 'person_b_id', 'status']))->toBe(['person_a_id' => $first->id, 'person_b_id' => $second->id, 'status' => 'open'])
        ->and($pairs[0]->reasons)->toBe(['phone'])
        ->and($pairs[1]->person_b_id)->toBe($third->id)
        ->and($pairs[1]->reasons)->toBe(['name']);
});

it('shows a reviewer only the pairs where both cards are in their scope', function () {
    $o = $this->org;
    $a = $this->crm->supporter($o->centru, $o->branchA, ['phone' => '069 100 100']);
    $this->crm->supporter($o->centru, $o->branchA, ['phone' => '069 100 100']);
    $this->crm->supporter($o->botanica, $o->branchB, ['email' => 'same@example.org']);
    $this->crm->supporter($o->centru, $o->branchA, ['email' => 'same@example.org']);

    expect($this->duplicates->queueFor($this->crm->hr)->count())->toBe(2)
        ->and($this->duplicates->queueFor($o->headA)->pluck('person_a_id')->all())->toBe([$a->id])
        ->and($this->duplicates->queueFor($o->headB)->count())->toBe(0)
        ->and($this->duplicates->queueFor($o->a1)->count())->toBe(0);
});

it('never raises a dismissed pair again', function () {
    $first = $this->crm->supporter(null, null, ['phone' => '069 200 200']);
    $this->crm->supporter(null, null, ['phone' => '069 200 200']);
    $pair = DuplicateCandidate::query()->sole();

    $this->duplicates->dismiss($this->crm->hr, $pair);
    Artisan::call('crm:find-duplicates');
    app(ManagePeople::class)->update($this->org->admin, $first, ['first_name' => 'Altul']);

    expect(DuplicateCandidate::query()->sole()->status)->toBe('dismissed')
        ->and(fn () => $this->duplicates->dismiss($this->crm->hr, $pair->fresh()))->toThrow(CrmRuleViolation::class)
        ->and(fn () => $this->duplicates->dismiss($this->org->a1, $pair->fresh()))->toThrow(AuthorizationException::class);
});

it('re-points tasks, leads, appeals, interactions and relations to the kept card and loses nothing', function () {
    $o = $this->org;
    $kept = $this->crm->supporter($o->centru, $o->branchA, ['phone' => '069 300 300']);
    $duplicate = $this->crm->supporter($o->centru, $o->branchA, ['phone' => '069 300 300', 'email' => 'dup@example.org']);
    $friend = $this->crm->supporter($o->centru, $o->branchA);

    $task = app(ManageTasks::class)->create($o->headA, ['title' => 'Sunați', 'type_code' => 'call', 'subject_person_id' => $duplicate->id]);
    $lead = app(ManageLeads::class)->create($o->headA, $this->crm->pipeline, $duplicate);
    $appeal = app(ManageAppeals::class)->register($o->headA, ['title' => 'Cerere', 'type_code' => 'question', 'person_id' => $duplicate->id]);
    $interaction = app(RecordInteraction::class)($o->headA, $duplicate, 'call');
    app(ManageRelations::class)->add($this->crm->hr, $duplicate, $friend, 'friend');
    app(ManageRelations::class)->add($this->crm->hr, $kept, $friend, 'friend');       // would collide after the merge
    app(ManageRelations::class)->add($this->crm->hr, $duplicate, $kept, 'relative');   // would point at oneself

    $merge = app(MergePeople::class)($this->crm->hr, $kept, $duplicate);

    expect(Task::query()->find($task->id)->subject_person_id)->toBe($kept->id)
        ->and(Lead::query()->find($lead->id)->person_id)->toBe($kept->id)
        ->and(Appeal::query()->find($appeal->id)->person_id)->toBe($kept->id)
        ->and(Interaction::query()->find($interaction->id)->person_id)->toBe($kept->id)
        ->and(PersonRelation::query()->count())->toBe(1)
        ->and($kept->fresh()->email)->toBe('dup@example.org')
        ->and($duplicate->fresh())->duplicate_of_person_id->toBe($kept->id)->isArchived()->toBeTrue()
        ->and($merge->snapshot['email'])->toBe('dup@example.org')
        ->and($merge->moved['filled'])->toBe(['email'])
        ->and($merge->moved['person_relations.person_id']['redundant'])->toHaveCount(2)
        ->and(DuplicateCandidate::query()->sole()->status)->toBe('merged')
        ->and(PersonStatusHistory::query()->where('kind', 'merged')->count())->toBe(2)
        ->and(JournalEntry::query()->where('event_type', 'crm.people.merged')->sole()->new_values['merged_person_id'])->toBe($duplicate->id);
});

it('refuses to merge a card with an account, an already merged card, or without the right', function () {
    $o = $this->org;
    $merge = app(MergePeople::class);
    [$a, $b, $c] = [$this->crm->supporter(), $this->crm->supporter(), $this->crm->supporter()];

    expect(fn () => $merge($this->crm->hr, $a, $o->a1->person))->toThrow(CrmRuleViolation::class)
        ->and(fn () => $merge($this->crm->hr, $a, $a))->toThrow(CrmRuleViolation::class)
        ->and(fn () => $merge($o->headA, $a, $b))->toThrow(AuthorizationException::class);

    // The account holder may be the card that stays.
    $merge($this->crm->hr, $o->a1->person, $a);
    expect(fn () => $merge($this->crm->hr, $b, $a->fresh()))->toThrow(CrmRuleViolation::class)
        ->and(PersonMerge::query()->count())->toBe(1)
        ->and($c->fresh()->isArchived())->toBeFalse();
});
