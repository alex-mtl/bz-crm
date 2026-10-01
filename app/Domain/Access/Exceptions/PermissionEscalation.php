<?php

declare(strict_types=1);

namespace App\Domain\Access\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * "Not more than you have" (Д-3, Д-17): granting rights the granter does not hold.
 */
final class PermissionEscalation extends AuthorizationException
{
    /**
     * @param  list<string>  $missing
     */
    public static function missing(array $missing): self
    {
        return new self(__('access.escalation', ['codes' => implode(', ', $missing)]));
    }
}
