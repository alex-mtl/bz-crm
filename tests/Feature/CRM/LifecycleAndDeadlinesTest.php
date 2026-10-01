<?php

use App\Domain\Access\Admission\AcceptInvitation;
use App\Domain\Access\Admission\InviteUser;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\CRM\Actions\ManageAppeals;
use App\Domain\CRM\Actions\ManageLeads;
use App\Domain\CRM\Actions\ManagePipelines;
use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CRM\Models\Appeal;
use App\Domain\CRM\Models\Lead;
use App\Domain\CRM\Models\Pipeline;
use App\Domain\CRM\Notifications\CrmNotice;
use App\Domain\Geo\Actions\ManageTerritories;
use App\Domain\Identity\Actions\SetUserActive;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\Invitation;
use App\Domain\People\Actions\ManagePeople;
use App\Domain\People\Actions\RegisterCandidate;
use App\Domain\People\Models\PersonStatusHistory;
use App\Filament\Resources\Appeals\Pages\ViewAppeal;
use App\Filament\Resources\People\Pages\ViewPerson;
use App\Filament\Resources\Pipelines\Pages\ListPipelines;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\CrmFixture;

/*
 * Д-22 — what the prototype did and the specification lacked: the life cycle of a person and access for an
 * existing card; automatic enrolment with the responsible found by territory; deadlines of stages and appeals.
 */

beforeEach(function () {
    $this->crm = CrmFixture::build();
    $this->org = $this->crm->org;
});

function autoPipeline($test, array $extra = []): Pipeline
{
    return app(ManagePipelines::class)->save($test->org->admin, [
        'code' => 'auto', 'names' => ['ro' => 'Automată'], 'auto_enroll_types' => ['candidate'], 'auto_assign_by_territory' => true, ...$extra,
    ], 'ro', [
        ['code' => 'new', 'names' => ['ro' => 'Nou'], 'sla_hours' => 24],
        ['code' => 'talk', 'names' => ['ro' => 'Discuție'], 'sla_hours' => 72],
        ['code' => 'in', 'names' => ['ro' => 'Membru'], 'kind' => 'won', 'sla_hours' => 5],
    ]);
}

it('has the life-cycle stages of a person as catalog values', function () {
    $types = CatalogItem::query()->ofCatalog('person_types')->selectable()->pluck('code')->all();
    $person = $this->crm->supporter();

    expect($types)->toContain('candidate', 'newcomer', 'activist', 'member', 'volunteer', 'employee', 'partner', 'inactive');

    foreach (['newcomer', 'activist', 'member', 'inactive'] as $type) {
        app(ManagePeople::class)->update($this->org->admin, $person->fresh(), ['person_type' => $type]);
    }
    expect(PersonStatusHistory::query()->where('person_id', $person->id)->where('kind', 'type')->pluck('new_value')->all())
        ->toBe(['supporter', 'newcomer', 'activist', 'member', 'inactive']);
});

it('gives an account to an existing card: the card keeps its history and changes its type', function () {
    Notification::fake();
    $o = $this->org;
    $person = $this->crm->supporter($o->centru, $o->branchA, ['email' => 'viitor@example.org']);
    $lead = app(ManageLeads::class)->create($o->admin, $this->crm->pipeline, $person);

    ['invitation' => $invitation, 'token' => $token] = app(InviteUser::class)($o->headA, 'viitor@example.org', ['employee'], personType: 'employee', forPerson: $person);
    $user = app(AcceptInvitation::class)($token, 'Alt', 'Nume', 'Parola-Foarte-Lunga-2026!', 'ro');

    expect($invitation->person_id)->toBe($person->id)
        ->and($user->person_id)->toBe($person->id)
        ->and($person->fresh())->person_type->toBe('employee')->first_name->toBe($person->first_name)
        ->and(Lead::query()->find($lead->id)->person_id)->toBe($person->id)
        ->and(PersonStatusHistory::query()->where('person_id', $person->id)->latest('id')->first())->old_value->toBe('supporter')->new_value->toBe('employee')
        ->and(JournalEntry::query()->where('event_type', 'admission.invitation.sent')->latest('id')->first()->new_values['person_id'])->toBe($person->id);
});

it('refuses access for a card that has an account or is archived, and to anyone who may not invite', function () {
    $o = $this->org;
    $archived = $this->crm->supporter($o->centru, $o->branchA);
    app(ManagePeople::class)->archive($o->orgHead, $archived);
    $invite = app(InviteUser::class);

    expect(fn () => $invite($o->headA, 'x@example.org', ['employee'], forPerson: $o->a1->person))->toThrow(IdentityRuleViolation::class)
        ->and(fn () => $invite($o->headA, 'y@example.org', ['employee'], forPerson: $archived->fresh()))->toThrow(IdentityRuleViolation::class)
        ->and(fn () => $invite($o->a1, 'z@example.org', ['employee'], forPerson: $this->crm->supporter()))->toThrow(AuthorizationException::class)
        ->and(Invitation::query()->count())->toBe(0);
});

it('offers "grant access" on the card of a person without an account', function () {
    Notification::fake();
    $o = $this->org;
    $person = $this->crm->supporter($o->centru, $o->branchA, ['email' => 'card@example.org']);
    $this->actingAs($o->headA);

    Livewire::test(ViewPerson::class, ['record' => $person->id])
        ->callAction('grantAccess', data: ['email' => 'card@example.org', 'person_type' => 'volunteer', 'roles' => ['volunteer']])
        ->assertHasNoActionErrors();
    Livewire::test(ViewPerson::class, ['record' => $o->a1->person_id])->assertActionHidden('grantAccess');

    expect(Invitation::query()->sole())->person_id->toBe($person->id)->person_type->toBe('volunteer');
});

it('takes a new candidate into the pipeline by itself and gives the lead to the responsible of the territory', function () {
    Notification::fake();
    $o = $this->org;
    $pipeline = autoPipeline($this);
    app(ManageTerritories::class)->assignResponsible($o->admin, $o->centru, $o->a1->person);
    app(ManageTerritories::class)->assignResponsible($o->admin, $o->chisinau, $o->regionHead->person);
    $create = fn (string $type, $territory) => app(ManagePeople::class)->create($o->admin, ['first_name' => 'Nou', 'person_type' => $type, 'territory_id' => $territory?->id]);
    $leadOf = fn ($person) => Lead::query()->where('pipeline_id', $pipeline->id)->where('person_id', $person->id)->first();

    $inCentru = $create('candidate', $o->centru);
    $inBotanica = $create('candidate', $o->botanica);       // nobody answers for Botanica — the level above does
    $inBalti = $create('candidate', $o->baltiTerritory);    // nobody up the tree at all
    $supporter = $create('supporter', $o->centru);          // not a type the pipeline takes in

    expect($leadOf($inCentru))->responsible_person_id->toBe($o->a1->person_id)->stage_id->toBe($pipeline->firstStage()->id)->territory_id->toBe($o->centru->id)
        ->and($leadOf($inBotanica)->responsible_person_id)->toBe($o->regionHead->person_id)
        ->and($leadOf($inBalti))->not->toBeNull()->responsible_person_id->toBeNull()
        ->and($leadOf($supporter))->toBeNull()
        ->and(JournalEntry::query()->where('event_type', 'crm.lead.created')->where('subject_id', (string) $leadOf($inCentru)->id)->sole()->new_values['automatic'])->toBeTrue();
    Notification::assertSentTo($o->a1, CrmNotice::class, fn (CrmNotice $notice): bool => $notice->kind === CrmNotice::LEAD_ASSIGNED);

    // A candidate from a public form is enrolled as well; a responsible without an active account is skipped (Д-15).
    app(SetUserActive::class)($o->admin, $o->a1, false);
    $second = $create('candidate', $o->centru);
    $fromForm = app(RegisterCandidate::class)('Din', 'Formular', 'form@example.org', null, 'site');
    expect($leadOf($second)->responsible_person_id)->toBe($o->regionHead->person_id)
        ->and($leadOf($fromForm))->not->toBeNull();
});

it('uses the territory rule for a lead created by hand when nobody is chosen, and never overrides a choice', function () {
    $o = $this->org;
    $pipeline = autoPipeline($this);
    app(ManageTerritories::class)->assignResponsible($o->admin, $o->centru, $o->a1->person);
    $leads = app(ManageLeads::class);

    $auto = $leads->create($o->headA, $pipeline, $this->crm->supporter($o->centru, $o->branchA));
    $chosen = $leads->create($o->headA, $pipeline, $this->crm->supporter($o->centru, $o->branchA), ['responsible_person_id' => $o->a2->person_id]);
    $plain = $leads->create($o->headA, $this->crm->pipeline, $this->crm->supporter($o->centru, $o->branchA));

    expect($auto->responsible_person_id)->toBe($o->a1->person_id)
        ->and($chosen->responsible_person_id)->toBe($o->a2->person_id)
        ->and($plain->responsible_person_id)->toBeNull();
});

it('counts the deadline of a stage from the moment the lead entered it; "overdue" is computed', function () {
    $o = $this->org;
    $pipeline = autoPipeline($this);
    $leads = app(ManageLeads::class);
    $stage = fn (string $code) => $pipeline->stages()->where('code', $code)->sole();
    $lead = $leads->create($o->admin, $pipeline, $this->crm->supporter($o->centru));

    expect((int) round(now()->diffInHours($lead->stage_due_at)))->toBe(24)
        ->and($lead->isStageOverdue())->toBeFalse();

    $this->travel(25)->hours();
    expect($lead->fresh()->isStageOverdue())->toBeTrue()
        ->and(Lead::query()->stageOverdue()->pluck('id')->all())->toBe([$lead->id]);

    // The next stage starts its own clock; a frozen lead has none; returning to work restarts it.
    $leads->move($o->admin, $lead->fresh(), $stage('talk'));
    expect($lead->fresh()->isStageOverdue())->toBeFalse()
        ->and((int) round(now()->diffInHours($lead->fresh()->stage_due_at)))->toBe(72);

    $leads->freeze($o->admin, $lead->fresh(), today()->addDays(30));
    $this->travel(10)->days();
    expect($lead->fresh())->stage_due_at->toBeNull()->isStageOverdue()->toBeFalse();

    $leads->reopen($o->admin, $lead->fresh());
    expect((int) round(now()->diffInHours($lead->fresh()->stage_due_at)))->toBe(72);

    // The goal stage has no deadline, whatever was entered for it.
    $leads->move($o->admin, $lead->fresh(), $stage('in'));
    expect($lead->fresh()->stage_due_at)->toBeNull()->and($stage('in')->sla_hours)->toBeNull();
});

it('sets the deadlines of an appeal by its priority and type', function () {
    $o = $this->org;
    $appeals = app(ManageAppeals::class);
    $hours = fn (Appeal $appeal): int => (int) round(now()->diffInHours($appeal->first_response_due_at));
    $days = fn (Appeal $appeal): int => (int) round(now()->diffInDays($appeal->due_at));

    $normal = $appeals->register($o->headA, ['title' => 'Obișnuită', 'type_code' => 'complaint']);
    $high = $appeals->register($o->headA, ['title' => 'Înaltă', 'type_code' => 'complaint', 'priority_code' => 'high']);
    $urgent = $appeals->register($o->headA, ['title' => 'Urgentă', 'type_code' => 'complaint', 'priority_code' => 'urgent']);

    // Normal: the type decides the resolution (complaint — 10 days). High and urgent shorten it.
    expect([$normal->priority_code, $hours($normal), $days($normal)])->toBe(['normal', 48, 10])
        ->and([$hours($high), $days($high)])->toBe([8, 3])
        ->and([$hours($urgent), $days($urgent)])->toBe([2, 1])
        ->and(fn () => $appeals->register($o->headA, ['title' => 'X', 'type_code' => 'complaint', 'priority_code' => 'whenever']))->toThrow(CrmRuleViolation::class);
});

it('marks the first response when the appeal is taken into work; before that "unanswered in time" is computed', function () {
    $o = $this->org;
    $appeals = app(ManageAppeals::class);
    $appeal = $appeals->register($o->headA, ['title' => 'Cerere', 'type_code' => 'question', 'priority_code' => 'urgent']);

    $this->travel(3)->hours();
    expect($appeal->fresh()->isFirstResponseOverdue())->toBeTrue()
        ->and(Appeal::query()->firstResponseOverdue()->count())->toBe(1);

    $appeals->changeStatus($o->headA, $appeal->fresh(), 'in_progress');
    expect($appeal->fresh())->first_responded_at->not->toBeNull()->isFirstResponseOverdue()->toBeFalse();

    // Changing the priority takes the right to manage the appeal and is journaled.
    $appeals->prioritize($o->headA, $appeal->fresh(), 'low');
    expect($appeal->fresh()->priority_code)->toBe('low')
        ->and(journalCount('crm.appeal.prioritized'))->toBe(1)
        ->and(fn () => $appeals->prioritize($o->a1, $appeal->fresh(), 'high'))->toThrow(AuthorizationException::class);
});

it('sets up automatic enrolment and stage deadlines in the pipeline constructor, and the priority on the appeal page', function () {
    $o = $this->org;
    $this->actingAs($o->admin);

    Livewire::test(ListPipelines::class)->callAction('create', data: [
        'name_ro' => 'Candidați', 'auto_enroll_types' => ['candidate'], 'auto_assign_by_territory' => true,
        'stages' => [['name_ro' => 'Nou', 'kind' => 'open', 'sla_hours' => 12], ['name_ro' => 'Gata', 'kind' => 'won']],
    ])->assertHasNoActionErrors();
    $pipeline = Pipeline::query()->where('code', 'candidati')->sole();

    $appeal = app(ManageAppeals::class)->register($o->admin, ['title' => 'Cerere', 'type_code' => 'question']);
    Livewire::test(ViewAppeal::class, ['record' => $appeal->id])->callAction('prioritize', data: ['priority_code' => 'high'])->assertHasNoActionErrors();

    expect($pipeline)->auto_enroll_types->toBe(['candidate'])->auto_assign_by_territory->toBeTrue()
        ->and($pipeline->firstStage()->sla_hours)->toBe(12)
        ->and($appeal->fresh()->priority_code)->toBe('high');
});
