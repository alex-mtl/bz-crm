<?php

declare(strict_types=1);

namespace App\Support\Translation;

/**
 * For models that keep their name in name_ro / name_ru / name_en (Д-16).
 */
trait HasTranslatedName
{
    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) ($this->getAttribute('name_'.$locale) ?? $this->getAttribute('name_ro'));
    }
}
