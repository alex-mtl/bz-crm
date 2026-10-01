<?php

declare(strict_types=1);

namespace App\Domain\CustomObjects\Exceptions;

use DomainException;

final class CustomFieldViolation extends DomainException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $key, array $replace = []): self
    {
        return new self(__('custom_fields.errors.'.$key, $replace));
    }
}
