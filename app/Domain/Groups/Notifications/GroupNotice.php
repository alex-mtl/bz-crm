<?php

declare(strict_types=1);

namespace App\Domain\Groups\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * In-app notices of groups: an invitation, a decision on a join request. Sent only to the person concerned,
 * so the name of a secret group reaches nobody else.
 */
final class GroupNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const string INVITED = 'invited';

    public const string REQUEST_APPROVED = 'request_approved';

    public const string REQUEST_REJECTED = 'request_rejected';

    public function __construct(public readonly string $kind, public readonly string $groupName, public readonly int $groupId) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'format' => 'filament',
            'title' => __('groups.notices.'.$this->kind, ['group' => $this->groupName]),
            'body' => null,
            'icon' => 'heroicon-o-user-group',
            'iconColor' => 'info',
            'duration' => 'persistent',
            'actions' => [[
                'name' => 'open',
                'label' => __('groups.notices.open'),
                'url' => $this->kind === self::INVITED ? '/admin/groups' : '/admin/groups/'.$this->groupId,
                'shouldMarkAsRead' => true,
            ]],
            'kind' => 'group_'.$this->kind,
            'group_id' => $this->groupId,
        ];
    }
}
