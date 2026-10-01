<?php

declare(strict_types=1);

namespace App\Support\Phone;

/**
 * Normalizes phone numbers for storage and comparison: digits only, international format
 * without "+". Moldovan local numbers (0XXXXXXXX) become 373XXXXXXXX.
 */
final class PhoneNumber
{
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 9 && $digits[0] === '0') {
            $digits = '373'.substr($digits, 1);
        }

        return strlen($digits) >= 7 ? $digits : null;
    }
}
