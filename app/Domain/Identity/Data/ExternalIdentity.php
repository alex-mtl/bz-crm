<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

/**
 * What an external sign-in provider tells us about a person.
 */
final readonly class ExternalIdentity
{
    public function __construct(
        public string $provider,
        public string $providerUserId,
        public ?string $email,
        public bool $emailVerified,
        public ?string $firstName,
        public ?string $lastName,
    ) {}
}
