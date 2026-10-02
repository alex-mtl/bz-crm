<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Exceptions\MessagingRuleViolation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\MessageAttachment;
use App\Support\Settings\SystemSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Д-27 (ФО §6.6.4): messages are kept indefinitely; the super admin may set a term, and then the scheduler
 * removes what is older — files included — with a journal entry for every run that removed something.
 */
final readonly class Retention
{
    public const string SETTING = 'messaging.retention_months';

    public function __construct(
        private SystemSettings $settings,
        private AuthorizationService $authorization,
        private EventJournal $journal,
    ) {}

    /**
     * @return int|null months; null — indefinitely
     */
    public function months(): ?int
    {
        $value = $this->settings->get(self::SETTING);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public function set(User $actor, ?int $months): void
    {
        $this->authorization->authorize($actor, 'messaging.policies.manage');
        if ($months !== null && ($months < 1 || $months > 600)) {
            throw MessagingRuleViolation::because('invalid_retention');
        }
        $old = $this->months();
        if ($old === $months) {
            return;
        }

        DB::transaction(function () use ($actor, $months, $old): void {
            $this->settings->put(self::SETTING, $months, $actor->id);
            $this->journal->record('messaging.retention.changed', $actor, ['months' => $old], ['months' => $months]);
        });
    }

    /**
     * @return int messages removed
     */
    public function purge(): int
    {
        $months = $this->months();
        if ($months === null) {
            return 0;
        }
        $before = now()->subMonths($months);
        $removed = 0;

        Message::query()->where('status', Message::SENT)->where('created_at', '<', $before)->orderBy('id')
            ->chunkById(500, function ($messages) use (&$removed): void {
                $ids = $messages->pluck('id')->all();
                Storage::disk('local')->delete(MessageAttachment::query()->whereIn('message_id', $ids)->pluck('path')->all());
                $removed += Message::query()->whereKey($ids)->delete();
            });

        if ($removed > 0) {
            $this->journal->record('messaging.retention.purged', null, [], ['messages' => $removed, 'older_than_months' => $months]);
        }

        return $removed;
    }
}
