<?php

declare(strict_types=1);

namespace App\Infrastructure\ExternalAuth;

use App\Domain\Identity\Contracts\OAuthGateway;
use App\Domain\Identity\Data\ExternalIdentity;
use App\Domain\Identity\Models\AuthProvider;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Socialite adapter. Credentials come from the auth_providers registry, not from .env,
 * so the super admin can add or change providers without a deployment.
 */
final class SocialiteOAuthGateway implements OAuthGateway
{
    public function redirect(AuthProvider $provider): RedirectResponse
    {
        return $this->driver($provider)->redirect();
    }

    public function identity(AuthProvider $provider): ExternalIdentity
    {
        $user = $this->driver($provider)->user();
        $raw = method_exists($user, 'getRaw') ? (array) $user->getRaw() : [];
        [$first, $last] = array_pad(explode(' ', trim((string) $user->getName()), 2), 2, null);

        return new ExternalIdentity(
            provider: $provider->code,
            providerUserId: (string) $user->getId(),
            email: $user->getEmail(),
            // Google reports verification explicitly; Facebook only returns confirmed addresses.
            emailVerified: (bool) ($raw['email_verified'] ?? $raw['verified_email'] ?? $provider->driver === 'facebook'),
            firstName: $raw['given_name'] ?? $raw['first_name'] ?? $first,
            lastName: $raw['family_name'] ?? $raw['last_name'] ?? $last,
        );
    }

    private function driver(AuthProvider $provider): Provider
    {
        config(["services.{$provider->driver}" => [
            'client_id' => $provider->client_id,
            'client_secret' => $provider->client_secret,
            'redirect' => route('oauth.callback', $provider->code),
        ]]);

        $driver = Socialite::driver($provider->driver);
        if ($driver instanceof AbstractProvider && $provider->scopes !== null && $provider->scopes !== []) {
            $driver->scopes($provider->scopes);
        }

        return $driver;
    }
}
