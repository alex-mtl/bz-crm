<?php

namespace Database\Seeders\Demo;

use App\Domain\Audit\JournalContext;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Carbon;

/**
 * Shared by the demo seeders: every step happens at a moment relative to "now" and on behalf of the persona
 * who would do it, so the journal tells the world's story in order and with the right actors.
 */
trait DemoSteps
{
    private ?Carbon $demoNow = null;

    /**
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     */
    private function at(int $daysAgo, callable $step): mixed
    {
        $this->demoNow ??= Carbon::now();
        Carbon::setTestNow($this->demoNow->copy()->subDays($daysAgo)->setTime(10, 0));

        try {
            return $step();
        } finally {
            Carbon::setTestNow($this->demoNow);
        }
    }

    /**
     * Runs a step as this user (null = an anonymous visitor), then returns to the seeder's system actor.
     *
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     */
    private function as(?User $actor, callable $step): mixed
    {
        $context = app(JournalContext::class);
        $actor !== null ? $context->asUser($actor->id, $actor->person_id) : $context->asGuest();

        try {
            return $step();
        } finally {
            $context->asSystem('seeder:demo');
        }
    }

    private function resetClock(): void
    {
        Carbon::setTestNow();
        app(JournalContext::class)->asSystem('seeder:demo');
    }
}
