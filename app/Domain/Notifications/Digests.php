<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationDigest;
use App\Domain\Notifications\Notifications\DigestNotice;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Digests (ФО §6.13 "что произошло в моём регионе"): a daily or a weekly summary for those who asked for it.
 *
 * The content comes from sections registered by the modules; each section queries through the module's own
 * visibility, as the reader — so a digest cannot say more than the reader's screens do. The row of
 * notification_digests is the claim: a second run for the same period finds it and sends nothing.
 */
final class Digests
{
    public const string DAILY = 'daily';

    public const string WEEKLY = 'weekly';

    /** @var array<string, Closure(User, Carbon, Carbon): (array{title: string, lines: list<string>, url?: string|null}|null)> */
    private array $sections = [];

    /**
     * @param  Closure(User, Carbon, Carbon): (array{title: string, lines: list<string>, url?: string|null}|null)  $section
     */
    public function section(string $code, Closure $section): void
    {
        $this->sections[$code] = $section;
    }

    /**
     * @return array{0: Carbon, 1: Carbon} the period that has just ended
     */
    public function period(string $frequency, ?Carbon $now = null): array
    {
        $now ??= now();

        return $frequency === self::WEEKLY
            ? [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()]
            : [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()];
    }

    /**
     * Builds and sends the digests of the period that has just ended. Safe to run again.
     *
     * @return int digests sent by this run
     */
    public function run(string $frequency): int
    {
        [$from, $to] = $this->period($frequency);
        $category = 'digest_'.$frequency;
        $preferences = app(Preferences::class);
        $sent = 0;

        foreach (User::query()->where('status', UserStatus::Active)->orderBy('id')->cursor() as $user) {
            $wanted = $preferences->enabled($user, $category, NotificationCategories::IN_APP)
                || $preferences->enabled($user, $category, NotificationCategories::EMAIL);
            if (! $wanted || NotificationDigest::query()->where('user_id', $user->id)->where('frequency', $frequency)->whereDate('period_start', $from)->exists()) {
                continue;
            }
            $summary = $this->summary($user, $from, $to);
            try {
                $digest = NotificationDigest::query()->create([
                    'user_id' => $user->id, 'frequency' => $frequency, 'period_start' => $from->toDateString(),
                    'period_end' => $to->toDateString(), 'summary' => $summary,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another run got here first.
                continue;
            }
            // Nothing happened — nothing is sent; the row still marks the period as done.
            if ($summary !== []) {
                $user->notify(new DigestNotice($digest));
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @return list<array{code: string, title: string, lines: list<string>, url: string|null}>
     */
    public function summary(User $user, Carbon $from, Carbon $to): array
    {
        $summary = [];
        foreach ($this->sections as $code => $section) {
            $part = $section($user, $from, $to);
            if ($part !== null && $part['lines'] !== []) {
                $summary[] = ['code' => $code, 'title' => $part['title'], 'lines' => $part['lines'], 'url' => $part['url'] ?? null];
            }
        }

        return $summary;
    }
}
