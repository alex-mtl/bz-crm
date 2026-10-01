<?php

declare(strict_types=1);

namespace App\Domain\Catalogs;

use App\Domain\Catalogs\Models\CatalogItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Duplicate guard for proposals (Д-16): compares a proposed name with existing items in any language.
 */
final class SimilarItemFinder
{
    /**
     * @param  array<string, string|null>  $names
     * @return Collection<int, CatalogItem>
     */
    public function find(string $catalogCode, array $names): Collection
    {
        $candidates = array_values(array_filter(array_map(fn (?string $n): string => $this->normalize((string) $n), $names)));
        if ($candidates === []) {
            return new Collection;
        }

        return CatalogItem::query()->ofCatalog($catalogCode)->get()
            ->filter(function (CatalogItem $item) use ($candidates): bool {
                foreach ([$item->name_ro, $item->name_ru, $item->name_en] as $existing) {
                    $existing = $this->normalize($existing);
                    foreach ($candidates as $candidate) {
                        if ($this->similar($candidate, $existing)) {
                            return true;
                        }
                    }
                }

                return false;
            })
            ->values();
    }

    private function similar(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }

        // Only a hint for the reviewer: better one extra match than a missed duplicate ("Звонок" / "Звонки").
        return levenshtein($a, $b) <= max(1, intdiv(min(strlen($a), strlen($b)), 3));
    }

    private function normalize(string $value): string
    {
        $ascii = Str::lower(Str::ascii($value));

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]+/', ' ', $ascii)));
    }
}
