<?php

declare(strict_types=1);

namespace App\Domain\CRM\Exports;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CRM\Models\Appeal;
use App\Domain\CRM\Models\ExportBatch;
use App\Domain\CRM\Models\Lead;
use App\Domain\CRM\Notifications\CrmNotice;
use App\Domain\CRM\SegmentQuery;
use App\Domain\CustomObjects\CustomFields;
use App\Domain\CustomObjects\Models\CustomField;
use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\OrgUnit;
use App\Domain\People\Models\Person;
use App\Domain\Profiles\ProfileAccess;
use App\Support\Spreadsheet\Spreadsheet;
use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Export to CSV / XLSX (ТЗ §68) of people, leads and appeals. Each kind needs its own explicit right; the file
 * holds only the records the person sees and only the fields they may see (Д-13). Small exports are built at
 * once, large ones in the queue — the person is notified when the file is ready. Every export is journaled.
 */
final readonly class Exports
{
    public const string PEOPLE = 'people';

    public const string LEADS = 'leads';

    public const string APPEALS = 'appeals';

    private const array RIGHTS = [
        self::PEOPLE => ['people.export', 'people.read', 'people.exported'],
        self::LEADS => ['leads.export', 'pipelines.read', 'crm.leads.exported'],
        self::APPEALS => ['appeals.export', 'appeals.read', 'crm.appeals.exported'],
    ];

    public function __construct(
        private AuthorizationService $authorization,
        private SegmentQuery $segments,
        private ProfileAccess $profiles,
        private CustomFields $customFields,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  people: segment criteria; leads: pipeline_id, status; appeals: status
     */
    public function request(User $actor, string $kind, string $format, array $filters = []): ExportBatch
    {
        if (! isset(self::RIGHTS[$kind]) || ! in_array($format, Spreadsheet::FORMATS, true)) {
            throw CrmRuleViolation::because('export_unsupported');
        }
        $this->authorization->authorize($actor, self::RIGHTS[$kind][0]);

        $batch = ExportBatch::query()->create([
            'kind' => $kind, 'format' => $format, 'filters' => $filters,
            'status' => ExportBatch::QUEUED, 'created_by_user_id' => $actor->id,
        ]);

        if ($this->query($actor, $kind, $filters)->count() > (int) config('crm.export_sync_limit', 1000)) {
            BuildExport::dispatch($batch->id);

            return $batch;
        }
        $this->build($batch);

        return $batch->fresh() ?? $batch;
    }

    public function build(ExportBatch $batch): void
    {
        if ($batch->status !== ExportBatch::QUEUED) {
            return;
        }
        $actor = $batch->creator;
        $path = 'exports/'.Str::uuid().'.'.$batch->format;

        try {
            // The right is checked again: it may have been taken away while the export waited in the queue.
            $this->authorization->authorize($actor, self::RIGHTS[$batch->kind][0]);
            Storage::disk('local')->makeDirectory('exports');
            $count = Spreadsheet::write(
                Storage::disk('local')->path($path), $batch->format,
                $this->header($batch->kind), $this->rows($actor, $batch->kind, $batch->filters ?? []),
            );
        } catch (Throwable $exception) {
            $batch->update(['status' => ExportBatch::FAILED, 'failure' => mb_substr($exception->getMessage(), 0, 1000), 'finished_at' => now()]);

            return;
        }

        $batch->update(['status' => ExportBatch::READY, 'path' => $path, 'row_count' => $count, 'finished_at' => now()]);
        $this->journal->record(self::RIGHTS[$batch->kind][2], $batch, [], [
            'rows' => $count, 'format' => $batch->format, 'filters' => array_keys($batch->filters ?? []), 'user_id' => $actor->id,
        ]);
    }

    public function notifyReady(ExportBatch $batch): void
    {
        if ($batch->status === ExportBatch::READY) {
            $batch->creator->notify(new CrmNotice(CrmNotice::EXPORT_READY, __('crm.export.kinds.'.$batch->kind), '/exports/'.$batch->id.'/download'));
        }
    }

    /**
     * The file of a finished export — only for the one who asked for it.
     */
    public function file(User $actor, ExportBatch $batch): string
    {
        if ($batch->created_by_user_id !== $actor->id || $batch->status !== ExportBatch::READY || $batch->path === null) {
            throw new AuthorizationException(__('access.denied'));
        }

        return Storage::disk('local')->path($batch->path);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<covariant Model>
     */
    private function query(User $actor, string $kind, array $filters): Builder
    {
        [$exportCode, $readCode] = self::RIGHTS[$kind];
        /** @var Builder<Model> $base */
        $base = match ($kind) {
            self::PEOPLE => $this->segments->build($filters),
            self::LEADS => Lead::query()
                ->when($filters['pipeline_id'] ?? null, fn (Builder $q, $id) => $q->where('pipeline_id', $id))
                ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status)),
            default => Appeal::query()->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status)),
        };

        // Both scopes: what the person may export, inside what they may see at all.
        return $this->authorization->scopeQuery($actor, $exportCode, $this->authorization->scopeQuery($actor, $readCode, $base));
    }

    /**
     * @return list<string>
     */
    private function header(string $kind): array
    {
        $columns = match ($kind) {
            self::PEOPLE => ['id', 'first_name', 'last_name', 'person_type', 'territory', 'unit', 'email', 'phone', 'contacts', 'locale', 'source', 'created_at',
                ...$this->customFields->definitions(CustomField::PERSON)->map(fn (CustomField $f): string => $f->name())->all()],
            self::LEADS => ['id', 'pipeline', 'stage', 'status', 'person', 'responsible', 'territory', 'unit', 'source', 'loss_reason', 'frozen_until', 'created_at', 'stage_entered_at'],
            default => ['number', 'title', 'type', 'priority', 'status', 'person', 'responsible', 'territory', 'unit', 'due_at', 'closed_at', 'created_at', 'resolution'],
        };

        return array_map(fn (string $column): string => Lang::has('crm.export.columns.'.$column) ? __('crm.export.columns.'.$column) : $column, $columns);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Generator<int, list<bool|float|int|string|null>>
     */
    private function rows(User $actor, string $kind, array $filters): Generator
    {
        $catalog = fn (string $code): array => CatalogItem::query()->ofCatalog($code)->get()->mapWithKeys(fn (CatalogItem $i): array => [$i->code => $i->name()])->all();
        $territories = [];
        $units = [];
        $territory = function (?int $id) use (&$territories): ?string {
            return $id === null ? null : ($territories[$id] ??= Territory::query()->find($id)?->name());
        };
        $unit = function (?int $id) use (&$units): ?string {
            return $id === null ? null : ($units[$id] ??= OrgUnit::query()->whereKey($id)->value('name'));
        };
        $sources = $catalog('contact_sources');

        if ($kind === self::PEOPLE) {
            $types = $catalog('person_types');
            $fields = $this->customFields->definitions(CustomField::PERSON);
            foreach ($this->query($actor, $kind, $filters)->orderBy('people.id')->lazyById(500, 'people.id', 'id') as $person) {
                /** @var Person $person */
                $seesContacts = $this->authorization->can($actor, 'people.fields.contacts.read', $person);
                $values = $fields->isEmpty() ? [] : $this->customFields->values(CustomField::PERSON, $person->id);
                yield [
                    $person->id, $person->first_name, $person->last_name, $types[$person->person_type] ?? $person->person_type,
                    $territory($person->territory_id), $unit($person->responsible_unit_id),
                    $seesContacts ? $person->email : null, $seesContacts ? $person->phone : null,
                    $this->profiles->visibleContacts($actor, $person)->map(fn ($c): string => $c->contact_type.': '.$c->value)->implode('; '),
                    $person->preferred_locale, $sources[(string) $person->source_code] ?? $person->source_code, $person->created_at->toDateString(),
                    ...$fields->map(fn (CustomField $f) => $values[$f->code] ?? null)->all(),
                ];
            }

            return;
        }

        if ($kind === self::LEADS) {
            $reasons = $catalog('lead_loss_reasons');
            foreach ($this->query($actor, $kind, $filters)->with(['pipeline', 'stage', 'person', 'responsible'])->orderBy('leads.id')->lazyById(500, 'leads.id', 'id') as $lead) {
                /** @var Lead $lead */
                yield [
                    $lead->id, $lead->pipeline->name(), $lead->stage->name(), __('crm.lead_statuses.'.$lead->status),
                    $lead->person->fullName(), $lead->responsible?->fullName(), $territory($lead->territory_id), $unit($lead->org_unit_id),
                    $sources[(string) $lead->source_code] ?? $lead->source_code, $reasons[(string) $lead->lost_reason_code] ?? $lead->lost_reason_code,
                    $lead->frozen_until?->toDateString(), $lead->created_at->toDateString(), $lead->stage_entered_at?->toDateString(),
                ];
            }

            return;
        }

        $types = $catalog('appeal_types');
        $priorities = $catalog('appeal_priorities');
        foreach ($this->query($actor, $kind, $filters)->with(['person', 'responsible'])->orderBy('appeals.id')->lazyById(500, 'appeals.id', 'id') as $appeal) {
            /** @var Appeal $appeal */
            yield [
                $appeal->number, $appeal->title, $types[$appeal->type_code] ?? $appeal->type_code,
                $priorities[$appeal->priority_code] ?? $appeal->priority_code, __('crm.appeal_statuses.'.$appeal->status),
                $appeal->person?->fullName(), $appeal->responsible?->fullName(), $territory($appeal->territory_id), $unit($appeal->org_unit_id),
                $appeal->due_at?->toDateTimeString(), $appeal->closed_at?->toDateTimeString(), $appeal->created_at->toDateString(), $appeal->resolution,
            ];
        }
    }
}
