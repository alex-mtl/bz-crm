<?php

declare(strict_types=1);

namespace App\Domain\Catalogs;

use App\Domain\Catalogs\Exceptions\CatalogRuleViolation;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Support\Translation\TranslatedNames;
use Illuminate\Support\Str;

/**
 * Shared creation logic for direct creation, approved proposals and reference data import.
 * Callers are responsible for authorization and journaling.
 */
final readonly class CatalogItemWriter
{
    public function __construct(private CatalogRegistry $catalogs) {}

    /**
     * @param  array<string, string|null>  $names
     * @param  array<string, mixed>  $properties
     */
    public function create(string $catalogCode, array $names, string $sourceLocale, array $properties = [], ?string $code = null, bool $isSystem = false, ?int $sortOrder = null): CatalogItem
    {
        $definition = $this->catalogs->get($catalogCode);
        $translated = TranslatedNames::complete($names, $sourceLocale);

        return CatalogItem::query()->create([
            'catalog_code' => $catalogCode,
            'code' => $code ?? $this->uniqueCode($catalogCode, $translated->names['en']),
            'name_ro' => $translated->names['ro'],
            'name_ru' => $translated->names['ru'],
            'name_en' => $translated->names['en'],
            'unverified_locales' => $translated->unverified,
            'properties' => $this->validProperties($definition, $properties),
            'is_system' => $isSystem,
            'is_active' => true,
            'sort_order' => $sortOrder ?? ((int) CatalogItem::query()->where('catalog_code', $catalogCode)->max('sort_order') + 10),
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public function validProperties(CatalogDefinition $definition, array $properties): array
    {
        $result = [];
        foreach ($properties as $key => $value) {
            $type = $definition->properties[$key] ?? throw CatalogRuleViolation::unknownProperty($key);
            $result[$key] = match ($type) {
                'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
                'int' => (int) $value,
                'string' => (string) $value,
            };
        }

        return $result;
    }

    private function uniqueCode(string $catalogCode, string $name): string
    {
        $base = Str::slug($name, '_') ?: 'item';
        $code = $base;
        for ($i = 2; CatalogItem::query()->where('catalog_code', $catalogCode)->where('code', $code)->exists(); $i++) {
            $code = $base.'_'.$i;
        }

        return $code;
    }
}
