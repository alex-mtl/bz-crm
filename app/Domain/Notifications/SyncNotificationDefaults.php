<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Access\Models\Role;
use App\Domain\Notifications\Models\NotificationDefault;

/**
 * The starter defaults by role (ФО §6.13). Only missing rows are added: what the administrator changed stays.
 */
final class SyncNotificationDefaults
{
    /** role => category => channel => enabled */
    private const array STARTER = [
        // Those who answer for an area get the daily digest; the weekly one is on for everyone by its category.
        'org_head' => ['digest_daily' => ['in_app' => true, 'email' => true]],
        'unit_head' => ['digest_daily' => ['in_app' => true, 'email' => false]],
        // Outsiders on their way in are not flooded with e-mail.
        'candidate' => ['digest_weekly' => ['in_app' => false, 'email' => false], 'events' => ['email' => false], 'event_reminders' => ['email' => false]],
        'volunteer' => ['digest_weekly' => ['email' => false]],
    ];

    public function __invoke(): int
    {
        $created = 0;
        foreach (self::STARTER as $roleCode => $categories) {
            $roleId = Role::query()->where('code', $roleCode)->value('id');
            if ($roleId === null) {
                continue;
            }
            foreach ($categories as $category => $channels) {
                foreach ($channels as $channel => $enabled) {
                    $row = NotificationDefault::query()->firstOrCreate(
                        ['role_id' => $roleId, 'category' => $category, 'channel' => $channel], ['enabled' => $enabled],
                    );
                    $created += $row->wasRecentlyCreated ? 1 : 0;
                }
            }
        }

        return $created;
    }
}
