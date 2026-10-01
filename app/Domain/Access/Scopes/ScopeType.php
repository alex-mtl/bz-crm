<?php

declare(strict_types=1);

namespace App\Domain\Access\Scopes;

/**
 * Scope of a role assignment (ФО §6.2, ADR-008). Stored in user_roles.scope_type; null means organization.
 */
enum ScopeType: string
{
    case Organization = 'organization';
    /** a fixed unit and its descendants */
    case OrgUnit = 'org_unit';
    /** a fixed territory and its subtree */
    case Territory = 'territory';
    /** the holder's own unit and its descendants — follows the holder on transfer */
    case OwnUnit = 'own_unit';
    /** the holder's effective territories (Д-3) — follows the holder */
    case OwnTerritories = 'own_territories';

    public static function fromStored(?string $value): self
    {
        return $value === null ? self::Organization : self::from($value);
    }

    public function stored(): ?string
    {
        return $this === self::Organization ? null : $this->value;
    }

    public function needsId(): bool
    {
        return $this === self::OrgUnit || $this === self::Territory;
    }

    public function label(): string
    {
        return __('access.scopes.'.$this->value);
    }
}
