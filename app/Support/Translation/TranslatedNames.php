<?php

declare(strict_types=1);

namespace App\Support\Translation;

use InvalidArgumentException;

/**
 * Names in all interface languages (Д-16): a missing language gets a copy of the
 * source-language name and is flagged as "translation not verified" — never blocking.
 */
final readonly class TranslatedNames
{
    /**
     * @param  array<string, string>  $names  locale => name, always complete
     * @param  list<string>  $unverified  locales holding an automatic copy
     */
    private function __construct(public array $names, public array $unverified) {}

    /**
     * @param  array<string, string|null>  $given  locale => name (partial)
     */
    public static function complete(array $given, string $sourceLocale): self
    {
        $locales = self::locales();
        $source = trim((string) ($given[$sourceLocale] ?? ''));
        if (! in_array($sourceLocale, $locales, true) || $source === '') {
            throw new InvalidArgumentException("A name in the source language [{$sourceLocale}] is required.");
        }

        $names = [];
        $unverified = [];
        foreach ($locales as $locale) {
            $value = trim((string) ($given[$locale] ?? ''));
            if ($value === '') {
                $names[$locale] = $source;
                $unverified[] = $locale;
            } else {
                $names[$locale] = $value;
            }
        }

        return new self($names, $unverified);
    }

    /**
     * @param  list<string>  $currentlyUnverified
     * @param  array<string, string|null>  $changes
     * @return list<string> locales still unverified after an edit
     */
    public static function unverifiedAfterEdit(array $currentlyUnverified, array $changes): array
    {
        $edited = array_keys(array_filter($changes, fn (?string $v): bool => trim((string) $v) !== ''));

        return array_values(array_diff($currentlyUnverified, $edited));
    }

    /**
     * @return list<string>
     */
    public static function locales(): array
    {
        /** @var list<string> $locales */
        $locales = config('app.supported_locales', ['ro', 'ru', 'en']);

        return $locales;
    }
}
