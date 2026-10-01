<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\AuthProvider;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adds or changes a sign-in provider without code changes (ФО §6.1). The secret is stored encrypted,
 * never shown back, and masked in the journal; an empty secret keeps the stored one.
 */
final readonly class SaveAuthProvider
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    /**
     * @param  array{code: string, driver: string, display_name: string, client_id?: string|null, client_secret?: string|null, scopes?: list<string>|null, is_enabled?: bool, sort_order?: int}  $data
     */
    public function __invoke(User $actor, array $data, ?AuthProvider $provider = null): AuthProvider
    {
        $this->authorization->authorize($actor, 'auth.providers.manage');

        return DB::transaction(function () use ($data, $provider): AuthProvider {
            $provider ??= new AuthProvider;
            $old = $provider->exists ? $this->snapshot($provider) : [];

            $secret = trim((string) ($data['client_secret'] ?? ''));
            unset($data['client_secret']);
            $provider->fill($data);
            if ($secret !== '') {
                $provider->client_secret = $secret;
            }
            $provider->save();

            $this->journal->record('identity.auth_provider.saved', $provider, $old, [
                ...$this->snapshot($provider),
                'secret_changed' => $secret !== '',
            ]);

            return $provider;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(AuthProvider $provider): array
    {
        return [...$provider->only(['code', 'driver', 'display_name', 'client_id', 'scopes', 'is_enabled']), 'has_secret' => $provider->hasSecret()];
    }
}
