<?php

declare(strict_types=1);

namespace App\Domain\Identity\Contracts;

use App\Domain\Identity\Data\ExternalIdentity;
use App\Domain\Identity\Models\AuthProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Port for external sign-in (ADR-007). Implemented in Infrastructure\ExternalAuth.
 */
interface OAuthGateway
{
    public function redirect(AuthProvider $provider): RedirectResponse;

    public function identity(AuthProvider $provider): ExternalIdentity;
}
