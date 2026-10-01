<?php

declare(strict_types=1);

namespace App\Domain\Events;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Scopes\ScopeType;
use App\Domain\Access\TerritorialAccess;
use App\Domain\Events\Models\Event;
use App\Domain\Geo\Models\Territory;
use App\Domain\Groups\GroupAccess;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Who sees an event (ФО §6.7 "уровень видимости как у постов") — decided in SQL, in this one place; lists,
 * the calendar, the iCal feed, notifications and the digest all start from visibleTo().
 *
 *   public   — everyone with events.read (a candidate only sees what is explicitly opened to them);
 *   regional — the territories of the event overlap the viewer's territorial access, in either direction;
 *   group    — members of one of the event's groups;
 *   private  — the organizer and the invited.
 *
 * The organizer and every invited person see the event whatever its level.
 */
final readonly class EventVisibility
{
    public function __construct(
        private AuthorizationService $authorization,
        private TerritorialAccess $territories,
        private GroupAccess $groups,
    ) {}

    /**
     * @return Builder<Event>
     */
    public function visibleTo(User $user): Builder
    {
        $query = Event::query();
        $grants = $this->authorization->grantsFor($user, 'events.read');
        if ($grants === []) {
            return $query->whereRaw('1 = 0');
        }

        $open = array_filter($grants, fn (array $grant): bool => $grant['data'] === null) !== [];
        $personId = $user->person_id;
        $groupIds = $this->groups->groupIdsOf($personId);
        [$inside, $above] = $open ? $this->territoryPaths($personId) : [[], []];

        return $query->where(function (Builder $where) use ($personId, $open, $groupIds, $inside, $above): void {
            $where->where('events.organizer_person_id', $personId)
                ->orWhereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('event_attendees as ev_a')
                    ->whereColumn('ev_a.event_id', 'events.id')->where('ev_a.person_id', $personId));
            if ($open) {
                $where->orWhere('events.visibility', Event::PUBLIC);
            }
            if ($inside !== []) {
                $where->orWhere(fn (Builder $regional) => $regional
                    ->where('events.visibility', Event::REGIONAL)
                    ->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('event_territories as ev_t')
                        ->join('territories as ev_tt', 'ev_tt.id', '=', 'ev_t.territory_id')
                        ->whereColumn('ev_t.event_id', 'events.id')
                        ->where(function (QueryBuilder $overlap) use ($inside, $above): void {
                            foreach ($inside as $path) {
                                $overlap->orWhere('ev_tt.path', 'like', $path.'%');
                            }
                            $overlap->orWhereIn('ev_tt.path', $above);
                        })));
            }
            if ($groupIds !== []) {
                $where->orWhere(fn (Builder $group) => $group
                    ->where('events.visibility', Event::GROUP)
                    ->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('event_groups as ev_g')
                        ->whereColumn('ev_g.event_id', 'events.id')->whereIn('ev_g.group_id', $groupIds)));
            }
        });
    }

    public function canSee(User $user, Event $event): bool
    {
        return $this->visibleTo($user)->whereKey($event->id)->exists();
    }

    /**
     * @param  iterable<User>  $users
     * @return list<User>
     */
    public function among(Event $event, iterable $users): array
    {
        $seeing = [];
        foreach ($users as $user) {
            if ($this->canSee($user, $event)) {
                $seeing[] = $user;
            }
        }

        return $seeing;
    }

    /**
     * May the user hold an event for this territory? An organization-wide right — anywhere; a scoped one —
     * inside the scope of the role or inside the user's own territorial access.
     */
    public function coversTerritory(User $user, Territory $territory): bool
    {
        foreach ($this->authorization->grantsFor($user, 'events.create') as $grant) {
            if ($grant['data'] !== null) {
                continue;
            }
            if ($grant['scope'] === ScopeType::Organization || $this->territories->covers($user->person_id, $territory)) {
                return true;
            }
        }

        return $this->authorization->can($user, 'events.create', $territory);
    }

    public function mayAddressEveryone(User $user): bool
    {
        foreach ($this->authorization->grantsFor($user, 'events.create') as $grant) {
            if ($grant['scope'] === ScopeType::Organization && $grant['data'] === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function territoryPaths(int $personId): array
    {
        $inside = array_values(array_filter($this->territories->paths($personId)));
        $above = [];
        foreach ($inside as $path) {
            $prefix = '/';
            foreach (array_values(array_filter(explode('/', $path))) as $id) {
                $prefix .= $id.'/';
                $above[$prefix] = true;
            }
        }

        return [$inside, array_keys($above)];
    }
}
