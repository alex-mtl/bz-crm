<?php

declare(strict_types=1);

namespace App\Domain\Geo;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Geo\Exceptions\GeoRuleViolation;
use App\Domain\Geo\Models\ApartmentNote;
use App\Domain\Identity\Models\User;
use App\Support\Settings\SystemSettings;
use Illuminate\Support\Facades\DB;

/**
 * What an administrator tunes in the field work (`system.settings.manage`): the map provider (ФО §10 —
 * "переключаемые"), how many houses one agitator may answer for (ФО §6.11: "2–3 дома … по настройке"), who reads a
 * note by default, and the limits of voluntary location sharing (Д-22).
 */
final readonly class FieldSettings
{
    public const string KEY = 'geo.field';

    /** Map providers that need no key. A custom one is any tile address with its attribution. */
    public const array PROVIDERS = [
        'osm' => [
            'url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'attribution' => '© OpenStreetMap contributors',
        ],
        'osm_hot' => [
            'url' => 'https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png',
            'attribution' => '© OpenStreetMap contributors, Humanitarian OpenStreetMap Team',
        ],
        'custom' => ['url' => '', 'attribution' => ''],
    ];

    private const array DEFAULTS = [
        'map_provider' => 'osm',
        'map_url' => '',
        'map_attribution' => '',
        // Chișinău.
        'map_latitude' => 47.0245,
        'map_longitude' => 28.8323,
        'map_zoom' => 12,
        'houses_per_agitator' => 3,
        'default_note_visibility' => ApartmentNote::TEAM,
        'share_minutes' => [60, 240, 480],
        'location_retention_days' => 30,
    ];

    public function __construct(
        private SystemSettings $settings,
        private AuthorizationService $authorization,
        private EventJournal $journal,
    ) {}

    /**
     * @return array{map_provider: string, map_url: string, map_attribution: string, map_latitude: float, map_longitude: float, map_zoom: int, houses_per_agitator: int|null, default_note_visibility: string, share_minutes: list<int>, location_retention_days: int}
     */
    public function all(): array
    {
        $stored = $this->settings->get(self::KEY);

        /** @var array{map_provider: string, map_url: string, map_attribution: string, map_latitude: float, map_longitude: float, map_zoom: int, houses_per_agitator: int|null, default_note_visibility: string, share_minutes: list<int>, location_retention_days: int} */
        return [...self::DEFAULTS, ...(is_array($stored) ? array_intersect_key($stored, self::DEFAULTS) : [])];
    }

    /**
     * What the map needs to draw tiles.
     *
     * @return array{url: string, attribution: string, center: array{0: float, 1: float}, zoom: int}
     */
    public function map(): array
    {
        $all = $this->all();
        $provider = $all['map_provider'] === 'custom'
            ? ['url' => $all['map_url'], 'attribution' => $all['map_attribution']]
            : (self::PROVIDERS[$all['map_provider']] ?? self::PROVIDERS['osm']);

        return [
            'url' => $provider['url'],
            'attribution' => $provider['attribution'],
            'center' => [(float) $all['map_latitude'], (float) $all['map_longitude']],
            'zoom' => (int) $all['map_zoom'],
        ];
    }

    /** null — no limit. */
    public function housesPerAgitator(): ?int
    {
        $limit = $this->all()['houses_per_agitator'];

        return $limit !== null && (int) $limit > 0 ? (int) $limit : null;
    }

    public function defaultNoteVisibility(): string
    {
        return $this->all()['default_note_visibility'];
    }

    /**
     * @return list<int> for how many minutes a person may share their location
     */
    public function shareMinutes(): array
    {
        return array_map('intval', $this->all()['share_minutes']);
    }

    public function locationRetentionDays(): int
    {
        return max(1, (int) $this->all()['location_retention_days']);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(User $actor, array $values): void
    {
        $this->authorization->authorize($actor, 'system.settings.manage');
        $old = $this->all();
        $new = [...$old, ...array_intersect_key($values, self::DEFAULTS)];

        $new['houses_per_agitator'] = filled($new['houses_per_agitator']) && (int) $new['houses_per_agitator'] > 0 ? (int) $new['houses_per_agitator'] : null;
        $new['share_minutes'] = array_values(array_unique(array_filter(array_map('intval', (array) $new['share_minutes']), fn (int $m): bool => $m >= 5 && $m <= 1440)));
        $new['location_retention_days'] = (int) $new['location_retention_days'];
        $new['map_zoom'] = (int) $new['map_zoom'];
        $new['map_latitude'] = (float) $new['map_latitude'];
        $new['map_longitude'] = (float) $new['map_longitude'];

        if (! isset(self::PROVIDERS[$new['map_provider']])
            || ($new['map_provider'] === 'custom' && ! preg_match('#^https://.+\{z\}.+\{x\}.+\{y\}#', (string) $new['map_url']))
            || ! in_array($new['default_note_visibility'], ApartmentNote::VISIBILITIES, true)
            || $new['share_minutes'] === []
            || $new['location_retention_days'] < 1 || $new['location_retention_days'] > 365
            || $new['map_zoom'] < 3 || $new['map_zoom'] > 19
            || abs($new['map_latitude']) > 90 || abs($new['map_longitude']) > 180) {
            throw GeoRuleViolation::because('invalid_settings');
        }
        if ($new == $old) {
            return;
        }

        DB::transaction(function () use ($actor, $old, $new): void {
            $this->settings->put(self::KEY, $new, $actor->id);
            $changed = array_keys(array_filter($new, fn (mixed $value, string $key): bool => $value != $old[$key], ARRAY_FILTER_USE_BOTH));
            $this->journal->record('geo.settings.changed', $actor, array_intersect_key($old, array_flip($changed)), array_intersect_key($new, array_flip($changed)));
        });
    }
}
