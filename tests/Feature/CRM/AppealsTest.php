<?php

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\CRM\Actions\ManageAppeals;
use App\Domain\CRM\Events\AppealStatusChanged;
use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CRM\Models\Appeal;
use App\Domain\CRM\Notifications\CrmNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CrmFixture;

/*
 * ФО §6.9.3 — appeals: new → in progress → done / rejected, a responsible, a deadline, links to a person and tasks.
 */

beforeEach(function () {
    $this->crm = CrmFixture::build();
    $this->org = $this->crm->org;
    $this->appeals = app(ManageAppeals::class);
});

it('registers an appeal with a number and a deadline by its type', function () {
    $o = $this->org;
    $person = $this->crm->supporter($o->centru, $o->branchA);

    $appeal = $this->appeals->register($this->crm->operator, ['title' => 'Iluminat stradal', 'type_code' => 'complaint', 'person_id' => $person->id, 'source_code' => 'phone_call']);

    expect($appeal->number)->toBe(sprintf('A-%s-%06d', now()->format('Y'), $appeal->id))
        ->and($appeal)->status->toBe('new')->territory_id->toBe($o->centru->id)->org_unit_id->toBe($o->branchA->id)
        ->and((int) round(now()->diffInDays($appeal->due_at)))->toBe(10)
        ->and(journalCount('crm.appeal.registered'))->toBe(1)
        ->and(fn () => $this->appeals->register($this->crm->operator, ['title' => ' ', 'type_code' => 'complaint']))->toThrow(CrmRuleViolation::class)
        ->and(fn () => $this->appeals->register($this->crm->operator, ['title' => 'X', 'type_code' => 'nope']))->toThrow(CrmRuleViolation::class);
});

it('lets an employee register appeals of their own territory or unit only', function () {
    $o = $this->org;
    $data = ['title' => 'Cerere', 'type_code' => 'question'];

    expect($this->appeals->register($o->a1, [...$data, 'territory_id' => $o->centru->id])->id)->toBeInt()
        ->and($this->appeals->register($o->a1, $data)->org_unit_id)->toBe($o->branchA->id)
        ->and(fn () => $this->appeals->register($o->a1, [...$data, 'territory_id' => $o->botanica->id]))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->appeals->register($this->crm->operator, [...$data, 'territory_id' => $o->baltiTerritory->id]))->toThrow(AuthorizationException::class);
});

it('walks the life cycle and refuses what the cycle does not allow', function () {
    Event::fake([AppealStatusChanged::class]);
    $o = $this->org;
    $appeal = $this->appeals->register($o->headA, ['title' => 'Cerere', 'type_code' => 'help_request', 'responsible_person_id' => $o->a1->person_id]);
    app(AuthorizationService::class)->forget();

    $this->appeals->changeStatus($o->a1, $appeal, 'in_progress');
    expect(fn () => $this->appeals->changeStatus($o->a1, $appeal->fresh(), 'rejected'))->toThrow(CrmRuleViolation::class)
        ->and(fn () => $this->appeals->changeStatus($o->a1, $appeal->fresh(), 'new'))->toThrow(CrmRuleViolation::class)
        ->and(fn () => $this->appeals->changeStatus($o->a2, $appeal->fresh(), 'done'))->toThrow(AuthorizationException::class);

    $this->appeals->changeStatus($o->a1, $appeal->fresh(), 'done', 'Rezolvat la fața locului');
    expect($appeal->fresh())->status->toBe('done')->closed_at->not->toBeNull()->resolution->toBe('Rezolvat la fața locului')
        // The responsible closed it; undoing that decision takes the right to manage the appeal.
        ->and(fn () => $this->appeals->changeStatus($o->a1, $appeal->fresh(), 'in_progress'))->toThrow(AuthorizationException::class);

    $this->appeals->changeStatus($o->headA, $appeal->fresh(), 'in_progress');
    expect($appeal->fresh())->status->toBe('in_progress')->closed_at->toBeNull()
        ->and(JournalEntry::query()->where('event_type', 'crm.appeal.status_changed')->count())->toBe(3);
    Event::assertDispatchedTimes(AppealStatusChanged::class, 4);
});

it('shows an appeal to those in scope, to its responsible and to the applicant — nobody else', function () {
    $o = $this->org;
    $authz = app(AuthorizationService::class);
    $applicant = userWithRoles('candidate');
    $own = $this->appeals->register($o->headA, ['title' => 'A mea', 'type_code' => 'question', 'person_id' => $applicant->person_id,
        'org_unit_id' => $o->branchA->id, 'territory_id' => $o->centru->id, 'responsible_person_id' => $o->a1->person_id]);
    $other = $this->appeals->register($o->headB, ['title' => 'Alta', 'type_code' => 'question']);
    $authz->forget();

    $ids = fn ($user) => $authz->scopeQuery($user, 'appeals.read', Appeal::query())->pluck('id')->all();

    expect($ids($applicant))->toBe([$own->id])
        ->and($ids($o->a1))->toBe([$own->id])
        ->and($ids($o->a2))->toBe([])
        ->and($ids($o->headB))->toBe([$other->id])
        ->and($ids($this->crm->operator))->toEqualCanonicalizing([$own->id, $other->id])
        ->and($authz->can($applicant, 'appeals.close', $own))->toBeFalse();
});

it('assigns only an active user, notifies them, and computes "overdue" instead of storing it', function () {
    Notification::fake();
    $o = $this->org;
    $appeal = $this->appeals->register($o->headA, ['title' => 'Cerere', 'type_code' => 'website_request']);

    $this->appeals->assign($o->headA, $appeal, $o->a1->person_id);
    Notification::assertSentTo($o->a1, CrmNotice::class, fn (CrmNotice $n): bool => $n->kind === CrmNotice::APPEAL_ASSIGNED);

    expect(fn () => $this->appeals->assign($o->headA, $appeal->fresh(), $this->crm->supporter()->id))->toThrow(CrmRuleViolation::class)
        ->and(fn () => $this->appeals->assign($o->a1, $appeal->fresh(), $o->a2->person_id))->toThrow(AuthorizationException::class)
        ->and($appeal->fresh()->isOverdue())->toBeFalse();

    $this->travel(4)->days();
    expect($appeal->fresh()->isOverdue())->toBeTrue()
        ->and(Appeal::query()->overdue()->count())->toBe(1);
});

it('creates a task from the appeal: the applicant becomes its subject, the task is linked', function () {
    $o = $this->org;
    $person = $this->crm->supporter($o->centru, $o->branchA);
    $appeal = $this->appeals->register($o->headA, ['title' => 'Bancă ruptă în parc', 'type_code' => 'complaint', 'person_id' => $person->id]);

    $task = $this->appeals->createTask($o->headA, $appeal, ['title' => 'Verificați la fața locului', 'type_code' => 'call'], [$o->a1->person_id]);

    expect($task->subject_person_id)->toBe($person->id)
        ->and($task->org_unit_id)->toBe($o->branchA->id)
        ->and($appeal->tasks()->pluck('tasks.id')->all())->toBe([$task->id])
        ->and(journalCount('crm.appeal.task_linked'))->toBe(1);
});
