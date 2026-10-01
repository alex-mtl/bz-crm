<?php

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\CRM\Actions\ManageLeads;
use App\Domain\CRM\Actions\ManagePipelines;
use App\Domain\CRM\Events\LeadStageChanged;
use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CRM\Models\Lead;
use App\Domain\CRM\Models\LeadStageHistory;
use App\Domain\CRM\Models\PipelineStage;
use App\Domain\CRM\Notifications\CrmNotice;
use App\Domain\Identity\Actions\SetUserActive;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CrmFixture;

/*
 * ФО §6.9.2, ТЗ §28 — configurable pipelines; a lead is a person + a position + a responsible + history.
 */

beforeEach(function () {
    $this->crm = CrmFixture::build();
    $this->org = $this->crm->org;
    $this->leads = app(ManageLeads::class);
});

it('lets the administrator build a pipeline without code; others cannot', function () {
    $pipelines = app(ManagePipelines::class);

    $pipeline = $pipelines->save($this->org->admin, ['names' => ['ru' => 'Волонтёры мероприятия']], 'ru', [
        ['names' => ['ru' => 'Заявка']], ['names' => ['ru' => 'Подтверждён'], 'kind' => 'won'],
    ]);

    expect($pipeline->code)->toBe('volontery_meropriiatiia')
        ->and($pipeline->name_ro)->toBe('Волонтёры мероприятия')
        ->and($pipeline->stages()->pluck('kind')->all())->toBe(['open', 'won'])
        ->and(fn () => $pipelines->save($this->org->headA, ['names' => ['ro' => 'X']], 'ro', [['names' => ['ro' => 'A']]]))->toThrow(AuthorizationException::class)
        ->and(fn () => $pipelines->save($this->org->admin, ['names' => ['ro' => 'Fără etape']], 'ro', []))->toThrow(CrmRuleViolation::class)
        ->and(fn () => $pipelines->save($this->org->admin, ['names' => ['ro' => 'Doar final']], 'ro', [['names' => ['ro' => 'Gata'], 'kind' => 'won']]))
        ->toThrow(CrmRuleViolation::class);
});

it('switches a stage with leads off instead of removing it', function () {
    $lead = $this->leads->create($this->org->admin, $this->crm->pipeline, $this->crm->supporter());
    $this->leads->move($this->org->admin, $lead, $this->crm->stage('contacted'));
    $keep = fn (string $code): array => ['id' => $this->crm->stage($code)->id, 'names' => ['ro' => $code], 'kind' => $this->crm->stage($code)->kind];

    app(ManagePipelines::class)->save($this->org->admin, ['names' => ['ro' => 'Aderare']], 'ro',
        [$keep('new'), $keep('active'), $keep('lost')], $this->crm->pipeline);

    expect($this->crm->stage('contacted')->is_active)->toBeFalse()
        ->and(PipelineStage::query()->where('code', 'meeting')->exists())->toBeFalse();
});

it('lets the operator of a region create leads there and nowhere else', function () {
    $o = $this->org;
    $inCentru = $this->crm->supporter($o->centru);
    $inBalti = $this->crm->supporter($o->baltiTerritory);

    $lead = $this->leads->create($this->crm->operator, $this->crm->pipeline, $inCentru, ['responsible_person_id' => $o->a1->person_id]);

    expect($lead)->status->toBe('open')->territory_id->toBe($o->centru->id)->stage_id->toBe($this->crm->stage('new')->id)
        ->and(fn () => $this->leads->create($this->crm->operator, $this->crm->pipeline, $inBalti))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->leads->create($o->a1, $this->crm->pipeline, $this->crm->supporter($o->centru)))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->leads->create($this->crm->operator, $this->crm->pipeline, $inCentru))->toThrow(CrmRuleViolation::class);
});

it('shows each reader the leads of their scope — in the list and in the stage counters', function () {
    $o = $this->org;
    $authz = app(AuthorizationService::class);
    $centru = $this->leads->create($o->admin, $this->crm->pipeline, $this->crm->supporter($o->centru, $o->branchA), ['responsible_person_id' => $o->a1->person_id]);
    $botanica = $this->leads->create($o->admin, $this->crm->pipeline, $this->crm->supporter($o->botanica, $o->branchB));
    $balti = $this->leads->create($o->admin, $this->crm->pipeline, $this->crm->supporter($o->baltiTerritory, $o->balti));
    $authz->forget();

    $ids = fn ($user) => $authz->scopeQuery($user, 'pipelines.read', Lead::query())->pluck('id')->all();
    $countInFirstStage = fn ($user) => $authz->scopeQuery($user, 'pipelines.read', Lead::query())->where('stage_id', $this->crm->stage('new')->id)->count();

    expect($ids($this->crm->operator))->toEqualCanonicalizing([$centru->id, $botanica->id])
        ->and($ids($o->headA))->toBe([$centru->id])
        ->and($ids($o->baltiHead))->toBe([$balti->id])
        ->and($ids($o->a1))->toBe([$centru->id])       // responsible — by relation
        ->and($ids($o->a2))->toBe([])
        ->and($countInFirstStage($this->crm->operator))->toBe(2)
        ->and($countInFirstStage($o->orgHead))->toBe(3)
        ->and($authz->can($this->crm->operator, 'pipelines.read', $balti))->toBeFalse();
});

it('records every move: history, journal, domain event; the goal stage closes the lead', function () {
    Event::fake([LeadStageChanged::class]);
    $o = $this->org;
    $lead = $this->leads->create($o->headA, $this->crm->pipeline, $this->crm->supporter($o->centru, $o->branchA), ['responsible_person_id' => $o->a1->person_id]);
    app(AuthorizationService::class)->forget();

    $this->leads->move($o->a1, $lead, $this->crm->stage('contacted'), 'A răspuns la telefon');
    $this->leads->move($o->a1, $lead->fresh(), $this->crm->stage('active'));

    expect($lead->fresh())->status->toBe('won')->closed_at->not->toBeNull()
        ->and(LeadStageHistory::query()->where('lead_id', $lead->id)->pluck('to_status')->all())->toBe(['open', 'open', 'won'])
        ->and(LeadStageHistory::query()->where('lead_id', $lead->id)->pluck('note')->all())->toBe([null, 'A răspuns la telefon', null])
        ->and(JournalEntry::query()->where('event_type', 'crm.lead.stage_changed')->where('subject_id', (string) $lead->id)->count())->toBe(2)
        ->and(fn () => $this->leads->move($o->a1, $lead->fresh(), $this->crm->stage('meeting')))->toThrow(CrmRuleViolation::class)
        ->and(fn () => $this->leads->move($o->a2, $lead->fresh(), $this->crm->stage('meeting')))->toThrow(AuthorizationException::class);
    Event::assertDispatchedTimes(LeadStageChanged::class, 3);
});

it('loses a lead only with a reason from the catalog and can return it to the stage it left', function () {
    $o = $this->org;
    $lead = $this->leads->create($o->headA, $this->crm->pipeline, $this->crm->supporter($o->centru, $o->branchA));
    $this->leads->move($o->headA, $lead, $this->crm->stage('meeting'));

    expect(fn () => $this->leads->move($o->headA, $lead->fresh(), $this->crm->stage('lost')))->toThrow(CrmRuleViolation::class)
        ->and(fn () => $this->leads->lose($o->headA, $lead->fresh(), 'because'))->toThrow(CrmRuleViolation::class);

    $this->leads->lose($o->headA, $lead->fresh(), 'no_contact', 'Trei apeluri fără răspuns');
    expect($lead->fresh())->status->toBe('lost')->lost_reason_code->toBe('no_contact')->stage_id->toBe($this->crm->stage('lost')->id);

    $this->leads->reopen($o->headA, $lead->fresh(), 'A sunat înapoi');
    expect($lead->fresh())->status->toBe('open')->lost_reason_code->toBeNull()->closed_at->toBeNull()->stage_id->toBe($this->crm->stage('meeting')->id);
});

it('freezes a lead until a date and returns it to work by itself, telling the responsible', function () {
    Notification::fake();
    $o = $this->org;
    $lead = $this->leads->create($o->headA, $this->crm->pipeline, $this->crm->supporter($o->centru, $o->branchA), ['responsible_person_id' => $o->a1->person_id]);

    expect(fn () => $this->leads->freeze($o->headA, $lead, today()))->toThrow(CrmRuleViolation::class);
    $this->leads->freeze($o->headA, $lead, today()->addDays(10), 'Plecat până la toamnă');

    Artisan::call('crm:unfreeze-leads');
    expect($lead->fresh()->status)->toBe('frozen')
        ->and(fn () => $this->leads->move($o->headA, $lead->fresh(), $this->crm->stage('contacted')))->toThrow(CrmRuleViolation::class);

    $this->travel(10)->days();
    Artisan::call('crm:unfreeze-leads');

    $entry = JournalEntry::query()->where('event_type', 'crm.lead.unfrozen')->sole();
    expect($lead->fresh())->status->toBe('open')->frozen_until->toBeNull()
        ->and($entry->actor_type->value)->toBe('system');
    Notification::assertSentTo($o->a1, CrmNotice::class, fn (CrmNotice $notice): bool => $notice->kind === CrmNotice::LEAD_UNFROZEN);
});

it('accepts only an active user as the responsible and notifies them (Д-15)', function () {
    Notification::fake();
    $o = $this->org;
    $lead = $this->leads->create($o->headA, $this->crm->pipeline, $this->crm->supporter($o->centru, $o->branchA));

    $this->leads->assign($o->headA, $lead, $o->a1->person_id);
    Notification::assertSentTo($o->a1, CrmNotice::class);

    app(SetUserActive::class)($o->admin, $o->a2, false);
    expect(fn () => $this->leads->assign($o->headA, $lead->fresh(), $o->a2->person_id))->toThrow(CrmRuleViolation::class)
        ->and(fn () => $this->leads->assign($o->headA, $lead->fresh(), $this->crm->supporter()->id))->toThrow(CrmRuleViolation::class)
        ->and(fn () => $this->leads->assign($o->a1, $lead->fresh(), $o->a1->person_id))->toThrow(AuthorizationException::class);
});
