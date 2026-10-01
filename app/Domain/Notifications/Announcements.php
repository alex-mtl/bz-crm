<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Exceptions\NotificationRuleViolation;
use App\Domain\Notifications\Models\Announcement;
use App\Domain\Notifications\Models\AnnouncementReceipt;
use App\Domain\Notifications\Notifications\AnnouncementNotice;
use App\Domain\People\Models\Person;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Announcements (ФО §6.13): an ordinary one — to the people of the sender's own area; a critical one — from the
 * security service or the head of the organization, with a confirmation of reading from every recipient.
 */
final readonly class Announcements
{
    public function __construct(
        private AuthorizationService $authorization,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array{title: string, body: string, critical?: bool, audience?: array<string, mixed>}  $data
     * @param  Builder<Person>|null  $people  whom to tell (built by the caller); the sender's scope is applied here
     */
    public function send(User $actor, array $data, ?Builder $people = null): Announcement
    {
        $critical = (bool) ($data['critical'] ?? false);
        $code = $critical ? 'notifications.critical.send' : 'notifications.broadcast';
        $this->authorization->authorize($actor, $code);
        $title = trim($data['title']);
        $body = trim($data['body']);
        if ($title === '' || $body === '') {
            throw NotificationRuleViolation::because('announcement_empty');
        }

        // "Только получатели в области": whoever is outside the sender's scope is silently not a recipient.
        $recipients = User::query()->where('status', UserStatus::Active)->where('id', '!=', $actor->id)
            ->whereIn('person_id', $this->authorization->scopeQuery($actor, $code, $people ?? Person::query())->select('people.id'))
            ->get();
        if ($recipients->isEmpty()) {
            throw NotificationRuleViolation::because('announcement_no_recipients');
        }

        $announcement = DB::transaction(function () use ($actor, $title, $body, $critical, $data, $recipients): Announcement {
            $announcement = Announcement::query()->create([
                'sender_user_id' => $actor->id, 'title' => $title, 'body' => $body, 'is_critical' => $critical,
                'audience' => $data['audience'] ?? null, 'recipients' => $recipients->count(),
            ]);
            AnnouncementReceipt::query()->insert($recipients->map(fn (User $user): array => [
                'announcement_id' => $announcement->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ])->all());
            $this->journal->record($critical ? 'notifications.critical.sent' : 'notifications.announcement.sent', $announcement, [], [
                'title' => $title, 'recipients' => $recipients->count(), 'audience' => $data['audience'] ?? null,
            ]);

            return $announcement;
        });

        foreach ($recipients as $recipient) {
            $recipient->notify(new AnnouncementNotice($announcement));
        }

        return $announcement;
    }

    /**
     * "I have read it" — only the recipient, only once.
     */
    public function acknowledge(User $user, Announcement $announcement): void
    {
        $receipt = AnnouncementReceipt::query()->where('announcement_id', $announcement->id)->where('user_id', $user->id)->first();
        if ($receipt === null) {
            throw new AuthorizationException(__('access.denied'));
        }
        if ($receipt->acknowledged_at !== null) {
            return;
        }

        DB::transaction(function () use ($user, $announcement, $receipt): void {
            $receipt->update(['acknowledged_at' => now()]);
            $user->unreadNotifications()->where('subject_type', 'announcement')->where('subject_id', $announcement->id)->update(['read_at' => now()]);
            $this->journal->record('notifications.critical.acknowledged', $announcement);
        });
    }

    /**
     * Critical announcements the user has not confirmed yet — shown on every page until they do.
     *
     * @return Builder<Announcement>
     */
    public function pendingFor(User $user): Builder
    {
        return Announcement::query()->where('is_critical', true)
            ->whereIn('id', AnnouncementReceipt::query()->where('user_id', $user->id)->whereNull('acknowledged_at')->select('announcement_id'))
            ->orderBy('id');
    }

    /**
     * What the user sent, and — for the holders of the critical right — every critical announcement.
     *
     * @return Builder<Announcement>
     */
    public function sentVisibleTo(User $user): Builder
    {
        $query = Announcement::query();
        if ($this->authorization->can($user, 'notifications.critical.send')) {
            return $query->where(fn (Builder $where) => $where->where('sender_user_id', $user->id)->orWhere('is_critical', true));
        }

        return $query->where('sender_user_id', $user->id);
    }

    public function maySeeReceipts(User $user, Announcement $announcement): bool
    {
        return $this->sentVisibleTo($user)->whereKey($announcement->id)->exists();
    }
}
