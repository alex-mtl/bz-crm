<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\AuthProvider;
use Illuminate\Support\Facades\DB;

/**
 * Reference data (ФО §6.1): the starter providers exist everywhere, switched off and without credentials.
 * The super admin enters client ID / secret in the interface and enables them. Idempotent.
 */
final readonly class EnsureStarterAuthProviders
{
    public const array STARTER = [
        ['code' => 'google', 'driver' => 'google', 'display_name' => 'Google', 'sort_order' => 10],
        ['code' => 'facebook', 'driver' => 'facebook', 'display_name' => 'Facebook', 'sort_order' => 20],
    ];

    public function __construct(private EventJournal $journal) {}

    /**
     * @return int number of providers created
     */
    public function __invoke(): int
    {
        $created = 0;
        foreach (self::STARTER as $data) {
            DB::transaction(function () use ($data, &$created): void {
                $provider = AuthProvider::query()->firstOrCreate(['code' => $data['code']], [...$data, 'is_enabled' => false]);
                if ($provider->wasRecentlyCreated) {
                    $this->journal->record('identity.auth_provider.saved', $provider, [], [
                        ...$provider->only(['code', 'driver', 'display_name', 'is_enabled']),
                        'has_secret' => false,
                    ]);
                    $created++;
                }
            });
        }

        return $created;
    }
}
