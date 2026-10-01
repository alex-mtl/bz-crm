<?php

declare(strict_types=1);

namespace App\Domain\Access;

use InvalidArgumentException;

final readonly class PermissionDefinition
{
    /**
     * @param  list<string>  $defaultRoles  role codes that get this permission in the starter setup
     * @param  array<string, string>  $dataScopes  role code => data layer (own | related) where the starter grant is narrower
     *                                             than the assignment scope (Д-17: e.g. an employee reads only related tasks)
     */
    public function __construct(
        public string $code,
        public string $module,
        public array $defaultRoles = [],
        public bool $reserved = false,
        public array $dataScopes = [],
    ) {
        if ($reserved && $defaultRoles !== []) {
            throw new InvalidArgumentException("Reserved permission [{$code}] cannot have default roles (Д-17).");
        }
    }

    /**
     * Flat key: codes like `audit.read` and `audit.read.confidential_access` cannot both be nested keys.
     */
    public function labelKey(): string
    {
        return 'permissions.'.str_replace('.', '__', $this->code);
    }

    public function label(): string
    {
        return __($this->labelKey());
    }
}
