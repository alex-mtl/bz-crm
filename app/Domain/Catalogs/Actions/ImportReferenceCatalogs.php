<?php

declare(strict_types=1);

namespace App\Domain\Catalogs\Actions;

use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\CatalogItemWriter;
use App\Domain\Catalogs\CatalogRegistry;
use App\Domain\Catalogs\Models\CatalogItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads starter items from database/data/catalogs/*.csv (all environments, idempotent by code).
 * Existing items are never overwritten: an admin's edits win over the reference file.
 * CSV columns: code, name_ro, name_ru, name_en, is_system (optional), unverified (optional, e.g. "ro|en"),
 * then catalog properties; other columns (descriptions) are ignored.
 */
final readonly class ImportReferenceCatalogs
{
    public function __construct(
        private CatalogRegistry $catalogs,
        private CatalogItemWriter $writer,
        private EventJournal $journal,
    ) {}

    /**
     * @return array<string, int> items created per catalog
     */
    public function __invoke(?string $basePath = null): array
    {
        $basePath ??= database_path('data/catalogs');
        $created = [];

        DB::transaction(function () use ($basePath, &$created): void {
            foreach ($this->catalogs->all() as $definition) {
                if ($definition->dataFile === null) {
                    continue;
                }
                $count = 0;
                foreach ($this->rows($basePath.'/'.$definition->dataFile) as $index => $row) {
                    if (CatalogItem::query()->where('catalog_code', $definition->code)->where('code', $row['code'])->exists()) {
                        continue;
                    }
                    $properties = array_intersect_key($row, $definition->properties);
                    $item = $this->writer->create(
                        $definition->code,
                        ['ro' => $row['name_ro'], 'ru' => $row['name_ru'], 'en' => $row['name_en']],
                        'ru',
                        $properties,
                        $row['code'],
                        ($row['is_system'] ?? '0') === '1',
                        ($index + 1) * 10,
                    );
                    // Working translations without an official source are flagged for a native speaker (Д-8, Д-16).
                    $unverified = array_values(array_filter(explode('|', $row['unverified'] ?? '')));
                    if ($unverified !== []) {
                        $item->update(['unverified_locales' => $unverified]);
                    }
                    $count++;
                }
                if ($count > 0) {
                    $created[$definition->code] = $count;
                }
            }

            if ($created !== []) {
                $this->journal->record('catalogs.reference_data.imported', null, [], ['created' => $created]);
            }
        });

        return $created;
    }

    /**
     * @return list<array<string, string>>
     */
    private function rows(string $file): array
    {
        $handle = fopen($file, 'rb') ?: throw new RuntimeException("Cannot open catalog data file [{$file}].");
        $header = fgetcsv($handle, null, ',', '"', '') ?: [];
        $rows = [];
        while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $rows[] = array_combine($header, array_pad($line, count($header), ''));
        }
        fclose($handle);

        return $rows;
    }
}
