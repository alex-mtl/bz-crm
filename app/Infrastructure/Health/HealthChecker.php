<?php

declare(strict_types=1);

namespace App\Infrastructure\Health;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class HealthChecker
{
    /**
     * @return array<string, bool>
     */
    public function run(): array
    {
        return [
            'database' => $this->check('database', fn () => DB::select('select 1')),
            'redis' => $this->check('redis', fn () => Redis::connection()->ping()),
            'cache' => $this->check('cache', function (): void {
                $key = 'health:'.Str::random(8);
                Cache::put($key, 'ok', 10);
                if (Cache::pull($key) !== 'ok') {
                    throw new \RuntimeException('cache round-trip failed');
                }
            }),
            'storage' => $this->check('storage', function (): void {
                $path = 'health/'.Str::random(8);
                Storage::disk('local')->put($path, 'ok');
                Storage::disk('local')->delete($path);
            }),
        ];
    }

    public function failedJobsCount(): ?int
    {
        try {
            return DB::table('failed_jobs')->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, bool>  $results
     */
    public static function allHealthy(array $results): bool
    {
        return ! in_array(false, $results, true);
    }

    private function check(string $name, Closure $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable $e) {
            Log::warning('health check failed', ['check' => $name, 'exception' => $e::class]);

            return false;
        }
    }
}
