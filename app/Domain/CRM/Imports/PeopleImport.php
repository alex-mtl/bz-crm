<?php

declare(strict_types=1);

namespace App\Domain\CRM\Imports;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\CRM\Actions\ManageLeads;
use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CRM\Models\ImportBatch;
use App\Domain\CRM\Models\ImportRow;
use App\Domain\CRM\Models\Lead;
use App\Domain\CRM\Models\Pipeline;
use App\Domain\CustomObjects\CustomFields;
use App\Domain\CustomObjects\Exceptions\CustomFieldViolation;
use App\Domain\CustomObjects\Models\CustomField;
use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\ManagePeople;
use App\Domain\People\Exceptions\PeopleRuleViolation;
use App\Domain\People\Models\Person;
use App\Domain\People\PossibleDuplicateFinder;
use App\Domain\Profiles\Actions\ManageProfile;
use App\Support\Spreadsheet\Spreadsheet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Import of people from CSV / XLSX (ФО §6.9.1, ТЗ §68):
 *
 *   upload → validate (this is the preview and the dry run: every row gets a verdict, nothing is created)
 *          → commit (in a queue, in one transaction: either every accepted row is created, or none)
 *          → optionally roll back a finished import.
 *
 * A file with errors is not imported at all — the error report says what to fix (no partial garbage).
 * Possible duplicates are found with the same finder as everywhere else and are skipped unless told otherwise.
 */
final readonly class PeopleImport
{
    public const string KIND = 'people';

    public const int MAX_ROWS = 20000;

    public const array COLUMNS = ['first_name', 'last_name', 'email', 'phone', 'person_type', 'preferred_locale', 'territory_code', 'birth_date', 'gender', 'source_code'];

    /** Header spellings people actually use → column. */
    private const array ALIASES = [
        'prenume' => 'first_name', 'имя' => 'first_name', 'name' => 'first_name',
        'nume' => 'last_name', 'фамилия' => 'last_name', 'surname' => 'last_name',
        'e-mail' => 'email', 'mail' => 'email', 'почта' => 'email',
        'telefon' => 'phone', 'телефон' => 'phone', 'tel' => 'phone',
        'tip' => 'person_type', 'тип' => 'person_type', 'type' => 'person_type',
        'limba' => 'preferred_locale', 'язык' => 'preferred_locale', 'locale' => 'preferred_locale', 'language' => 'preferred_locale',
        'teritoriu' => 'territory_code', 'территория' => 'territory_code', 'territory' => 'territory_code',
        'data_nasterii' => 'birth_date', 'дата_рождения' => 'birth_date', 'birthday' => 'birth_date',
        'gen' => 'gender', 'пол' => 'gender', 'sex' => 'gender',
        'sursa' => 'source_code', 'источник' => 'source_code', 'source' => 'source_code',
    ];

    public function __construct(
        private AuthorizationService $authorization,
        private ManagePeople $people,
        private ManageProfile $profiles,
        private ManageLeads $leads,
        private CustomFields $customFields,
        private PossibleDuplicateFinder $finder,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array{person_type?: string|null, territory_id?: int|null, responsible_unit_id?: int|null, source_code?: string|null,
     *               pipeline_id?: int|null, duplicates?: string}  $options  defaults for rows that do not say otherwise
     */
    public function upload(User $actor, string $sourcePath, string $originalName, array $options = []): ImportBatch
    {
        $this->authorization->authorize($actor, 'people.import');
        $format = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($format, Spreadsheet::FORMATS, true)) {
            throw CrmRuleViolation::because('import_unsupported_format');
        }
        if (isset($options['pipeline_id'])) {
            // Creating leads on import is a CRM import; the leads themselves need the right to create leads.
            $this->authorization->authorize($actor, 'crm.import');
            $this->authorization->authorize($actor, 'leads.create');
        }

        $path = 'imports/'.Str::uuid().'.'.$format;
        Storage::disk('local')->put($path, (string) file_get_contents($sourcePath));

        $batch = ImportBatch::query()->create([
            'kind' => self::KIND,
            'original_name' => mb_substr($originalName, 0, 255),
            'path' => $path,
            'format' => $format,
            'status' => ImportBatch::UPLOADED,
            'options' => [
                'person_type' => $options['person_type'] ?? 'supporter',
                'territory_id' => $options['territory_id'] ?? null,
                'responsible_unit_id' => $options['responsible_unit_id'] ?? null,
                'source_code' => $options['source_code'] ?? 'import',
                'pipeline_id' => $options['pipeline_id'] ?? null,
                'duplicates' => ($options['duplicates'] ?? 'skip') === 'create' ? 'create' : 'skip',
            ],
            'created_by_user_id' => $actor->id,
        ]);
        $this->journal->record('crm.import.uploaded', $batch, [], ['file' => $batch->original_name, 'format' => $format]);

        return $this->validate($actor, $batch);
    }

    /**
     * The dry run: reads the file and gives every row a verdict. Creates nothing but the rows of the report.
     */
    public function validate(User $actor, ImportBatch $batch): ImportBatch
    {
        $this->ensureMayHandle($actor, $batch);
        if (! in_array($batch->status, [ImportBatch::UPLOADED, ImportBatch::VALIDATED, ImportBatch::FAILED], true)) {
            throw CrmRuleViolation::because('import_wrong_state');
        }

        $options = $batch->options ?? [];
        $columns = null;
        $rows = [];
        /** @var array{email: array<string, int>, phone: array<string, int>} $seen */
        $seen = ['email' => [], 'phone' => []];
        $territories = [];
        $number = 0;

        foreach (Spreadsheet::read(Storage::disk('local')->path($batch->path), $batch->format) as $cells) {
            if ($columns === null) {
                $columns = $this->mapHeader($cells);
                if (! in_array('first_name', $columns, true)) {
                    throw CrmRuleViolation::because('import_no_first_name_column');
                }

                continue;
            }
            if (array_filter($cells, fn (?string $cell): bool => $cell !== null) === []) {
                continue;
            }
            if (++$number > self::MAX_ROWS) {
                throw CrmRuleViolation::because('import_too_many_rows', ['limit' => self::MAX_ROWS]);
            }

            $data = [];
            foreach ($columns as $index => $column) {
                if ($column !== null) {
                    $data[$column] = $cells[$index] ?? null;
                }
            }
            $rows[] = $this->judge($actor, $number + 1, $data, $options, $seen, $territories);
        }
        if ($columns === null || $rows === []) {
            throw CrmRuleViolation::because('import_empty_file');
        }

        return DB::transaction(function () use ($batch, $rows): ImportBatch {
            $batch->rows()->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                ImportRow::query()->insert(array_map(fn (array $row): array => [
                    'import_batch_id' => $batch->id,
                    'row_number' => $row['row_number'],
                    'data' => json_encode($row['data'], JSON_UNESCAPED_UNICODE),
                    'status' => $row['status'],
                    'errors' => $row['errors'] === [] ? null : json_encode($row['errors'], JSON_UNESCAPED_UNICODE),
                    'duplicate_of_person_id' => $row['duplicate_of_person_id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $chunk));
            }
            $statuses = array_count_values(array_column($rows, 'status'));
            $batch->update([
                'status' => ImportBatch::VALIDATED,
                'failure' => null,
                'totals' => [
                    'total' => count($rows),
                    'valid' => $statuses[ImportRow::VALID] ?? 0,
                    'errors' => $statuses[ImportRow::ERROR] ?? 0,
                    'duplicates' => $statuses[ImportRow::DUPLICATE] ?? 0,
                ],
            ]);

            return $batch;
        });
    }

    /**
     * Accepts the validated file for import. Refused while any row has an error.
     */
    public function commit(User $actor, ImportBatch $batch, bool $inline = false): ImportBatch
    {
        $this->ensureMayHandle($actor, $batch);
        if ($batch->status !== ImportBatch::VALIDATED) {
            throw CrmRuleViolation::because('import_wrong_state');
        }
        if (($batch->totals['errors'] ?? 0) > 0) {
            throw CrmRuleViolation::because('import_has_errors', ['count' => $batch->totals['errors']]);
        }

        $batch->update(['status' => ImportBatch::IMPORTING, 'started_at' => now()]);
        // Normally the queue does the work (ТЗ §68); "inline" is for seeding and scripts that must not depend on a worker.
        $inline ? $this->run($batch) : RunPeopleImport::dispatch($batch->id);

        return $batch->fresh() ?? $batch;
    }

    /**
     * Creates the people (called by the queue job). One transaction: on any failure nothing stays.
     */
    public function run(ImportBatch $batch): void
    {
        if ($batch->status !== ImportBatch::IMPORTING) {
            return;
        }
        $actor = $batch->creator;
        $options = $batch->options ?? [];
        $pipeline = isset($options['pipeline_id']) ? Pipeline::query()->find($options['pipeline_id']) : null;
        $accepted = ($options['duplicates'] ?? 'skip') === 'create' ? [ImportRow::VALID, ImportRow::DUPLICATE] : [ImportRow::VALID];

        try {
            $totals = DB::transaction(function () use ($actor, $batch, $pipeline, $accepted): array {
                $imported = 0;
                $leads = 0;
                foreach ($batch->rows()->whereIn('status', $accepted)->cursor() as $row) {
                    $data = $row->data;
                    $person = $this->people->create($actor, $this->personAttributes($data), ['import_batch_id' => $batch->id]);

                    $profile = array_filter(['birth_date' => $data['birth_date'] ?? null, 'gender' => $data['gender'] ?? null]);
                    if ($profile !== []) {
                        $this->profiles->updateFor($actor, $person, $profile);
                    }
                    if (($data['custom'] ?? []) !== []) {
                        $this->customFields->store(CustomField::PERSON, $person->id, $data['custom'], $person->person_type);
                    }
                    if ($pipeline !== null) {
                        $this->leads->create($actor, $pipeline, $person, [], ['import_batch_id' => $batch->id]);
                        $leads++;
                    }
                    $row->update(['status' => ImportRow::IMPORTED, 'person_id' => $person->id]);
                    $imported++;
                }
                $skipped = $batch->rows()->where('status', ImportRow::DUPLICATE)->update(['status' => ImportRow::SKIPPED]);

                return ['imported' => $imported, 'skipped' => $skipped, 'leads' => $leads];
            });
        } catch (Throwable $exception) {
            $batch->update(['status' => ImportBatch::FAILED, 'failure' => mb_substr($exception->getMessage(), 0, 1000), 'finished_at' => now()]);
            $this->journal->record('crm.import.failed', $batch, [], ['file' => $batch->original_name]);

            return;
        }

        $batch->update(['status' => ImportBatch::COMPLETED, 'finished_at' => now(), 'totals' => [...($batch->totals ?? []), ...$totals]]);
        $this->journal->record('crm.import.completed', $batch, [], ['file' => $batch->original_name, ...$totals]);
    }

    /**
     * Undoes a finished import. Cards nobody has worked with since are removed; cards that already have their
     * own history (interactions, tasks, appeals, other leads, a merge) are archived instead — history is not erased.
     *
     * @return array{removed: int, archived: int}
     */
    public function rollback(User $actor, ImportBatch $batch): array
    {
        $this->ensureMayHandle($actor, $batch);
        if ($batch->status !== ImportBatch::COMPLETED) {
            throw CrmRuleViolation::because('import_wrong_state');
        }

        return DB::transaction(function () use ($actor, $batch): array {
            $result = ['removed' => 0, 'archived' => 0];
            foreach (Person::query()->where('import_batch_id', $batch->id)->get() as $person) {
                if ($this->hasOwnHistory($person, $batch)) {
                    if (! $person->isArchived()) {
                        $person->forceFill(['archived_at' => now()])->save();
                    }
                    $result['archived']++;

                    continue;
                }
                Lead::query()->where('person_id', $person->id)->where('import_batch_id', $batch->id)->delete();
                DB::table('custom_field_values')->where('entity_id', $person->id)
                    ->whereIn('custom_field_id', CustomField::query()->where('entity', CustomField::PERSON)->select('id'))->delete();
                $person->delete();
                $result['removed']++;
            }
            $batch->update(['status' => ImportBatch::ROLLED_BACK, 'rolled_back_at' => now()]);
            $this->journal->record('crm.import.rolled_back', $batch, [], ['file' => $batch->original_name, ...$result, 'by_user_id' => $actor->id]);

            return $result;
        });
    }

    /**
     * Rows that were not accepted, with the reasons — the error report.
     *
     * @return array{0: list<string>, 1: list<list<string|int|null>>}
     */
    public function report(User $actor, ImportBatch $batch): array
    {
        $this->ensureMayHandle($actor, $batch);
        $header = [__('crm.import.report.row'), __('crm.import.report.status'), __('crm.import.report.problems'), ...self::COLUMNS];
        $rows = [];
        foreach ($batch->rows()->whereIn('status', [ImportRow::ERROR, ImportRow::DUPLICATE, ImportRow::SKIPPED])->cursor() as $row) {
            $rows[] = [
                $row->row_number,
                __('crm.import.row_statuses.'.$row->status),
                implode('; ', $row->errors ?? []),
                ...array_map(fn (string $column) => $row->data['raw'][$column] ?? $row->data[$column] ?? null, self::COLUMNS),
            ];
        }

        return [$header, $rows];
    }

    /**
     * The import template: the header and one example row.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    public function template(): array
    {
        $custom = $this->customFields->definitions(CustomField::PERSON)->map(fn (CustomField $field): string => 'cf_'.$field->code)->all();

        return [
            [...self::COLUMNS, ...$custom],
            [['Ioana', 'Exemplu', 'ioana.exemplu@example.org', '+373 69 000 000', 'supporter', 'ro', 'chisinau/sectorul-centru', '1990-05-17', 'female', 'event', ...array_fill(0, count($custom), '')]],
        ];
    }

    public function mayHandle(User $actor, ImportBatch $batch): bool
    {
        return $this->authorization->can($actor, 'people.import')
            && ($batch->created_by_user_id === $actor->id || $this->authorization->can($actor, 'people.import', $batch->creator->person));
    }

    /**
     * @param  list<string|null>  $cells
     * @return list<string|null> column per cell; null = not imported
     */
    private function mapHeader(array $cells): array
    {
        return array_map(function (?string $cell): ?string {
            $key = Str::of((string) $cell)->lower()->trim()->replace([' ', '-'], '_')->toString();
            $key = self::ALIASES[$key] ?? self::ALIASES[str_replace('_', '-', $key)] ?? $key;

            return in_array($key, self::COLUMNS, true) || str_starts_with($key, 'cf_') ? $key : null;
        }, $cells);
    }

    /**
     * @param  array<string, string|null>  $raw
     * @param  array<string, mixed>  $options
     * @param  array{email: array<string, int>, phone: array<string, int>}  $seen
     * @param  array<string, int|null>  $territories
     * @return array{row_number: int, data: array<string, mixed>, status: string, errors: list<string>, duplicate_of_person_id: int|null}
     */
    private function judge(User $actor, int $rowNumber, array $raw, array $options, array &$seen, array &$territories): array
    {
        $errors = [];
        $data = ['raw' => array_intersect_key($raw, array_flip(self::COLUMNS))];

        $attributes = [
            'first_name' => $raw['first_name'] ?? null,
            'last_name' => $raw['last_name'] ?? null,
            'email' => $raw['email'] ?? null,
            'phone' => $raw['phone'] ?? null,
            'person_type' => $raw['person_type'] ?? $options['person_type'] ?? 'supporter',
            'preferred_locale' => $raw['preferred_locale'] ?? 'ro',
            'source_code' => $raw['source_code'] ?? $options['source_code'] ?? null,
            'responsible_unit_id' => $options['responsible_unit_id'] ?? null,
            'territory_id' => $options['territory_id'] ?? null,
        ];

        if (filled($raw['territory_code'] ?? null)) {
            $code = (string) $raw['territory_code'];
            $territories[$code] ??= $this->territoryId($code);
            if ($territories[$code] === null) {
                $errors[] = __('crm.import.errors.unknown_territory', ['code' => $code]);
            } else {
                $attributes['territory_id'] = $territories[$code];
            }
        }

        try {
            $attributes = [...$attributes, ...$this->people->validated($attributes, null)];
        } catch (PeopleRuleViolation $violation) {
            $errors[] = $violation->getMessage();
        }

        if (filled($raw['birth_date'] ?? null)) {
            try {
                $birthDate = CarbonImmutable::parse((string) $raw['birth_date']);
                $birthDate->isFuture() ? $errors[] = __('crm.import.errors.invalid_birth_date') : $data['birth_date'] = $birthDate->toDateString();
            } catch (Throwable) {
                $errors[] = __('crm.import.errors.invalid_birth_date');
            }
        }
        if (filled($raw['gender'] ?? null)) {
            $gender = match (Str::lower((string) $raw['gender'])) {
                'female', 'f', 'ж', 'жен', 'feminin' => 'female',
                'male', 'm', 'м', 'муж', 'masculin' => 'male',
                default => null,
            };
            $gender === null ? $errors[] = __('crm.import.errors.invalid_gender') : $data['gender'] = $gender;
        }

        $custom = [];
        foreach ($raw as $column => $value) {
            if (str_starts_with($column, 'cf_') && filled($value)) {
                $custom[substr($column, 3)] = $value;
            }
        }
        if ($custom !== []) {
            try {
                $data['custom'] = $this->customFields->normalize(CustomField::PERSON, $custom, (string) $attributes['person_type']);
            } catch (CustomFieldViolation $violation) {
                $errors[] = $violation->getMessage();
            }
        }

        $data = [...$data, ...array_intersect_key($attributes, array_flip([
            'first_name', 'last_name', 'email', 'phone', 'person_type', 'preferred_locale', 'source_code', 'responsible_unit_id', 'territory_id',
        ]))];

        if ($errors === [] && ! $this->authorization->can($actor, 'people.create', new Person($this->personAttributes($data)))) {
            $errors[] = __('crm.import.errors.outside_scope');
        }
        if ($errors !== []) {
            return ['row_number' => $rowNumber, 'data' => $data, 'status' => ImportRow::ERROR, 'errors' => $errors, 'duplicate_of_person_id' => null];
        }

        // Duplicates: first inside the file itself, then against the registry.
        $notes = [];
        $duplicateOf = null;
        foreach (['email', 'phone'] as $key) {
            if (! isset($data[$key])) {
                continue;
            }
            $value = (string) $data[$key];
            if (isset($seen[$key][$value])) {
                $notes[] = __('crm.import.errors.duplicate_in_file', ['field' => __('crm.import.fields.'.$key), 'row' => $seen[$key][$value]]);
            } else {
                $seen[$key][$value] = $rowNumber;
            }
        }
        $matches = $this->finder->find(new Person($this->personAttributes($data)));
        if ($matches !== []) {
            $duplicateOf = (int) array_key_first($matches);
            $notes[] = __('crm.import.errors.duplicate_in_registry', [
                'id' => $duplicateOf,
                'reasons' => implode(', ', array_map(fn (string $reason): string => __('crm.duplicate_reasons.'.$reason), $matches[$duplicateOf])),
            ]);
        }

        return [
            'row_number' => $rowNumber,
            'data' => $data,
            'status' => $notes === [] ? ImportRow::VALID : ImportRow::DUPLICATE,
            'errors' => $notes,
            'duplicate_of_person_id' => $duplicateOf,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function personAttributes(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'first_name', 'last_name', 'email', 'phone', 'person_type', 'preferred_locale', 'source_code', 'responsible_unit_id', 'territory_id',
        ]));
    }

    /**
     * By code, or by an official name when it is unambiguous.
     */
    private function territoryId(string $value): ?int
    {
        $id = Territory::query()->where('code', $value)->value('id');
        if ($id !== null) {
            return (int) $id;
        }
        $byName = Territory::query()->where('name_ro', $value)->orWhere('name_ru', $value)->limit(2)->pluck('id');

        return $byName->count() === 1 ? (int) $byName->first() : null;
    }

    private function hasOwnHistory(Person $person, ImportBatch $batch): bool
    {
        return $person->user !== null
            || DB::table('interactions')->where('person_id', $person->id)->exists()
            || DB::table('tasks')->where('subject_person_id', $person->id)->exists()
            || DB::table('appeals')->where('person_id', $person->id)->exists()
            || DB::table('leads')->where('person_id', $person->id)->where(fn ($q) => $q->whereNull('import_batch_id')->orWhere('import_batch_id', '!=', $batch->id))->exists()
            || DB::table('lead_stage_history')->whereIn('lead_id', DB::table('leads')->where('person_id', $person->id)->select('id'))->count() > 1
            || DB::table('person_relations')->where('person_id', $person->id)->orWhere('related_person_id', $person->id)->exists()
            || DB::table('person_merges')->where('kept_person_id', $person->id)->exists()
            || DB::table('org_memberships')->where('person_id', $person->id)->exists();
    }

    private function ensureMayHandle(User $actor, ImportBatch $batch): void
    {
        $this->authorization->authorize($actor, 'people.import');
        if (! $this->mayHandle($actor, $batch)) {
            $this->authorization->authorize($actor, 'people.import', $batch->creator->person);
        }
    }
}
