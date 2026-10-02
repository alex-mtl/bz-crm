<?php

declare(strict_types=1);

namespace App\Domain\Geo\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Geo\Exceptions\GeoRuleViolation;
use App\Domain\Geo\FieldAccess;
use App\Domain\Geo\FieldSettings;
use App\Domain\Geo\Models\FieldAssignment;
use App\Domain\Geo\Models\House;
use App\Domain\Geo\Models\Territory;
use App\Domain\Geo\Notifications\FieldNotice;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Who answers for which houses (ФО §6.11). An agitator gets a house — or a whole territory, and then every
 * house in it. The assignment is what lets them open the house and record visits; ending it takes that away.
 */
final readonly class ManageAssignments
{
    public function __construct(
        private FieldAccess $access,
        private AuthorizationService $authorization,
        private FieldSettings $settings,
        private EventJournal $journal,
    ) {}

    public function assignHouse(User $actor, House $house, Person $person): FieldAssignment
    {
        $this->access->authorize($actor, 'geo.assignments.manage', $house);
        if ($house->isArchived()) {
            throw GeoRuleViolation::because('house_archived');
        }
        $this->ensureAgitator($actor, $person);
        if (FieldAssignment::query()->current()->where('person_id', $person->id)->where('house_id', $house->id)->exists()) {
            throw GeoRuleViolation::because('already_assigned');
        }
        $limit = $this->settings->housesPerAgitator();
        if ($limit !== null && FieldAssignment::query()->current()->where('person_id', $person->id)->whereNotNull('house_id')->count() >= $limit) {
            throw GeoRuleViolation::because('too_many_houses', ['limit' => $limit]);
        }

        return $this->store($actor, $person, ['house_id' => $house->id], $house->label(), '/admin/houses/'.$house->id);
    }

    /**
     * A polling district or a sector as a whole: every house inside, present and future.
     */
    public function assignTerritory(User $actor, Territory $territory, Person $person): FieldAssignment
    {
        $this->access->authorize($actor, 'geo.assignments.manage', new House(['territory_id' => $territory->id]));
        $this->ensureAgitator($actor, $person);
        if (FieldAssignment::query()->current()->where('person_id', $person->id)->where('territory_id', $territory->id)->exists()) {
            throw GeoRuleViolation::because('already_assigned');
        }

        return $this->store($actor, $person, ['territory_id' => $territory->id], $territory->name(), '/admin/houses');
    }

    public function end(User $actor, FieldAssignment $assignment): void
    {
        $subject = $assignment->house ?? new House(['territory_id' => $assignment->territory_id]);
        $this->access->authorize($actor, 'geo.assignments.manage', $subject);
        if ($assignment->ended_at !== null) {
            return;
        }

        DB::transaction(function () use ($actor, $assignment): void {
            $assignment->update(['ended_at' => now(), 'ended_by_person_id' => $actor->person_id]);
            $this->journal->record('geo.assignment.ended', $assignment, [], [
                'person_id' => $assignment->person_id, 'house_id' => $assignment->house_id, 'territory_id' => $assignment->territory_id,
            ]);
        });
        $this->forget();
    }

    /**
     * Only a person who can record visits may answer for a house — and only one the actor sees.
     */
    private function ensureAgitator(User $actor, Person $person): void
    {
        if (! $this->authorization->can($actor, 'people.read', $person)) {
            throw new AuthorizationException(__('access.denied'));
        }
        $user = $person->user;
        if ($user === null || ! $user->isActive() || ! $this->authorization->can($user, 'geo.visits.create')) {
            throw GeoRuleViolation::because('not_an_agitator');
        }
    }

    /**
     * @param  array<string, int>  $target
     */
    private function store(User $actor, Person $person, array $target, string $what, string $url): FieldAssignment
    {
        $assignment = DB::transaction(function () use ($actor, $person, $target): FieldAssignment {
            $assignment = FieldAssignment::query()->create([
                'person_id' => $person->id, ...$target, 'assigned_by_person_id' => $actor->person_id, 'assigned_at' => now(),
            ]);
            $this->journal->record('geo.assignment.created', $assignment, [], ['person_id' => $person->id, ...$target]);

            return $assignment;
        });
        $this->forget();

        if ($person->user !== null && $person->id !== $actor->person_id) {
            $person->user->notify(new FieldNotice(FieldNotice::ASSIGNED, ['what' => $what, 'url' => $url]));
        }

        return $assignment;
    }

    private function forget(): void
    {
        $this->access->forget();
        $this->authorization->forget();
    }
}
