<?php

declare(strict_types=1);

namespace App\Domain\Social\Exceptions;

use DomainException;

final class SocialRuleViolation extends DomainException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $key, array $replace = []): self
    {
        return new self(__('social.errors.'.$key, $replace));
    }
}
