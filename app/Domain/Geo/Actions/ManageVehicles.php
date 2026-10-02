<?php

declare(strict_types=1);

namespace App\Domain\Geo\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Geo\Exceptions\GeoRuleViolation;
use App\Domain\Geo\Models\GeoZoneCrossing;
use App\Domain\Geo\Models\LocationPoint;
use App\Domain\Geo\Models\Vehicle;
use App\Domain\Geo\ZoneWatcher;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vehicles (ФО §6.11, Д-22): a card, a tracker that reports with a key of its own, the history of movement.
 * A vehicle belongs to a unit — that decides who sees and manages it. The key is shown once; only its hash is kept.
 */
final readonly class ManageVehicles
{
    public const string KEY_PREFIX = 'trk_';

    public function __construct(
        private AuthorizationService $authorization,
        private ZoneWatcher $watcher,
        private EventJournal $journal,
    ) {}

    /**
     * @return Builder<Vehicle>
     */
    public function visibleTo(User $user): Builder
    {
        return $this->authorization->scopeQuery($user, 'geo.vehicles.read', Vehicle::query());
    }

    /**
     * @param  array{name: string, type_code: string, plate?: string|null, org_unit_id?: int|null, responsible_person_id?: int|null, description?: string|null}  $data
     */
    public function create(User $actor, array $data): Vehicle
    {
        $draft = new Vehicle($this->clean($data));
        $this->authorization->authorize($actor, 'geo.vehicles.manage', $draft);

        return DB::transaction(function () use ($draft): Vehicle {
            $draft->save();
            $this->journal->record('geo.vehicle.created', $draft, [], $draft->only(['name', 'plate', 'type_code', 'org_unit_id']));

            return $draft;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Vehicle $vehicle, array $data): Vehicle
    {
        $this->authorization->authorize($actor, 'geo.vehicles.manage', $vehicle);
        $changes = $this->clean([...$vehicle->only(['name', 'type_code', 'plate', 'org_unit_id', 'responsible_person_id', 'description']), ...$data]);
        if (($changes['org_unit_id'] ?? null) !== $vehicle->org_unit_id) {
            $this->authorization->authorize($actor, 'geo.vehicles.manage', new Vehicle(['org_unit_id' => $changes['org_unit_id'] ?? null]));
        }

        return DB::transaction(function () use ($vehicle, $changes): Vehicle {
            $old = $vehicle->only(array_keys($changes));
            $vehicle->update($changes);
            if ($vehicle->wasChanged()) {
                $this->journal->record('geo.vehicle.updated', $vehicle, array_intersect_key($old, $vehicle->getChanges()), array_intersect_key($changes, $vehicle->getChanges()));
            }

            return $vehicle;
        });
    }

    public function archive(User $actor, Vehicle $vehicle, bool $archived = true): void
    {
        $this->authorization->authorize($actor, 'geo.vehicles.manage', $vehicle);
        DB::transaction(function () use ($vehicle, $archived): void {
            // An archived vehicle reports nothing: its key dies with it.
            $vehicle->update(['archived_at' => $archived ? now() : null, ...($archived ? ['tracker_key_hash' => null, 'tracker_key_issued_at' => null] : [])]);
            if ($archived) {
                $this->watcher->lost(GeoZoneCrossing::VEHICLE, $vehicle->id);
            }
            $this->journal->record($archived ? 'geo.vehicle.archived' : 'geo.vehicle.restored', $vehicle);
        });
    }

    /**
     * Gives the tracker a new key; the previous one stops working. The key is returned once and never stored.
     */
    public function issueKey(User $actor, Vehicle $vehicle): string
    {
        $this->authorization->authorize($actor, 'geo.vehicles.manage', $vehicle);
        if ($vehicle->archived_at !== null) {
            throw GeoRuleViolation::because('vehicle_archived');
        }
        $key = self::KEY_PREFIX.Str::random(40);

        DB::transaction(function () use ($vehicle, $key): void {
            $vehicle->update(['tracker_key_hash' => hash('sha256', $key), 'tracker_key_issued_at' => now()]);
            $this->journal->record('geo.vehicle.tracker_key_issued', $vehicle);
        });

        return $key;
    }

    public function revokeKey(User $actor, Vehicle $vehicle): void
    {
        $this->authorization->authorize($actor, 'geo.vehicles.manage', $vehicle);
        if (! $vehicle->hasTracker()) {
            return;
        }
        DB::transaction(function () use ($vehicle): void {
            $vehicle->update(['tracker_key_hash' => null, 'tracker_key_issued_at' => null]);
            $this->journal->record('geo.vehicle.tracker_key_revoked', $vehicle);
        });
    }

    /**
     * Points from a tracker. The key is the tracker's only credential; a point with a time already stored for the
     * vehicle is skipped, so a tracker may safely send the same batch again.
     *
     * @param  list<mixed>  $points  each: latitude, longitude, recorded_at?, accuracy?
     * @return int|null points stored; null — the key is not known
     */
    public function report(string $key, array $points): ?int
    {
        $vehicle = str_starts_with($key, self::KEY_PREFIX)
            ? Vehicle::query()->active()->where('tracker_key_hash', hash('sha256', $key))->first()
            : null;
        if ($vehicle === null) {
            return null;
        }

        $stored = 0;
        $latest = null;
        foreach (array_slice($points, 0, 500) as $point) {
            if (! is_array($point) || ! is_numeric($point['latitude'] ?? null) || ! is_numeric($point['longitude'] ?? null)
                || abs((float) $point['latitude']) > 90 || abs((float) $point['longitude']) > 180) {
                continue;
            }
            try {
                $at = isset($point['recorded_at']) && is_string($point['recorded_at']) ? Carbon::parse($point['recorded_at'])->setTimezone(config('app.timezone')) : now();
            } catch (\Throwable) {
                continue;
            }
            if ($at->gt(now()->addMinutes(10)) || LocationPoint::query()->where('vehicle_id', $vehicle->id)->where('recorded_at', $at)->exists()) {
                continue;
            }
            $row = LocationPoint::query()->create([
                'vehicle_id' => $vehicle->id, 'latitude' => (float) $point['latitude'], 'longitude' => (float) $point['longitude'],
                'accuracy' => is_numeric($point['accuracy'] ?? null) ? (int) $point['accuracy'] : null, 'recorded_at' => $at,
            ]);
            $stored++;
            if ($latest === null || $row->recorded_at->gt($latest->recorded_at)) {
                $latest = $row;
            }
        }

        if ($latest !== null && ($vehicle->last_point_at === null || $latest->recorded_at->gte($vehicle->last_point_at))) {
            $vehicle->update(['last_latitude' => $latest->latitude, 'last_longitude' => $latest->longitude, 'last_point_at' => $latest->recorded_at]);
            $this->watcher->moved(GeoZoneCrossing::VEHICLE, $vehicle->id, (float) $latest->latitude, (float) $latest->longitude, $latest->recorded_at, ['name' => $vehicle->name]);
        }

        return $stored;
    }

    /**
     * @return Collection<int, LocationPoint>
     */
    public function track(User $viewer, Vehicle $vehicle, ?Carbon $from = null): Collection
    {
        $this->authorization->authorize($viewer, 'geo.vehicles.read', $vehicle);

        return LocationPoint::query()->where('vehicle_id', $vehicle->id)
            ->where('recorded_at', '>=', $from ?? now()->subDay())->orderBy('recorded_at')->limit(5000)->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            throw GeoRuleViolation::because('vehicle_name_required');
        }
        if (! CatalogItem::query()->ofCatalog('vehicle_types')->where('code', $data['type_code'] ?? '')->exists()) {
            throw GeoRuleViolation::because('unknown_vehicle_type');
        }

        return [
            'name' => $name,
            'type_code' => (string) $data['type_code'],
            'plate' => filled($data['plate'] ?? null) ? mb_strtoupper(trim((string) $data['plate'])) : null,
            'org_unit_id' => filled($data['org_unit_id'] ?? null) ? (int) $data['org_unit_id'] : null,
            'responsible_person_id' => filled($data['responsible_person_id'] ?? null) ? (int) $data['responsible_person_id'] : null,
            'description' => filled($data['description'] ?? null) ? (string) $data['description'] : null,
        ];
    }
}
