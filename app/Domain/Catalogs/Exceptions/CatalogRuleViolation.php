<?php

declare(strict_types=1);

namespace App\Domain\Catalogs\Exceptions;

use DomainException;

final class CatalogRuleViolation extends DomainException
{
    public static function systemItem(): self
    {
        return new self(__('catalogs.errors.system_item'));
    }

    public static function differentCatalogs(): self
    {
        return new self(__('catalogs.errors.different_catalogs'));
    }

    public static function alreadyReviewed(): self
    {
        return new self(__('catalogs.errors.already_reviewed'));
    }

    public static function rejectionNeedsComment(): self
    {
        return new self(__('catalogs.errors.rejection_needs_comment'));
    }

    public static function unknownProperty(string $key): self
    {
        return new self(__('catalogs.errors.unknown_property', ['key' => $key]));
    }
}
