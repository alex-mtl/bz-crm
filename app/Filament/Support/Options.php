<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\Models\Role;
use App\Domain\Catalogs\Models\CatalogItem;

/**
 * Select options built from catalogs and roles, in the interface language.
 */
final class Options
{
    /**
     * @return array<string, string> role code => name
     */
    public static function roles(): array
    {
        return Role::query()->orderBy('id')->get()
            ->mapWithKeys(fn (Role $role): array => [$role->code => $role->name()])
            ->all();
    }

    /**
     * @return array<string, string> item code => name
     */
    public static function catalog(string $catalogCode): array
    {
        return CatalogItem::query()->ofCatalog($catalogCode)->selectable()->get()
            ->mapWithKeys(fn (CatalogItem $item): array => [$item->code => $item->name()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function locales(): array
    {
        return collect((array) config('app.supported_locales'))
            ->mapWithKeys(fn (string $code): array => [$code => __('identity.locales.'.$code)])
            ->all();
    }
}
