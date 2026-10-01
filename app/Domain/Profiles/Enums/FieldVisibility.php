<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Enums;

/**
 * The owner's choice of who sees a profile field (ФО §6.3.1, Д-13). The circles are nested:
 * management ⊂ colleagues ⊂ region ⊂ all — whoever sees a narrower circle also sees the wider ones.
 */
enum FieldVisibility: string
{
    case Management = 'management';
    case Colleagues = 'colleagues';
    case Region = 'region';
    case All = 'all';

    public function label(): string
    {
        return __('profiles.visibility.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
