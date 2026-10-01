<?php

use App\Domain\Access\Actions\SetRolePermissions;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\RolePermission;
use App\Domain\CRM\Actions\ManageAppeals;
use App\Domain\CRM\Actions\ManageLeads;
use App\Domain\CRM\Actions\RecordInteraction;
use App\Domain\CRM\Imports\PeopleImport;
use App\Domain\CRM\Models\Appeal;
use App\Domain\CRM\Models\DuplicateCandidate;
use App\Domain\CRM\Models\ExportBatch;
use App\Domain\CRM\Models\Interaction;
use App\Domain\CRM\Models\Lead;
use App\Domain\CRM\Models\Pipeline;
use App\Domain\CRM\Models\Segment;
use App\Domain\CustomObjects\CustomFields;
use App\Domain\CustomObjects\Models\CustomField;
use App\Domain\People\Models\Person;
use App\Filament\Pages\FieldRules;
use App\Filament\Pages\LeadBoard;
use App\Filament\Resources\Appeals\Pages\ViewAppeal;
use App\Filament\Resources\CustomFields\Pages\ListCustomFields;
use App\Filament\Resources\Duplicates\Pages\ListDuplicateCandidates;
use App\Filament\Resources\Imports\Pages\ViewImportBatch;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Resources\People\Pages\CreatePerson;
use App\Filament\Resources\People\Pages\ListPeople;
use App\Filament\Resources\People\Pages\ViewPerson;
use App\Filament\Resources\Pipelines\Pages\ListPipelines;
use App\Filament\Resources\Segments\Pages\ListSegments;
use App\Support\Spreadsheet\Spreadsheet;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CrmFixture;

/*
 * The CRM in the panel: pages are a thin layer over the domain actions, and show only what the viewer may see.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->crm = CrmFixture::build();
    $this->org = $this->crm->org;
});

it('creates a person card with custom fields from the form', function () {
    app(CustomFields::class)->saveDefinition($this->org->admin, CustomField::PERSON, ['names' => ['ro' => 'Carnet'], 'code' => 'card_no', 'field_type' => 'number'], 'ro');
    $this->actingAs($this->org->headA);

    Livewire::test(CreatePerson::class)
        ->fillForm(['first_name' => 'Vera', 'last_name' => 'Nume-Unic-În-Test', 'person_type' => 'supporter', 'phone' => '069 700 700',
            'territory_id' => $this->org->centru->id, 'custom' => ['card_no' => '7']])
        ->call('create')
        ->assertHasNoFormErrors();

    $person = Person::query()->where('last_name', 'Nume-Unic-În-Test')->sole();
    expect($person)->responsible_unit_id->toBe($this->org->branchA->id)->phone->toBe('37369700700')
        ->and(app(CustomFields::class)->values(CustomField::PERSON, $person->id))->toBe(['card_no' => '7']);
});

it('runs the CRM actions of a person card and shows the feed', function () {
    $o = $this->org;
    $person = $this->crm->supporter($o->centru, $o->branchA);
    $friend = $this->crm->supporter($o->centru, $o->branchA);
    $this->actingAs($o->headA);

    Livewire::test(ViewPerson::class, ['record' => $person->id])
        ->callAction('editCard', data: ['first_name' => 'Nume nou', 'person_type' => 'partner'])
        ->callAction('addInteraction', data: ['kind_code' => 'meeting', 'summary' => 'La sediu'])
        ->callAction('addRelation', data: ['relation_code' => 'friend', 'related_person_id' => $friend->id])
        ->callAction('createLead', data: ['pipeline_id' => $this->crm->pipeline->id, 'responsible_person_id' => $o->a1->person_id])
        ->callAction('registerAppeal', data: ['title' => 'Cerere', 'type_code' => 'question'])
        ->assertHasNoActionErrors()
        ->assertActionHidden('archive');   // a branch head does not archive cards

    expect($person->fresh())->first_name->toBe('Nume nou')->person_type->toBe('partner')
        ->and(Interaction::query()->where('person_id', $person->id)->count())->toBe(1)
        ->and(Lead::query()->where('person_id', $person->id)->sole()->responsible_person_id)->toBe($o->a1->person_id)
        ->and(Appeal::query()->where('person_id', $person->id)->count())->toBe(1);

    $this->get('/admin/people/'.$person->id)->assertOk()->assertSee('La sediu')->assertSee($friend->last_name)->assertSee(__('admin.crm.timeline'));
});

it('hides the CRM actions and the feed from an employee who only reads the card', function () {
    $o = $this->org;
    $person = $this->crm->supporter($o->centru, $o->branchA);
    app(RecordInteraction::class)($o->headA, $person, 'call', ['summary' => 'Confidențial pentru b1']);
    $this->actingAs($o->b1);

    Livewire::test(ViewPerson::class, ['record' => $person->id])
        ->assertActionHidden('editCard')->assertActionHidden('addInteraction')->assertActionHidden('addRelation')->assertActionHidden('createLead');
    $this->get('/admin/people/'.$person->id)->assertOk()->assertDontSee('Confidențial pentru b1');
});

it('moves, freezes and loses a lead from its card; the board counts only what the viewer sees', function () {
    $o = $this->org;
    $leads = app(ManageLeads::class);
    $lead = $leads->create($o->admin, $this->crm->pipeline, $this->crm->supporter($o->centru, $o->branchA));
    $leads->create($o->admin, $this->crm->pipeline, $this->crm->supporter($o->botanica, $o->branchB));
    $leads->create($o->admin, $this->crm->pipeline, $this->crm->supporter($o->baltiTerritory, $o->balti));

    $this->actingAs($this->crm->operator);
    $counts = fn () => collect(Livewire::test(LeadBoard::class)->instance()->getColumnsProperty())->mapWithKeys(fn (array $c): array => [$c['stage']->code => $c['count']])->all();
    expect($counts()['new'])->toBe(2);   // Bălți is outside the operator's region

    Livewire::test(LeadBoard::class)->callAction('move', arguments: ['lead' => $lead->id, 'stage' => $this->crm->stage('contacted')->id])->assertHasNoActionErrors();
    expect($counts())->toMatchArray(['new' => 1, 'contacted' => 1]);

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('freeze', data: ['until' => today()->addDays(5)->toDateString(), 'note' => 'Revine luni'])
        ->callAction('reopen', data: [])
        ->callAction('lose', data: ['reason' => 'no_contact'])
        ->assertHasNoActionErrors();
    expect($lead->fresh())->status->toBe('lost')->stage_id->toBe($this->crm->stage('lost')->id);

    Livewire::test(ListLeads::class)->assertCanSeeTableRecords(Lead::query()->where('territory_id', '!=', $o->baltiTerritory->id)->get())
        ->assertCanNotSeeTableRecords(Lead::query()->where('territory_id', $o->baltiTerritory->id)->get());
    $this->actingAs($o->orgHead);
    expect($counts()['new'])->toBe(2);
});

it('takes an appeal through its life from the card', function () {
    $o = $this->org;
    $appeal = app(ManageAppeals::class)->register($o->headA, ['title' => 'Cerere', 'type_code' => 'question', 'responsible_person_id' => $o->a1->person_id]);
    app(AuthorizationService::class)->forget();
    $this->actingAs($o->a1);

    Livewire::test(ViewAppeal::class, ['record' => $appeal->id])
        ->callAction('start')
        ->callAction('done', data: ['resolution' => 'Am răspuns'])
        ->assertHasNoActionErrors()
        ->assertActionHidden('reopen')->assertActionHidden('assign');

    expect($appeal->fresh())->status->toBe('done')->resolution->toBe('Am răspuns');
    $this->actingAs($o->a2)->get('/admin/appeals/'.$appeal->id)->assertNotFound();
});

it('saves a segment, filters the people list by it and exports what is filtered', function () {
    $o = $this->org;
    $in = $this->crm->supporter($o->centru, $o->branchA);
    $out = $this->crm->supporter($o->baltiTerritory, $o->balti);
    $this->actingAs($o->orgHead);

    Livewire::test(ListSegments::class)
        ->callAction('create', data: ['name' => 'Chișinău', 'visibility' => 'shared', 'criteria' => ['person_types' => ['supporter'], 'territory_id' => $o->chisinau->id]])
        ->assertHasNoActionErrors();
    $segment = Segment::query()->sole();

    Livewire::test(ListPeople::class)->filterTable('segment', $segment->id)
        ->assertCanSeeTableRecords([$in])->assertCanNotSeeTableRecords([$out])
        ->callAction('export', data: ['format' => 'csv'])
        ->assertRedirect();

    $batch = ExportBatch::query()->sole();
    expect($batch)->row_count->toBe(1)->filters->toBe($segment->criteria);
    $this->get(route('exports.download', $batch))->assertOk()->assertDownload();
    $this->actingAs($o->admin)->get(route('exports.download', $batch))->assertForbidden();
});

it('merges or dismisses a pair from the duplicates queue', function () {
    $o = $this->org;
    $first = $this->crm->supporter($o->centru, $o->branchA, ['phone' => '069 111 000']);
    $second = $this->crm->supporter($o->centru, $o->branchA, ['phone' => '069 111 000']);
    $this->crm->supporter($o->centru, $o->branchA, ['email' => 'x@example.org']);
    $this->crm->supporter($o->centru, $o->branchA, ['email' => 'x@example.org']);
    [$toMerge, $toDismiss] = DuplicateCandidate::query()->orderBy('id')->get()->all();

    // A branch head reviews the queue but cannot merge (catalog §5): the action is not offered.
    $this->actingAs($o->headA);
    Livewire::test(ListDuplicateCandidates::class)->assertCanSeeTableRecords([$toMerge, $toDismiss])
        ->assertTableActionHidden('merge', $toMerge)
        ->callTableAction('dismiss', $toDismiss);

    $this->actingAs($this->crm->hr);
    Livewire::test(ListDuplicateCandidates::class)->callTableAction('merge', $toMerge, data: ['keep' => 'b'])->assertHasNoTableActionErrors();

    expect($toDismiss->fresh()->status)->toBe('dismissed')
        ->and($first->fresh()->duplicate_of_person_id)->toBe($second->id);
});

it('builds a pipeline and a custom field in the panel', function () {
    $this->actingAs($this->org->admin);

    Livewire::test(ListPipelines::class)->callAction('create', data: [
        'name_ro' => 'Voluntari', 'stages' => [['name_ro' => 'Cerere', 'kind' => 'open'], ['name_ro' => 'Confirmat', 'kind' => 'won']],
    ])->assertHasNoActionErrors();
    Livewire::test(ListCustomFields::class)->callAction('create', data: [
        'name_ro' => 'Interes', 'field_type' => 'select', 'options' => [['ro' => 'Ecologie'], ['ro' => 'Educație']],
    ])->assertHasNoActionErrors();

    expect(Pipeline::query()->where('code', 'voluntari')->sole()->stages()->pluck('kind')->all())->toBe(['open', 'won'])
        ->and(CustomField::query()->where('code', 'interes')->sole()->options)->toHaveCount(2);
});

it('changes which roles see which field groups — and nothing else in the role', function () {
    $volunteer = userWithRoles('volunteer');
    $role = Role::query()->where('code', 'volunteer')->sole();
    $before = $role->permissions()->count();
    $authz = app(AuthorizationService::class);
    expect($authz->can($volunteer, 'people.fields.contacts.read'))->toBeFalse();

    $this->actingAs($this->org->admin);
    Livewire::test(FieldRules::class)->set('matrix.'.$role->id.'.people__fields__contacts__read', true)->call('save');

    expect($authz->can($volunteer, 'people.fields.contacts.read'))->toBeTrue()
        ->and($role->permissions()->count())->toBe($before + 1)
        ->and(journalCount('access.role.field_rules_changed'))->toBe(1);
});

it('keeps the data layer of a grant when a role is saved in the constructor', function () {
    $role = Role::query()->where('code', 'employee')->sole();
    $layer = fn (string $code): ?string => RolePermission::query()->where('role_id', $role->id)->where('permission_code', $code)->value('data_scope');
    $allowed = $role->permissions()->pluck('permission_code')->all();
    expect($layer('tasks.read'))->toBe('related');

    app(SetRolePermissions::class)($this->org->admin, $role, array_values(array_diff($allowed, ['pipelines.read'])));
    app(SetRolePermissions::class)($this->org->admin, $role->fresh(), $allowed);

    // Kept grants keep their layer; a grant added back starts with the layer of the starter setup.
    expect($layer('tasks.read'))->toBe('related')
        ->and($layer('pipelines.read'))->toBe('related')
        ->and($layer('people.read'))->toBeNull();
});

it('shows the verdict of an import and starts it from the page', function () {
    $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
    Spreadsheet::write($path, 'csv', ['first_name', 'last_name'], [['Unu', 'A'], ['Doi', 'B']]);
    $batch = app(PeopleImport::class)->upload($this->crm->hr, $path, 'oameni.csv');
    $this->actingAs($this->crm->hr);

    Livewire::test(ViewImportBatch::class, ['record' => $batch->id])->assertSee('Unu A')->callAction('commit')->assertHasNoActionErrors();

    expect($batch->fresh()->status)->toBe('completed')
        ->and(Person::query()->where('import_batch_id', $batch->id)->count())->toBe(2);
    $this->get(route('imports.template'))->assertOk()->assertDownload();
    $this->actingAs($this->org->headA)->get('/admin/imports/'.$batch->id)->assertNotFound();
});
