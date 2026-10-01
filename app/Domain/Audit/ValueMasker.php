<?php

declare(strict_types=1);

namespace App\Domain\Audit;

final class ValueMasker
{
    public const string MASK = '***';

    /** Keys that are never stored in clear text, whatever the event type says. */
    private const array ALWAYS_MASKED = [
        'password', 'password_confirmation', 'current_password', 'remember_token',
        'secret', 'client_secret', 'token', 'access_token', 'refresh_token', 'api_key',
        'two_factor_secret', 'two_factor_recovery_codes', 'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $extraKeys
     * @return array<array-key, mixed>
     */
    public function mask(array $values, array $extraKeys = []): array
    {
        $masked = array_map('strtolower', [...self::ALWAYS_MASKED, ...$extraKeys]);

        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $masked, true)) {
                $values[$key] = $value === null ? null : self::MASK;
            } elseif (is_array($value)) {
                $values[$key] = $this->mask($value, $extraKeys);
            }
        }

        return $values;
    }
}
