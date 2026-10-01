<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The person's own notifications (ФО §6.13 "центр уведомлений"). Nobody reads somebody else's: every query
 * starts from the notifications of the user.
 */
final class NotificationCenter
{
    /**
     * @return MorphMany<DatabaseNotification, User>
     */
    public function for(User $user, bool $unreadOnly = false, ?string $category = null): MorphMany
    {
        $query = $user->notifications();
        if ($unreadOnly) {
            $query->whereNull('read_at');
        }
        if ($category !== null && $category !== '') {
            $query->where('category', $category);
        }

        return $query;
    }

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    public function markRead(User $user, string $notificationId): ?DatabaseNotification
    {
        $notification = $user->notifications()->whereKey($notificationId)->first();
        // A critical notice is read only by confirming it (Announcements::acknowledge).
        if ($notification !== null && $notification->read_at === null && $notification->getAttribute('category') !== 'critical') {
            $notification->markAsRead();
        }

        return $notification;
    }

    public function markAllRead(User $user): int
    {
        return $user->unreadNotifications()->where(fn ($where) => $where->whereNull('category')->orWhere('category', '!=', 'critical'))
            ->update(['read_at' => now()]);
    }

    /**
     * Categories present among the user's notifications — for the filter.
     *
     * @return list<string>
     */
    public function categoriesOf(User $user): array
    {
        return $user->notifications()->reorder()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->map(fn ($code): string => (string) $code)->all();
    }
}
