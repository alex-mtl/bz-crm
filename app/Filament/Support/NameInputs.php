<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\Translation\TranslatedNames;
use Filament\Forms\Components\TextInput;

/**
 * Three name inputs — ro / ru / en (Д-16). Only the current interface language is required: the others get a
 * copy on saving and can be translated later.
 */
final class NameInputs
{
    /**
     * @return list<TextInput>
     */
    public static function make(int $maxLength = 150): array
    {
        return array_map(fn (string $locale): TextInput => TextInput::make('name_'.$locale)
            ->label(__('admin.fields.name').' ('.strtoupper($locale).')')
            ->required($locale === app()->getLocale())
            ->maxLength($maxLength), TranslatedNames::locales());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null> locale => name
     */
    public static function names(array $data): array
    {
        $names = [];
        foreach (TranslatedNames::locales() as $locale) {
            $names[$locale] = isset($data['name_'.$locale]) ? (string) $data['name_'.$locale] : null;
        }

        return $names;
    }
}
