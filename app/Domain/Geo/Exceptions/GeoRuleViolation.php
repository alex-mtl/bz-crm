<?php

declare(strict_types=1);

namespace App\Domain\Geo\Exceptions;

use DomainException;

final class GeoRuleViolation extends DomainException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $key, array $replace = []): self
    {
        return new self(__('geo.errors.'.$key, $replace));
    }
}
