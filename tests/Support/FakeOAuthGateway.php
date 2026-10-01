<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Identity\Contracts\OAuthGateway;
use App\Domain\Identity\Data\ExternalIdentity;
use App\Domain\Identity\Models\AuthProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Stands in for Socialite: returns the identity the test prepared.
 */
final class FakeOAuthGateway implements OAuthGateway
{
    public ?ExternalIdentity $next = null;

    public static function install(): self
    {
        $fake = new self;
        app()->instance(OAuthGateway::class, $fake);

        return $fake;
    }

    public function returns(string $providerUserId, ?string $email, bool $emailVerified = true, string $firstName = 'Ion', ?string $lastName = 'Popescu', string $provider = 'google'): self
    {
        $this->next = new ExternalIdentity($provider, $providerUserId, $email, $emailVerified, $firstName, $lastName);

        return $this;
    }

    public function redirect(AuthProvider $provider): RedirectResponse
    {
        return new RedirectResponse('https://provider.example/authorize?client='.$provider->code);
    }

    public function identity(AuthProvider $provider): ExternalIdentity
    {
        return $this->next ?? throw new \RuntimeException('No fake identity prepared');
    }
}
