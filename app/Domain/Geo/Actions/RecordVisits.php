<?php

declare(strict_types=1);

namespace App\Domain\Geo\Actions;

use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Geo\Events\VisitCompleted;
use App\Domain\Geo\Exceptions\GeoRuleViolation;
use App\Domain\Geo\FieldAccess;
use App\Domain\Geo\FieldSettings;
use App\Domain\Geo\Models\Apartment;
use App\Domain\Geo\Models\ApartmentNote;
use App\Domain\Geo\Models\House;
use App\Domain\Geo\Models\Visit;
use App\Domain\Identity\Models\User;
use App\Domain\Tasks\Actions\ManageTasks;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A visit to a flat (ФО §6.11): the status of the contact, the count of attempts, the date to come back, a note
 * for oneself or for the staff, a task "to return". Recorded by whoever answers for the house — at once or from
 * the offline queue, where the id of the operation guarantees one visit however many times it is sent.
 */
final readonly class RecordVisits
{
    /** A visit recorded offline may wait for the network; older than this it is a mistake of the device's clock. */
    public const int MAX_AGE_DAYS = 30;

    public const int MAX_NOTE = 2000;

    public function __construct(
        private FieldAccess $access,
        private FieldSettings $settings,
        private ManageTasks $tasks,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array{status_code: string, note?: string|null, note_visibility?: string|null, next_visit_on?: string|null,
     *               visited_at?: Carbon|string|null, create_task?: bool}  $data
     */
    public function record(User $actor, Apartment $apartment, array $data, ?string $operationId = null): Visit
    {
        $house = $apartment->house;
        $this->access->authorize($actor, 'geo.visits.create', $house);
        if ($house->isArchived()) {
            throw GeoRuleViolation::because('house_archived');
        }
        $status = CatalogItem::query()->ofCatalog('canvass_statuses')->selectable()->where('code', $data['status_code'])->first();
        if ($status === null || ! (bool) $status->property('visited')) {
            throw GeoRuleViolation::because('unknown_status');
        }
        $visitedAt = $this->moment($data['visited_at'] ?? null);
        $nextVisit = filled($data['next_visit_on'] ?? null) ? Carbon::parse((string) $data['next_visit_on'])->startOfDay() : null;
        if ($nextVisit !== null && $nextVisit->lt($visitedAt->copy()->startOfDay())) {
            throw GeoRuleViolation::because('next_visit_in_past');
        }
        $note = trim((string) ($data['note'] ?? ''));
        if (mb_strlen($note) > self::MAX_NOTE) {
            throw GeoRuleViolation::because('note_too_long', ['limit' => self::MAX_NOTE]);
        }

        $visit = DB::transaction(function () use ($actor, $apartment, $house, $status, $visitedAt, $nextVisit, $note, $data, $operationId): Visit {
            $locked = Apartment::query()->lockForUpdate()->findOrFail($apartment->id);
            $visit = Visit::query()->create([
                'apartment_id' => $locked->id, 'house_id' => $house->id, 'person_id' => $actor->person_id,
                'status_code' => $status->code, 'attempt_no' => $locked->attempts + 1, 'visited_at' => $visitedAt,
                'next_visit_on' => $nextVisit, 'operation_id' => $operationId,
                'source' => $operationId !== null ? Visit::OFFLINE : Visit::ONLINE,
            ]);

            // The flat shows the latest visit: one recorded offline earlier and synced later does not overwrite a newer one.
            $latest = $locked->last_visit_at === null || $visitedAt->gte($locked->last_visit_at);
            $locked->update([
                'attempts' => $locked->attempts + 1,
                ...($latest ? [
                    'status_code' => $status->code, 'last_visit_at' => $visitedAt, 'last_visit_person_id' => $actor->person_id,
                    'next_visit_on' => $nextVisit,
                ] : []),
            ]);

            if ($note !== '') {
                ApartmentNote::query()->create([
                    'apartment_id' => $locked->id, 'house_id' => $house->id, 'visit_id' => $visit->id,
                    'author_person_id' => $actor->person_id, 'visibility' => $this->visibility($data['note_visibility'] ?? null), 'body' => $note,
                ]);
            }
            // The note itself never reaches the journal.
            $this->journal->record('geo.visit.recorded', $visit, [], [
                'house_id' => $house->id, 'apartment' => $locked->number, 'status_code' => $status->code,
                'attempt_no' => $visit->attempt_no, 'source' => $visit->source, 'with_note' => $note !== '',
            ]);

            return $visit;
        });

        if ($nextVisit !== null && ($data['create_task'] ?? false)) {
            $this->followUp($actor, $visit, $house, $apartment, $nextVisit);
        }
        VisitCompleted::dispatch($visit);

        return $visit;
    }

    /**
     * A note without a visit — something noticed about the flat.
     */
    public function note(User $actor, Apartment $apartment, string $body, ?string $visibility = null): ApartmentNote
    {
        $this->access->authorize($actor, 'geo.visits.create', $apartment->house);
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::MAX_NOTE) {
            throw GeoRuleViolation::because('note_too_long', ['limit' => self::MAX_NOTE]);
        }

        return ApartmentNote::query()->create([
            'apartment_id' => $apartment->id, 'house_id' => $apartment->house_id, 'author_person_id' => $actor->person_id,
            'visibility' => $this->visibility($visibility), 'body' => $body,
        ]);
    }

    private function visibility(?string $asked): string
    {
        return in_array($asked, ApartmentNote::VISIBILITIES, true) ? $asked : $this->settings->defaultNoteVisibility();
    }

    /**
     * When the visit happened: the clock of the device, kept within reason.
     */
    private function moment(Carbon|string|null $asked): Carbon
    {
        if ($asked === null || $asked === '') {
            return now();
        }
        try {
            $moment = $asked instanceof Carbon ? $asked->copy() : Carbon::parse($asked);
        } catch (\Throwable) {
            throw GeoRuleViolation::because('invalid_visit_time');
        }
        $moment->setTimezone(config('app.timezone'));
        if ($moment->gt(now()->addMinutes(10)) || $moment->lt(now()->subDays(self::MAX_AGE_DAYS))) {
            throw GeoRuleViolation::because('invalid_visit_time');
        }

        return $moment->gt(now()) ? now() : $moment;
    }

    /**
     * ФО §6.11: «связанные задачи ("вернуться 12.10")». The task goes to the agitator; the visit stays recorded
     * even if the task could not be made.
     */
    private function followUp(User $actor, Visit $visit, House $house, Apartment $apartment, Carbon $on): void
    {
        try {
            $task = $this->tasks->create($actor, [
                'title' => __('geo.tasks.follow_up', ['address' => $house->label(), 'apartment' => $apartment->number]),
                'type_code' => 'follow_up_visit',
                'territory_id' => $house->territory_id,
                'due_at' => $on->copy()->setTime(20, 0),
            ], [$actor->person_id]);
            $visit->update(['task_id' => $task->id]);
        } catch (AuthorizationException|DomainException $e) {
            report($e);
        }
    }
}
