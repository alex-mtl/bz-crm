<?php

declare(strict_types=1);

namespace App\Domain\Geo\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Geo\Exceptions\GeoRuleViolation;
use App\Domain\Geo\FieldSettings;
use App\Domain\Geo\Models\GeoZoneCrossing;
use App\Domain\Geo\Models\LocationPoint;
use App\Domain\Geo\Models\LocationShare;
use App\Domain\Geo\ZoneWatcher;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Voluntary location sharing (Д-22). A person turns it on themselves, for one of the allowed periods, and may
 * stop it at any moment; when the period runs out it stops by itself. Nobody can turn it on for somebody else.
 * Points are kept for a limited number of days. Turning on is the consent — it is journaled, and so is every
 * look at somebody's track.
 */
final readonly class LocationSharing
{
    public function __construct(
        private AuthorizationService $authorization,
        private FieldSettings $settings,
        private ZoneWatcher $watcher,
        private EventJournal $journal,
    ) {}

    public function current(User $user): ?LocationShare
    {
        return LocationShare::query()->live()->where('person_id', $user->person_id)->orderByDesc('id')->first();
    }

    public function start(User $user, int $minutes): LocationShare
    {
        $this->authorization->authorize($user, 'geo.locations.share');
        if (! in_array($minutes, $this->settings->shareMinutes(), true)) {
            throw GeoRuleViolation::because('invalid_share_period');
        }

        return DB::transaction(function () use ($user, $minutes): LocationShare {
            $this->close($user);
            $share = LocationShare::query()->create([
                'person_id' => $user->person_id, 'started_at' => now(), 'expires_at' => now()->addMinutes($minutes),
            ]);
            $this->journal->record('geo.location.sharing_started', $share, [], ['minutes' => $minutes, 'until' => $share->expires_at->toIso8601String()]);

            return $share;
        });
    }

    public function stop(User $user): void
    {
        DB::transaction(fn () => $this->close($user));
    }

    /**
     * A point from the person's own device. Accepted only while their sharing is on.
     */
    public function report(User $user, float $latitude, float $longitude, ?int $accuracy = null, ?Carbon $at = null): LocationPoint
    {
        $share = $this->current($user);
        if ($share === null) {
            throw GeoRuleViolation::because('not_sharing');
        }
        if (abs($latitude) > 90 || abs($longitude) > 180) {
            throw GeoRuleViolation::because('invalid_point');
        }
        $at = $at !== null && $at->between($share->started_at, now()->addMinute()) ? $at : now();

        $point = DB::transaction(function () use ($share, $latitude, $longitude, $accuracy, $at): LocationPoint {
            $point = LocationPoint::query()->create([
                'location_share_id' => $share->id, 'latitude' => $latitude, 'longitude' => $longitude, 'accuracy' => $accuracy, 'recorded_at' => $at,
            ]);
            if ($share->last_point_at === null || $at->gte($share->last_point_at)) {
                $share->update(['last_latitude' => $latitude, 'last_longitude' => $longitude, 'last_accuracy' => $accuracy, 'last_point_at' => $at]);
            }

            return $point;
        });
        $this->watcher->moved(GeoZoneCrossing::PERSON, $share->person_id, $latitude, $longitude, $at, [
            'name' => $share->person->fullName(), 'share_id' => $share->id,
        ]);

        return $point;
    }

    /**
     * Who is sharing right now — among the people the viewer leads. One's own sharing is always seen.
     *
     * @return Builder<LocationShare>
     */
    public function visibleTo(User $viewer): Builder
    {
        $led = $this->authorization->scopeQuery($viewer, 'geo.locations.read', LocationShare::query())->select('location_shares.id');

        return LocationShare::query()->live()->where(fn (Builder $where) => $where
            ->where('location_shares.person_id', $viewer->person_id)
            ->orWhereIn('location_shares.id', $led));
    }

    /**
     * The track of one sharing. Looking at somebody else's track is journaled.
     *
     * @return Collection<int, LocationPoint>
     */
    public function track(User $viewer, LocationShare $share): Collection
    {
        $own = $share->person_id === $viewer->person_id;
        if (! $own && ! $this->authorization->can($viewer, 'geo.locations.read', $share)) {
            throw new AuthorizationException(__('access.denied'));
        }
        if (! $own) {
            $this->journal->record('geo.location.track_viewed', $share, [], ['person_id' => $share->person_id]);
        }

        return LocationPoint::query()->where('location_share_id', $share->id)->orderBy('recorded_at')->limit(2000)->get();
    }

    /**
     * Removes what is older than the retention period: points first, then the sharings left without any.
     *
     * @return int points removed
     */
    public function purge(): int
    {
        $before = now()->subDays($this->settings->locationRetentionDays());
        $removed = LocationPoint::query()->where('recorded_at', '<', $before)->delete();
        LocationShare::query()->where('expires_at', '<', $before)->delete();
        if ($removed > 0) {
            $this->journal->record('geo.location.purged', null, [], ['points' => $removed, 'older_than_days' => $this->settings->locationRetentionDays()]);
        }

        return $removed;
    }

    private function close(User $user): void
    {
        $live = $this->current($user);
        if ($live === null) {
            return;
        }
        $live->update(['stopped_at' => now()]);
        $this->watcher->lost(GeoZoneCrossing::PERSON, $live->person_id);
        $this->journal->record('geo.location.sharing_stopped', $live);
    }
}
