<?php

declare(strict_types=1);

namespace App\Domain\Geo\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Events\EventVisibility;
use App\Domain\Events\Models\Event;
use App\Domain\Geo\Exceptions\GeoRuleViolation;
use App\Domain\Geo\GeoService;
use App\Domain\Geo\Models\GeoZone;
use App\Domain\Geo\Models\GeoZoneLink;
use App\Domain\Geo\ZoneBindings;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Geozones (ФО §6.11): a name, an outline, the people who answer for it, the things bound to it. A zone stands
 * in a territory — that decides who manages it; a zone without a territory belongs to the whole organization.
 */
final readonly class ManageZones
{
    public function __construct(
        private AuthorizationService $authorization,
        private GeoService $geo,
        private ZoneBindings $bindings,
        private EventVisibility $events,
        private EventJournal $journal,
    ) {}

    /**
     * The zones the reader may see.
     *
     * @return Builder<GeoZone>
     */
    public function visibleTo(User $user): Builder
    {
        return $this->authorization->scopeQuery($user, 'geo.zones.read', GeoZone::query());
    }

    /**
     * @param  array{name: string, corners: array<array-key, mixed>, description?: string|null, color?: string|null,
     *               territory_id?: int|null, notify_events?: bool, notify_crossings?: bool, responsible_ids?: list<int>}  $data
     */
    public function create(User $actor, array $data): GeoZone
    {
        $draft = new GeoZone(['territory_id' => $data['territory_id'] ?? null]);
        $this->authorization->authorize($actor, 'geo.zones.manage', $draft);
        $outline = $this->geo->outline($data['corners']);

        $zone = DB::transaction(function () use ($actor, $draft, $data, $outline): GeoZone {
            $draft->fill([
                'name' => $this->name($data['name']),
                'description' => $data['description'] ?? null,
                'color' => $this->color($data['color'] ?? null),
                'geometry' => json_encode($outline['geometry'], JSON_THROW_ON_ERROR),
                ...$outline['box'],
                'notify_events' => (bool) ($data['notify_events'] ?? true),
                'notify_crossings' => (bool) ($data['notify_crossings'] ?? true),
                'created_by_person_id' => $actor->person_id,
            ])->save();
            $this->syncResponsibles($actor, $draft, $data['responsible_ids'] ?? []);
            $this->journal->record('geo.zone.created', $draft, [], ['name' => $draft->name, 'territory_id' => $draft->territory_id]);

            return $draft;
        });
        $this->bindings->zoneChanged($zone);

        return $zone;
    }

    /**
     * @param  array<string, mixed>  $data  name, description, color, corners, notify_events, notify_crossings, responsible_ids
     */
    public function update(User $actor, GeoZone $zone, array $data): GeoZone
    {
        $this->authorization->authorize($actor, 'geo.zones.manage', $zone);
        $changes = [];
        if (array_key_exists('name', $data)) {
            $changes['name'] = $this->name((string) $data['name']);
        }
        if (array_key_exists('description', $data)) {
            $changes['description'] = $data['description'];
        }
        if (array_key_exists('color', $data)) {
            $changes['color'] = $this->color($data['color']);
        }
        foreach (['notify_events', 'notify_crossings'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $changes[$flag] = (bool) $data[$flag];
            }
        }
        $moved = false;
        if (isset($data['corners']) && is_array($data['corners'])) {
            $outline = $this->geo->outline(array_values($data['corners']));
            $geometry = json_encode($outline['geometry'], JSON_THROW_ON_ERROR);
            $moved = $geometry !== $zone->geometry;
            $changes = [...$changes, 'geometry' => $geometry, ...$outline['box']];
        }

        DB::transaction(function () use ($actor, $zone, $changes, $data, $moved): void {
            $old = $zone->only(['name', 'description', 'color', 'notify_events', 'notify_crossings']);
            $zone->update($changes);
            if (array_key_exists('responsible_ids', $data)) {
                $this->syncResponsibles($actor, $zone, (array) $data['responsible_ids']);
            }
            $this->journal->record('geo.zone.updated', $zone, $old, [...$zone->only(array_keys($old)), 'outline_changed' => $moved]);
        });
        if ($moved) {
            $this->bindings->zoneChanged($zone);
        }

        return $zone;
    }

    public function archive(User $actor, GeoZone $zone, bool $archived = true): void
    {
        $this->authorization->authorize($actor, 'geo.zones.manage', $zone);
        if ($zone->isArchived() === $archived) {
            return;
        }
        DB::transaction(function () use ($zone, $archived): void {
            $zone->update(['archived_at' => $archived ? now() : null]);
            if ($archived) {
                DB::table('geo_zone_presences')->where('geo_zone_id', $zone->id)->delete();
            }
            $this->journal->record($archived ? 'geo.zone.archived' : 'geo.zone.restored', $zone);
        });
    }

    /**
     * Binds an event to the zone by hand — e.g. one that has no point on the map.
     */
    public function linkEvent(User $actor, GeoZone $zone, Event $event): void
    {
        $this->authorization->authorize($actor, 'geo.zones.manage', $zone);
        if (! $this->events->canSee($actor, $event)) {
            throw new AuthorizationException(__('access.denied'));
        }
        $this->bindings->bind($zone, $event, GeoZoneLink::MANUAL, $actor->person_id);
    }

    public function unlinkEvent(User $actor, GeoZone $zone, Event $event): void
    {
        $this->authorization->authorize($actor, 'geo.zones.manage', $zone);
        GeoZoneLink::query()->where('geo_zone_id', $zone->id)->where('subject_type', GeoZoneLink::EVENT)->where('subject_id', $event->id)->delete();
    }

    /**
     * @param  array<array-key, mixed>  $personIds
     */
    private function syncResponsibles(User $actor, GeoZone $zone, array $personIds): void
    {
        $ids = Person::query()->whereKey(array_map('intval', $personIds))->whereHas('user')->get()
            ->filter(fn (Person $person): bool => $this->authorization->can($actor, 'people.read', $person))
            ->pluck('id')->all();
        $zone->responsibles()->sync($ids);
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 150) {
            throw GeoRuleViolation::because('zone_name_required');
        }

        return $name;
    }

    private function color(mixed $color): string
    {
        return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? strtolower($color) : '#2563eb';
    }
}
