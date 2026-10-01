<?php

declare(strict_types=1);

namespace App\Support\Settings;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Plain key-value storage for system settings. Owning domain modules wrap writes
 * in their own Actions (which also write to the event journal).
 */
final class SystemSettings
{
    private const string CACHE_KEY = 'system_settings:all';

    public function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever(self::CACHE_KEY, fn (): array => DB::table('system_settings')
            ->pluck('value', 'key')
            ->map(fn (?string $json): mixed => $json === null ? null : json_decode($json, true))
            ->all());

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function put(string $key, mixed $value, ?int $userId = null): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_by_user_id' => $userId, 'updated_at' => now()],
        );

        DB::afterCommit(fn () => Cache::forget(self::CACHE_KEY));
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The cache outlives the database (Redis): after migrations (migrate:fresh, a restore) it must be dropped,
     * or stale settings would be read — e.g. which permission codes were already introduced.
     */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
