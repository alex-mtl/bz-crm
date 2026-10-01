<?php

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\CRM\Actions\ManageAppeals;
use App\Domain\CRM\Actions\ManageLeads;
use App\Domain\CRM\Actions\RecordInteraction;
use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CRM\Exports\BuildExport;
use App\Domain\CRM\Exports\Exports;
use App\Domain\CRM\Imports\PeopleImport;
use App\Domain\CRM\Models\DuplicateCandidate;
use App\Domain\CRM\Models\ImportBatch;
use App\Domain\CRM\Models\ImportRow;
use App\Domain\CRM\Models\Lead;
use App\Domain\CRM\Notifications\CrmNotice;
use App\Domain\CustomObjects\CustomFields;
use App\Domain\CustomObjects\Models\CustomField;
use App\Domain\People\Models\Person;
use App\Domain\Profiles\Actions\ManageProfile;
use App\Domain\Profiles\Models\PersonProfile;
use App\Support\Spreadsheet\Spreadsheet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CrmFixture;

/*
 * ТЗ §68 — import with preview, validation, dry run, error report, duplicate detection and rollback;
 * export by an explicit right, only of what the person sees.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->crm = CrmFixture::build();
    $this->org = $this->crm->org;
    $this->import = app(PeopleImport::class);
});

/**
 * @param  list<list<string|null>>  $rows
 */
function importFile(array $rows, string $format = 'csv', ?array $header = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'imp').'.'.$format;
    Spreadsheet::write($path, $format, $header ?? ['first_name', 'last_name', 'email', 'phone', 'person_type', 'territory_code', 'birth_date', 'gender'], $rows);

    return $path;
}

function peopleCount(): int
{
    return Person::query()->whereNotNull('import_batch_id')->count();
}

it('validates a file as a dry run, then imports every row in the queue', function () {
    $o = $this->org;
    app(CustomFields::class)->saveDefinition($o->admin, CustomField::PERSON, ['names' => ['ro' => 'Carnet'], 'code' => 'card_no', 'field_type' => 'number'], 'ro');
    $file = importFile([
        ['Ioana', 'Rusu', 'ioana@example.org', '069 111 111', 'supporter', 'chisinau/centru', '1990-05-17', 'f', '15'],
        ['Petru', 'Lupu', null, '069 222 222', null, null, null, null, null],
    ], 'csv', ['Prenume', 'Nume', 'E-mail', 'Telefon', 'Tip', 'Teritoriu', 'birth_date', 'gender', 'cf_card_no']);

    $batch = $this->import->upload($this->crm->hr, $file, 'oameni.csv', ['responsible_unit_id' => $o->branchA->id, 'person_type' => 'partner']);

    expect($batch)->status->toBe('validated')->totals->toBe(['total' => 2, 'valid' => 2, 'errors' => 0, 'duplicates' => 0])
        ->and(peopleCount())->toBe(0);

    $this->import->commit($this->crm->hr, $batch);

    $ioana = Person::query()->where('email', 'ioana@example.org')->sole();
    $petru = Person::query()->where('phone', '37369222222')->sole();
    expect($batch->fresh())->status->toBe('completed')
        ->and($batch->fresh()->totals['imported'])->toBe(2)
        ->and($ioana)->person_type->toBe('supporter')->territory_id->toBe($o->centru->id)->responsible_unit_id->toBe($o->branchA->id)->source_code->toBe('import')
        ->and($petru->person_type)->toBe('partner')
        ->and(PersonProfile::query()->find($ioana->id))->gender->toBe('female')
        ->and(app(CustomFields::class)->values(CustomField::PERSON, $ioana->id))->toBe(['card_no' => '15'])
        ->and($batch->rows()->pluck('status')->unique()->all())->toBe(['imported'])
        ->and(journalCount('crm.import.completed'))->toBe(1)
        ->and(JournalEntry::query()->where('event_type', 'people.person.created')->where('subject_id', (string) $ioana->id)->exists())->toBeTrue();
});

it('reads XLSX the same way', function () {
    $batch = $this->import->upload($this->crm->hr, importFile([['Elena', 'Popa', 'elena@example.org', null, 'supporter', null, '1985-01-02', 'female']], 'xlsx'), 'oameni.xlsx');

    expect($batch->totals['valid'])->toBe(1)
        ->and($batch->rows()->sole()->data['birth_date'])->toBe('1985-01-02');
});

it('does not import a file with errors at all and says what to fix', function () {
    $batch = $this->import->upload($this->crm->hr, importFile([
        ['Bun', 'Rând', 'bun@example.org', null, 'supporter', null, null, null],
        [null, 'Fără prenume', null, null, 'supporter', null, null, null],
        ['Email', 'Greșit', 'not-an-email', null, 'supporter', null, null, null],
        ['Tip', 'Greșit', null, null, 'alien', null, null, null],
        ['Teritoriu', 'Greșit', null, null, 'supporter', 'nowhere', '2090-01-01', 'x'],
    ]), 'cu-erori.csv');

    expect($batch->totals)->toBe(['total' => 5, 'valid' => 1, 'errors' => 4, 'duplicates' => 0])
        ->and(fn () => $this->import->commit($this->crm->hr, $batch))->toThrow(CrmRuleViolation::class)
        ->and(peopleCount())->toBe(0);

    [$header, $rows] = $this->import->report($this->crm->hr, $batch);
    expect($rows)->toHaveCount(4)
        ->and(array_column($rows, 0))->toBe([3, 4, 5, 6])
        ->and($rows[3][2])->toContain('nowhere')->toContain(__('crm.import.errors.invalid_birth_date'))->toContain(__('crm.import.errors.invalid_gender'))
        ->and($header[0])->toBe(__('crm.import.report.row'));
});

it('finds duplicates inside the file and against the registry, and skips them unless told otherwise', function () {
    $existing = $this->crm->supporter(null, null, ['phone' => '069 333 333']);
    $rows = [
        ['Nou', 'Unu', 'nou@example.org', '069 444 444', 'supporter', null, null, null],
        ['Nou', 'Doi', 'nou@example.org', null, 'supporter', null, null, null],          // same e-mail as row 2
        ['Vechi', 'Trei', null, '+373 69 333 333', 'supporter', null, null, null],        // already in the registry
    ];

    $batch = $this->import->upload($this->crm->hr, importFile($rows), 'dubluri.csv');
    expect($batch->totals)->toBe(['total' => 3, 'valid' => 1, 'errors' => 0, 'duplicates' => 2])
        ->and($batch->rows()->where('row_number', 4)->sole()->duplicate_of_person_id)->toBe($existing->id);

    $this->import->commit($this->crm->hr, $batch);
    expect(peopleCount())->toBe(1)
        ->and($batch->rows()->pluck('status')->all())->toBe(['imported', 'skipped', 'skipped']);

    // "Create anyway": the rows are imported and the pairs go to the duplicates queue for a human decision.
    $again = $this->import->upload($this->crm->hr, importFile([$rows[2]]), 'dubluri-2.csv', ['duplicates' => 'create']);
    $this->import->commit($this->crm->hr, $again);
    expect(peopleCount())->toBe(2)
        ->and(DuplicateCandidate::query()->where('person_a_id', $existing->id)->exists())->toBeTrue();
});

it('leaves nothing behind when the import fails half-way', function () {
    $batch = $this->import->upload($this->org->admin, importFile([
        ['Unu', 'A', null, null, 'supporter', null, null, null],
        ['Doi', 'B', null, null, 'supporter', null, null, null],
    ]), 'cu-pipeline.csv', ['pipeline_id' => $this->crm->pipeline->id]);
    // The pipeline is switched off after validation: creating the lead of the first row fails.
    $this->crm->pipeline->update(['is_active' => false]);

    $this->import->commit($this->org->admin, $batch);

    expect($batch->fresh())->status->toBe('failed')->failure->not->toBeNull()
        ->and(peopleCount())->toBe(0)
        ->and(Lead::query()->count())->toBe(0)
        ->and(ImportRow::query()->where('status', 'imported')->count())->toBe(0)
        ->and(journalCount('crm.import.failed'))->toBe(1);
});

it('creates leads together with people and rolls a finished import back, sparing cards that got their own history', function () {
    $o = $this->org;
    $batch = $this->import->upload($o->admin, importFile([
        ['Unu', 'A', null, null, 'supporter', 'chisinau/centru', null, null],
        ['Doi', 'B', null, null, 'supporter', 'chisinau/centru', null, null],
    ]), 'cu-pipeline.csv', ['pipeline_id' => $this->crm->pipeline->id]);
    $this->import->commit($o->admin, $batch);
    [$first, $second] = Person::query()->where('import_batch_id', $batch->id)->orderBy('id')->get()->all();
    expect(Lead::query()->where('import_batch_id', $batch->id)->count())->toBe(2);

    app(RecordInteraction::class)($o->admin, $second, 'call');
    $result = $this->import->rollback($o->admin, $batch->fresh());

    expect($result)->toBe(['removed' => 1, 'archived' => 1])
        ->and(Person::query()->find($first->id))->toBeNull()
        ->and($second->fresh()->isArchived())->toBeTrue()
        ->and($batch->fresh()->status)->toBe('rolled_back')
        ->and(fn () => $this->import->rollback($o->admin, $batch->fresh()))->toThrow(CrmRuleViolation::class);
});

it('allows import only with the explicit right and only inside the importer\'s scope', function () {
    $o = $this->org;
    $file = fn () => importFile([['Unu', 'A', null, null, 'supporter', null, null, null]]);

    expect(fn () => $this->import->upload($o->headA, $file(), 'x.csv'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->import->upload($this->crm->hr, $file(), 'x.csv', ['pipeline_id' => $this->crm->pipeline->id]))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->import->upload($this->crm->hr, $file(), 'x.pdf'))->toThrow(CrmRuleViolation::class)
        ->and(ImportBatch::query()->count())->toBe(0);
});

it('exports only with the explicit right, only visible records and only visible fields', function () {
    $o = $this->org;
    $exports = app(Exports::class);
    $person = $this->crm->supporter($o->centru, $o->branchA, ['email' => 'vizibil@example.org', 'phone' => '069 555 555']);
    app(ManageProfile::class)->updateFor($o->admin, $person, [], [['contact_type' => 'telegram', 'value' => '@vizibil', 'visibility' => 'all']]);

    expect(fn () => $exports->request($o->headA, Exports::PEOPLE, 'csv'))->toThrow(AuthorizationException::class)
        ->and(fn () => $exports->request($this->crm->hr, Exports::LEADS, 'csv'))->toThrow(AuthorizationException::class);

    $batch = $exports->request($o->orgHead, Exports::PEOPLE, 'csv', ['person_types' => ['supporter']]);
    $rows = iterator_to_array(Spreadsheet::read($exports->file($o->orgHead, $batch), 'csv'), false);

    expect($batch)->status->toBe('ready')->row_count->toBe(1)
        ->and($rows[1])->toContain('vizibil@example.org', 'telegram: @vizibil')
        ->and(JournalEntry::query()->where('event_type', 'people.exported')->sole()->new_values['rows'])->toBe(1)
        ->and(fn () => $exports->file($o->admin, $batch))->toThrow(AuthorizationException::class);
});

it('builds a large export in the queue and tells the person when it is ready', function () {
    config(['crm.export_sync_limit' => 1]);
    $o = $this->org;
    $this->crm->supporter($o->centru);
    $this->crm->supporter($o->centru);
    Queue::fake();
    Notification::fake();

    $batch = app(Exports::class)->request($o->orgHead, Exports::PEOPLE, 'xlsx', ['person_types' => ['supporter']]);

    expect($batch->status)->toBe('queued');
    Queue::assertPushed(BuildExport::class);

    (new BuildExport($batch->id))->handle(app(Exports::class));
    expect($batch->fresh())->status->toBe('ready')->row_count->toBe(2);
    Notification::assertSentTo($o->orgHead, CrmNotice::class, fn (CrmNotice $notice): bool => $notice->kind === CrmNotice::EXPORT_READY);
});

it('exports leads and appeals within the scope of the reader', function () {
    $o = $this->org;
    app(AuthorizationService::class)->forget();
    app(ManageLeads::class)->create($o->admin, $this->crm->pipeline, $this->crm->supporter($o->centru, $o->branchA));
    app(ManageAppeals::class)->register($o->admin, ['title' => 'Cerere', 'type_code' => 'question', 'territory_id' => $o->centru->id]);

    $leads = app(Exports::class)->request($o->orgHead, Exports::LEADS, 'csv', ['pipeline_id' => $this->crm->pipeline->id]);
    $appeals = app(Exports::class)->request($o->orgHead, Exports::APPEALS, 'xlsx');

    expect($leads->row_count)->toBe(1)->and($appeals->row_count)->toBe(1)
        ->and(journalCount('crm.leads.exported') + journalCount('crm.appeals.exported'))->toBe(2);
});
