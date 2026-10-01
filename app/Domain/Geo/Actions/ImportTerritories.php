<?php

declare(strict_types=1);

namespace App\Domain\Geo\Actions;

use App\Domain\Audit\EventJournal;
use App\Domain\Geo\GeoService;
use App\Domain\Geo\Models\Territory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Loads the official territory reference of Moldova (Д-6…Д-9) from database/data/geo — in every environment,
 * production included. Idempotent by stable codes: a re-run updates official fields and never duplicates.
 * Admin-edited fields that are not in the files (description, activity) are left alone.
 */
final readonly class ImportTerritories
{
    public const string COUNTRY_CODE = 'md';

    public function __construct(private GeoService $geo, private EventJournal $journal) {}

    /**
     * @return array{created: int, updated: int, total: int}
     */
    public function __invoke(?string $basePath = null): array
    {
        $basePath ??= database_path('data/geo');
        $stats = ['created' => 0, 'updated' => 0, 'total' => 0];

        DB::transaction(function () use ($basePath, &$stats): void {
            $country = $this->upsert($stats, null, [
                'code' => self::COUNTRY_CODE, 'level' => Territory::COUNTRY, 'sort_order' => 0,
                'name_ro' => 'Republica Moldova', 'name_ru' => 'Республика Молдова', 'name_en' => 'Republic of Moldova', 'iso' => 'MD',
            ]);

            $macro = [];
            foreach ($this->rows($basePath.'/md-macro-regions.csv') as $i => $row) {
                $macro[$row['code']] = $this->upsert($stats, $country, [
                    'code' => 'mr-'.$row['code'], 'level' => Territory::MACRO_REGION, 'sort_order' => ($i + 1) * 10,
                    'name_ro' => $row['name_ro'], 'name_ru' => $row['name_ru'], 'name_en' => $row['name_en'],
                ]);
            }

            $districts = [];
            foreach ($this->rows($basePath.'/md-adm1-units.csv') as $i => $row) {
                $parent = $macro[$row['macro_region']] ?? throw new RuntimeException("Unknown macro-region [{$row['macro_region']}].");
                $aliases = array_values(array_filter(explode('|', $row['search_aliases'])));
                $districts[$row['slug']] = $this->upsert($stats, $parent, [
                    'code' => $row['slug'], 'level' => Territory::DISTRICT, 'sort_order' => ($i + 1) * 10, 'iso' => $row['iso'],
                    'name_ro' => $row['name_ro'], 'name_ru' => $row['name_ru'], 'name_en' => $row['name_en'],
                    'search_aliases' => $aliases === [] ? null : $aliases,
                ]);
            }

            $seen = [];
            foreach ($this->rows($basePath.'/md-localities-official.csv') as $i => $row) {
                $parent = $districts[$row['unit_slug']] ?? throw new RuntimeException("Unknown district [{$row['unit_slug']}].");
                $code = $row['unit_slug'].'/'.Str::slug($row['name_ro']);
                $seen[$code] = ($seen[$code] ?? 0) + 1;
                if ($seen[$code] > 1) {
                    $code .= '-'.$seen[$code];
                }
                $this->upsert($stats, $parent, [
                    'code' => $code,
                    'level' => Str::startsWith($row['name_ro'], 'Sectorul ') ? Territory::SECTOR : Territory::LOCALITY,
                    'sort_order' => ($i + 1) * 10,
                    'name_ro' => $row['name_ro'], 'name_ru' => $row['name_ru'], 'name_en' => $row['name_en'],
                    'railway_station' => $row['railway_station'] === '1',
                    'geonameid' => $row['geonameid'] !== '' ? (int) $row['geonameid'] : null,
                    'latitude' => $row['lat'] !== '' ? $row['lat'] : null,
                    'longitude' => $row['lng'] !== '' ? $row['lng'] : null,
                ]);
            }

            $this->geo->importBoundaries($basePath.'/md-adm1-boundaries.geojson', $districts);

            if ($stats['created'] + $stats['updated'] > 0) {
                $this->journal->record('geo.territories.imported', null, [], $stats);
            }
        });

        return $stats;
    }

    /**
     * @param  array{created: int, updated: int, total: int}  $stats
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(array &$stats, ?Territory $parent, array $attributes): Territory
    {
        $stats['total']++;
        $territory = Territory::query()->where('code', $attributes['code'])->first();
        $attributes = [
            'railway_station' => false, 'search_aliases' => null, 'iso' => null,
            'geonameid' => null, 'latitude' => null, 'longitude' => null,
            ...$attributes,
            'parent_id' => $parent?->id,
            'depth' => $parent !== null ? $parent->depth + 1 : 0,
        ];

        if ($territory === null) {
            $territory = Territory::query()->create($attributes);
            $stats['created']++;
        } else {
            $territory->fill($attributes);
            if ($territory->isDirty()) {
                $territory->save();
                $stats['updated']++;
            }
        }

        $path = ($parent !== null ? $parent->path : '/').$territory->id.'/';
        if ($territory->path !== $path) {
            $territory->forceFill(['path' => $path])->save();
        }

        return $territory;
    }

    /**
     * @return list<array<string, string>>
     */
    private function rows(string $file): array
    {
        $handle = fopen($file, 'rb') ?: throw new RuntimeException("Cannot read [{$file}].");
        $header = fgetcsv($handle, escape: '') ?: [];
        $rows = [];
        while (($line = fgetcsv($handle, escape: '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $rows[] = array_combine($header, array_map(fn ($v): string => (string) $v, array_pad($line, count($header), '')));
        }
        fclose($handle);

        return $rows;
    }
}
