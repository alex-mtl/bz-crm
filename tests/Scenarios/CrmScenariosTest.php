<?php

use App\Domain\Access\Actions\AssignRole;
use App\Domain\Access\Actions\CreateRole;
use App\Domain\Access\Actions\SetRolePermissions;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Exceptions\PermissionEscalation;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Scopes\ScopeType;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\CRM\Actions\ManageAppeals;
use App\Domain\CRM\Actions\ManageLeads;
use App\Domain\CRM\Actions\ManageRelations;
use App\Domain\CRM\Actions\MergePeople;
use App\Domain\CRM\Duplicates;
use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CRM\Exports\Exports;
use App\Domain\CRM\Imports\PeopleImport;
use App\Domain\CRM\Models\Appeal;
use App\Domain\CRM\Models\DuplicateCandidate;
use App\Domain\CRM\Models\ImportBatch;
use App\Domain\CRM\Models\Interaction;
use App\Domain\CRM\Models\Lead;
use App\Domain\CRM\Models\PersonMerge;
use App\Domain\CRM\Models\PersonRelation;
use App\Domain\CRM\Models\Segment;
use App\Domain\CRM\Segments;
use App\Domain\Identity\Models\Invitation;
use App\Domain\People\Models\Person;
use App\Domain\People\Models\PersonStatusHistory;
use App\Domain\Tasks\Actions\ManageTasks;
use App\Domain\Tasks\Models\Task;
use App\Filament\Pages\LeadBoard;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\People\Pages\ListPeople;
use App\Support\Spreadsheet\Spreadsheet;
use Database\Seeders\Demo\CrmDemoSeeder as Crm;
use Database\Seeders\Demo\Personas;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
 * IMPLEMENTATION-PLAN 3.3 — the CRM on the demo world (docs/demo/README.md § CRM).
 */

function joiningLeadOf(string $key): Lead
{
    return Lead::query()->where('pipeline_id', Crm::pipeline(Crm::JOINING)->id)->where('person_id', Crm::person($key)->id)->sole();
}

/**
 * @return list<int>
 */
function leadsSeenBy(string $persona): array
{
    app(AuthorizationService::class)->forget();

    return app(AuthorizationService::class)->scopeQuery(Personas::user($persona), 'pipelines.read', Lead::query())->pluck('id')->all();
}

it('shows the operator of Chișinău the leads of her region and not the others — in the list, the search and the board', function () {
    $stela = Personas::user('inbox_operator');
    $inRegion = [joiningLeadOf('doina')->id, joiningLeadOf('petru')->id, joiningLeadOf('andrei')->id];
    $outside = [joiningLeadOf('viorica')->id, joiningLeadOf('tudor')->id, joiningLeadOf('larisa')->id];

    expect(leadsSeenBy('inbox_operator'))->toContain(...$inRegion)->not->toContain(...$outside);

    $this->actingAs($stela);
    Livewire::test(ListLeads::class)
        ->assertCanSeeTableRecords(Lead::query()->whereKey($inRegion)->get())
        ->assertCanNotSeeTableRecords(Lead::query()->whereKey($outside)->get())
        ->searchTable('Mocanu')->assertCountTableRecords(0)      // a lead of Bălți is not found by name either
        ->searchTable('Vrabie')->assertCountTableRecords(2);     // Doina: joining + event volunteers
    $this->get('/admin/leads/'.joiningLeadOf('viorica')->id)->assertNotFound();
    $this->get('/admin/leads/'.joiningLeadOf('doina')->id)->assertOk();
});

it('counts in every stage of the board only the leads the viewer sees', function () {
    $counts = function (string $persona): array {
        $this->actingAs(Personas::user($persona));
        app(AuthorizationService::class)->forget();

        return collect(Livewire::test(LeadBoard::class, ['pipelineId' => Crm::pipeline(Crm::JOINING)->id])->instance()->getColumnsProperty())
            ->mapWithKeys(fn (array $column): array => [$column['stage']->code => $column['count']])->all();
    };

    // "new": Petru, Larisa and the three candidates enrolled automatically; the one from the public form has no
    // territory, so only the organization-wide reader counts it. "contacted": Lilia (Centru) and Viorica (Bălți, frozen).
    expect($counts('org_head'))->toMatchArray(['new' => 5, 'contacted' => 2, 'meeting' => 1, 'training' => 1, 'mentor' => 1, 'active' => 1, 'lost' => 2])
        ->and($counts('inbox_operator'))->toMatchArray(['new' => 3, 'contacted' => 1, 'training' => 0, 'lost' => 1])
        ->and($counts('balti_head'))->toMatchArray(['new' => 0, 'contacted' => 1, 'meeting' => 0, 'lost' => 1]);
});

it('shows an employee only the leads they are responsible for', function () {
    expect(leadsSeenBy('branch_a_employee_1'))->toEqualCanonicalizing([
        joiningLeadOf('doina')->id,
        Lead::query()->where('responsible_person_id', Personas::user('branch_a_employee_1')->person_id)->whereKeyNot(joiningLeadOf('doina')->id)->sole()->id,
    ])
        ->and(leadsSeenBy('branch_b_employee_3'))->toBe([])
        ->and(leadsSeenBy('volunteer'))->toBe([])
        ->and(fn () => app(ManageLeads::class)->move(Personas::user('branch_a_employee_3'), joiningLeadOf('doina'), Crm::stage(Crm::JOINING, 'training')))
        ->toThrow(AuthorizationException::class);
});

it('has leads on every stage, lost for different reasons, frozen with a date, with the history of moves', function () {
    $joining = Lead::query()->where('pipeline_id', Crm::pipeline(Crm::JOINING)->id)->with('stage')->get();

    expect($joining->pluck('stage.code')->unique()->values()->all())->toEqualCanonicalizing(['new', 'contacted', 'meeting', 'training', 'mentor', 'active', 'lost'])
        ->and(Lead::query()->where('status', 'lost')->pluck('lost_reason_code')->all())->toEqualCanonicalizing(['no_time', 'moved_away', 'other'])
        ->and(joiningLeadOf('viorica'))->status->toBe('frozen')->frozen_until->not->toBeNull()
        ->and(joiningLeadOf('viorica')->frozen_until->isFuture())->toBeTrue()
        ->and(joiningLeadOf('andrei'))->status->toBe('won')->closed_at->not->toBeNull()
        ->and(joiningLeadOf('galina')->history()->count())->toBe(5)
        ->and(JournalEntry::query()->where('event_type', 'crm.lead.stage_changed')->where('subject_id', (string) joiningLeadOf('galina')->id)->count())->toBe(4);
});

it('returned the lead whose freeze date had passed to work by itself and told the responsible', function () {
    $lead = joiningLeadOf('larisa');
    $returned = JournalEntry::query()->where('event_type', 'crm.lead.unfrozen')->where('subject_id', (string) $lead->id)->sole();

    expect($lead)->status->toBe('open')->frozen_until->toBeNull()
        ->and($lead->history()->pluck('to_status')->all())->toBe(['open', 'frozen', 'open'])
        ->and($returned->actor_type->value)->toBe('system')
        ->and(Personas::user('central_employee')->notifications()->where('data->kind', 'lead_unfrozen')->exists())->toBeTrue();
});

it('keeps the references of tasks, leads, appeals, interactions and relations when two cards are merged', function () {
    $doina = Crm::person('doina');
    $second = Person::query()->where('last_name', 'Vrabii')->sole();
    $task = app(ManageTasks::class)->create(Personas::user('branch_a_head'), ['title' => 'Sunați-o pe Doina', 'type_code' => 'call', 'subject_person_id' => $doina->id]);
    $before = [
        'leads' => Lead::query()->where('person_id', $doina->id)->pluck('id')->all(),
        'appeals' => Appeal::query()->where('person_id', $doina->id)->pluck('id')->all(),
        'interactions' => Interaction::query()->where('person_id', $doina->id)->pluck('id')->all(),
        'relations' => PersonRelation::query()->where('person_id', $doina->id)->orWhere('related_person_id', $doina->id)->count(),
    ];
    expect($before['leads'])->toHaveCount(2)->and($before['appeals'])->toHaveCount(1)->and($before['interactions'])->toHaveCount(3)->and($before['relations'])->toBe(2);

    // The newer card stays, the older one — with all the history — is merged into it.
    $merge = app(MergePeople::class)(Personas::user('hr'), $second, $doina);

    expect(Lead::query()->whereKey($before['leads'])->pluck('person_id')->unique()->all())->toBe([$second->id])
        ->and(Appeal::query()->whereKey($before['appeals'])->value('person_id'))->toBe($second->id)
        ->and(Interaction::query()->whereKey($before['interactions'])->pluck('person_id')->unique()->all())->toBe([$second->id])
        ->and(Task::query()->find($task->id)->subject_person_id)->toBe($second->id)
        ->and(PersonRelation::query()->where('person_id', $second->id)->orWhere('related_person_id', $second->id)->count())->toBe(2)
        ->and($doina->fresh())->duplicate_of_person_id->toBe($second->id)->isArchived()->toBeTrue()
        ->and($merge->snapshot['last_name'])->toBe('Vrabie')
        ->and(Person::query()->whereKey($doina->id)->exists())->toBeTrue();
});

it('shows the merge already done in the demo world: nothing of the second card was lost', function () {
    $tudor = Crm::person('tudor');
    $merge = PersonMerge::query()->where('kept_person_id', $tudor->id)->sole();
    $second = Person::query()->findOrFail($merge->merged_person_id);

    expect($second)->duplicate_of_person_id->toBe($tudor->id)->isArchived()->toBeTrue()
        ->and(Lead::query()->where('person_id', $tudor->id)->count())->toBe(2)                 // his own + the one of the second card
        ->and(Interaction::query()->where('person_id', $tudor->id)->where('kind_code', 'call')->exists())->toBeTrue()
        ->and(Lead::query()->where('person_id', $second->id)->exists())->toBeFalse()
        ->and($merge->merged_by_user_id)->toBe(Personas::user('hr')->id)
        ->and(JournalEntry::query()->where('event_type', 'crm.people.merged')->where('subject_id', (string) $tudor->id)->exists())->toBeTrue();
});

it('queues the deliberate duplicates: a pair by phone, a pair by e-mail, a triple by name; a dismissed pair stays dismissed', function () {
    $open = DuplicateCandidate::query()->where('status', 'open')->get();
    $serghei = Person::query()->where('first_name', 'Serghei')->where('last_name', 'Ivanov')->pluck('id');
    $queue = fn (string $persona) => app(Duplicates::class)->queueFor(Personas::user($persona))->where('status', 'open')->count();

    expect($open)->toHaveCount(5)
        ->and($open->firstWhere('person_a_id', Crm::person('doina')->id)->reasons)->toBe(['phone'])
        ->and($open->firstWhere('person_a_id', Crm::person('andrei')->id)->reasons)->toBe(['email'])
        ->and($open->filter(fn (DuplicateCandidate $pair): bool => $serghei->contains($pair->person_a_id) && $serghei->contains($pair->person_b_id)))->toHaveCount(3)
        ->and(DuplicateCandidate::query()->where('status', 'dismissed')->where('person_a_id', Crm::person('elena_b')->id)->exists())->toBeTrue()
        // A reviewer sees a pair only when both cards are in scope: Ana — Centru, Nicolae — Bălți, HR — all.
        ->and($queue('hr'))->toBe(5)
        ->and($queue('branch_a_head'))->toBe(1)
        ->and($queue('balti_head'))->toBe(3)
        ->and($queue('branch_a_employee_1'))->toBe(0);

    app(Duplicates::class)->scanAll();
    expect(DuplicateCandidate::query()->where('status', 'dismissed')->count())->toBe(1);
});

it('forbids export without the explicit right, and limits a scoped export to the scope', function () {
    $exports = app(Exports::class);

    foreach (['inbox_operator', 'branch_a_head', 'hr', 'branch_a_employee_1'] as $persona) {
        expect(fn () => $exports->request(Personas::user($persona), Exports::LEADS, 'csv'))->toThrow(AuthorizationException::class);
    }
    expect(fn () => $exports->request(Personas::user('branch_a_head'), Exports::PEOPLE, 'csv'))->toThrow(AuthorizationException::class)
        ->and(fn () => $exports->request(Personas::user('inbox_operator'), Exports::APPEALS, 'csv'))->toThrow(AuthorizationException::class);

    // Give the operator the right to export leads — still only inside her region.
    $admin = Personas::user('super_admin');
    $role = app(CreateRole::class)($admin, 'regional_export', ['ro' => 'Export regional'], 'ro');
    app(SetRolePermissions::class)($admin, $role, ['leads.export']);
    app(AssignRole::class)($admin, Personas::user('inbox_operator'), $role, scope: ScopeType::Territory, scopeId: Personas::territory('chisinau')->id);

    $regional = $exports->request(Personas::user('inbox_operator'), Exports::LEADS, 'csv', ['pipeline_id' => Crm::pipeline(Crm::JOINING)->id]);
    $all = $exports->request(Personas::user('org_head'), Exports::LEADS, 'csv', ['pipeline_id' => Crm::pipeline(Crm::JOINING)->id]);
    $names = collect(iterator_to_array(Spreadsheet::read($exports->file(Personas::user('inbox_operator'), $regional), 'csv'), false))->skip(1)->pluck(4)->all();

    expect($regional->row_count)->toBe(8)
        ->and($all->row_count)->toBe(13)
        ->and($names)->toContain('Doina Vrabie', 'Andrei Cazacu')->not->toContain('Viorica Mocanu', 'Tudor Bostan', 'Larisa Guzun');
});

it('journaled the export of the demo world with who, what and how much', function () {
    $entry = JournalEntry::query()->where('event_type', 'crm.leads.exported')->oldest('id')->firstOrFail();

    expect($entry->new_values)->toMatchArray(['rows' => 10, 'format' => 'xlsx', 'user_id' => Personas::user('org_head')->id]);
});

it('gives a report for an import with errors and creates no partial garbage', function () {
    $import = app(PeopleImport::class);
    $blocked = ImportBatch::query()->where('original_name', 'people-import-sample.csv')->sole();

    expect($blocked)->status->toBe('validated')->totals->toMatchArray(['total' => 8, 'valid' => 2, 'errors' => 4, 'duplicates' => 2])
        ->and(Person::query()->where('import_batch_id', $blocked->id)->count())->toBe(0)
        ->and(fn () => $import->commit(Personas::user('hr'), $blocked))->toThrow(CrmRuleViolation::class)
        ->and(Person::query()->where('last_name', 'like', 'Demo-Munteanu%')->count())->toBe(0);

    [, $rows] = $import->report(Personas::user('hr'), $blocked);
    $problems = collect($rows)->mapWithKeys(fn (array $row): array => [$row[0] => $row[2]]);
    expect($problems->keys()->all())->toBe([4, 5, 6, 7, 8, 9])
        ->and($problems[4])->toContain(__('people.errors.first_name_required'))
        ->and($problems[6])->toContain('chisinau/sectorul-inexistent')
        ->and($problems[8])->toContain('2')                                        // the same e-mail as in row 2 of the file
        ->and($problems[9])->toContain((string) Crm::person('doina')->id);          // already in the registry

    $this->actingAs(Personas::user('hr'))->get(route('imports.report', $blocked))->assertOk()->assertDownload();
    $this->actingAs(Personas::user('branch_a_head'))->get(route('imports.report', $blocked))->assertForbidden();
});

it('imported the clean file completely, and can roll it back', function () {
    $clean = ImportBatch::query()->where('original_name', 'people-import-clean.csv')->sole();
    $people = Person::query()->where('import_batch_id', $clean->id)->get();

    expect($clean)->status->toBe('completed')
        ->and($people)->toHaveCount(3)
        ->and($people->pluck('territory_id')->unique()->all())->toBe([Personas::territory('cahul')->id])
        ->and($people->pluck('responsible_unit_id')->unique()->all())->toBe([Personas::unit('north_south')->id]);

    expect(app(PeopleImport::class)->rollback(Personas::user('hr'), $clean))->toBe(['removed' => 3, 'archived' => 0])
        ->and(Person::query()->where('import_batch_id', $clean->id)->count())->toBe(0);
});

it('has appeals in every status, linked to people and tasks; "overdue" is computed', function () {
    $overdue = Appeal::query()->overdue()->sole();
    $withTask = Appeal::query()->where('title', 'Transport la policlinică')->sole();
    $ids = fn (string $persona) => app(AuthorizationService::class)->scopeQuery(Personas::user($persona), 'appeals.read', Appeal::query())->pluck('id')->all();

    expect(Appeal::query()->distinct()->pluck('status')->all())->toEqualCanonicalizing(Appeal::STATUSES)
        ->and(Appeal::query()->whereNull('person_id')->count())->toBe(0)
        ->and($overdue)->title->toBe('Gropi pe str. Independenței')->status->toBe('in_progress')
        ->and($withTask->tasks()->sole()->subject_person_id)->toBe(Crm::person('galina')->id)
        ->and(Appeal::query()->where('status', 'rejected')->sole()->resolution)->not->toBeEmpty()
        // The operator of Chișinău does not see the appeal of Bălți; Sergiu sees only the one he is responsible for.
        ->and($ids('inbox_operator'))->not->toContain(Appeal::query()->where('type_code', 'website_request')->sole()->id)
        ->and($ids('branch_a_employee_3'))->toBe([$withTask->id])
        ->and(fn () => app(ManageAppeals::class)->changeStatus(Personas::user('branch_a_employee_1'), $withTask, 'done'))->toThrow(AuthorizationException::class);
});

it('computes a shared segment for the reader: supporters of Chișinău older than 30', function () {
    $segment = Segment::query()->where('visibility', 'shared')->where('name', 'like', 'Susținători din Chișinău%')->sole();
    $names = fn (string $persona) => app(Segments::class)->people(Personas::user($persona), $segment)->get()->map->fullName()->sort()->values()->all();

    // Doina 45, Galina 62, Elena 51 — and not Petru (28), not Andrei (he became a member), not the partner, not Bălți.
    expect($names('org_head'))->toBe(['Doina Vrabie', 'Elena Rotaru', 'Galina Sîrbu'])
        ->and($names('inbox_operator'))->toBe($names('org_head'))
        ->and(fn () => $names('volunteer'))->toThrow(AuthorizationException::class)
        ->and(app(Segments::class)->visibleTo(Personas::user('branch_a_employee_2'))->count())->toBe(0)
        ->and(app(Segments::class)->visibleTo(Personas::user('branch_a_employee_1'))->sole()->visibility)->toBe('personal');
});

it('has relations between people, read from both sides', function () {
    $relations = app(ManageRelations::class);
    $hr = Personas::user('hr');

    expect($relations->of($hr, Crm::person('doina'))->pluck('type')->all())->toEqualCanonicalizing(['A invitat', 'Coleg(ă)'])
        ->and($relations->of($hr, Crm::person('petru'))->sole())->type->toBe('Invitat(ă) de')
        ->and($relations->of($hr, Crm::person('petru'))->sole()['other']->id)->toBe(Crm::person('doina')->id);
});

it('does not let anyone grant a role with people.export they do not hold themselves (Д-17)', function () {
    $ana = Personas::user('branch_a_head');
    $before = journalCount('access.escalation.denied');
    expect(app(AuthorizationService::class)->can($ana, 'people.export'))->toBeFalse();

    try {
        app(AssignRole::class)($ana, Personas::user('branch_a_employee_1'), Role::query()->where('code', 'org_head')->sole());
        $this->fail('The escalation was not refused.');
    } catch (PermissionEscalation $refusal) {
        expect($refusal->getMessage())->toContain('people.export');
    }
    expect(journalCount('access.escalation.denied'))->toBe($before + 1);
});

it('finds a supporter by name in the people list and opens the card with the CRM blocks', function () {
    $doina = Crm::person('doina');
    $this->actingAs(Personas::user('inbox_operator'));

    Livewire::test(ListPeople::class)->searchTable('Vrabie')
        ->assertCanSeeTableRecords([$doina])
        ->assertCountTableRecords(1);
    $this->get('/admin/people?search=Vrabie')->assertOk()->assertSee('Doina');
    $this->get('/admin/people/'.$doina->id)->assertOk()
        ->assertSee(__('admin.crm.card'))->assertSee(__('admin.crm.timeline'))->assertSee('Întâlnire la sediu');
});

it('takes new candidates into the joining pipeline by itself and finds the responsible by territory (Д-22)', function () {
    $leadOf = fn (string $lastName): Lead => Lead::query()->where('pipeline_id', Crm::pipeline(Crm::JOINING)->id)
        ->whereHas('person', fn ($person) => $person->where('last_name', $lastName))->sole();

    // Centru has its own responsible (Ana). Buiucani has none — the lead goes up to the responsible for Chișinău (Mihai).
    // The request from the public form has no territory: enrolled, but nobody is responsible yet.
    expect($leadOf('Demo-Olaru'))->responsible_person_id->toBe(Personas::user('branch_a_head')->person_id)->stage_id->toBe(Crm::stage(Crm::JOINING, 'new')->id)
        ->and($leadOf('Demo-Pascal')->responsible_person_id)->toBe(Personas::user('chisinau_head')->person_id)
        ->and($leadOf('Demo-Negară'))->responsible_person_id->toBeNull()->territory_id->toBeNull()
        // The operator took Petru's request without choosing anybody: the same rule gave it to Ana.
        ->and(joiningLeadOf('petru')->responsible_person_id)->toBe(Personas::user('branch_a_head')->person_id)
        ->and(JournalEntry::query()->where('event_type', 'crm.lead.created')->where('subject_id', (string) $leadOf('Demo-Olaru')->id)->sole()->new_values['automatic'])->toBeTrue()
        ->and(Personas::user('chisinau_head')->notifications()->where('data->kind', 'lead_assigned')->exists())->toBeTrue();

    // Supporters are not a type the pipeline takes in: no lead appeared for the people of the clean import.
    expect(Lead::query()->whereHas('person', fn ($person) => $person->where('last_name', 'Demo-Cebanu'))->exists())->toBeFalse();
});

it('shows which leads have been in a stage for too long (Д-22)', function () {
    $late = Lead::query()->stageOverdue()->with('person')->get()->map(fn (Lead $lead): string => (string) $lead->person->last_name)->all();

    // Petru: three weeks in "new" with a day to react. Larisa returned from the freeze today, the form request came today.
    expect($late)->toContain('Lungu', 'Vrabie', 'Zaharia', 'Demo-Olaru', 'Demo-Pascal')
        ->not->toContain('Guzun', 'Demo-Negară', 'Mocanu', 'Cazacu')
        ->and(joiningLeadOf('viorica')->stage_due_at)->toBeNull()           // frozen: the clock is stopped
        ->and(joiningLeadOf('galina')->stage_due_at)->toBeNull();           // a stage without a deadline

    $this->actingAs(Personas::user('inbox_operator'))->get('/admin/lead-board?pipeline='.Crm::pipeline(Crm::JOINING)->id)
        ->assertOk()->assertSee(__('admin.leads.stage_overdue'));
});

it('sets the deadlines of appeals by priority; an appeal nobody took in time is seen at once (Д-22)', function () {
    $pothole = Appeal::query()->where('title', 'Gropi pe str. Independenței')->sole();
    $light = Appeal::query()->where('title', 'like', 'Iluminat stradal%')->sole();

    // High priority: three days for the resolution instead of the ten of a complaint; eight hours for the first response.
    expect($pothole)->priority_code->toBe('high')->first_responded_at->not->toBeNull()
        ->and((int) round($pothole->created_at->diffInDays($pothole->due_at)))->toBe(3)
        ->and($light)->priority_code->toBe('high')->status->toBe('new')
        ->and($light->isFirstResponseOverdue())->toBeTrue()
        ->and($light->isOverdue())->toBeFalse()
        ->and(Appeal::query()->firstResponseOverdue()->pluck('id')->all())->toBe([$light->id]);
});

it('has a person who went through the life cycle, and an invitation that gives an account to an existing card (Д-22)', function () {
    $andrei = Crm::person('andrei');
    $petru = Crm::person('petru');
    $invitation = Invitation::query()->where('person_id', $petru->id)->sole();

    expect($andrei->person_type)->toBe('member')
        ->and(PersonStatusHistory::query()->where('person_id', $andrei->id)->where('kind', 'type')->pluck('new_value')->all())->toBe(['supporter', 'member'])
        ->and($invitation)->person_type->toBe('volunteer')->accepted_at->toBeNull()
        ->and($invitation->invited_by_user_id)->toBe(Personas::user('branch_a_head')->id)
        ->and($petru->user)->toBeNull();
});
