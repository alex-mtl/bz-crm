<?php

declare(strict_types=1);

namespace App\Domain\Social\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * In-app notices of the social network. The text names a person and, at most, the fact — never the content of a
 * post: who is notified is decided before sending, by the same visibility rule as the feed (ТЗ §18).
 */
final class SocialNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const string NEW_POST = 'new_post';

    public const string COMMENT = 'comment';

    public const string REPLY = 'reply';

    public const string WARNING = 'warning';

    public const string MUTED = 'muted';

    public const string HIDDEN = 'hidden';

    /**
     * @param  array<string, string>  $replace
     */
    public function __construct(public readonly string $kind, public readonly array $replace = [], public readonly ?int $postId = null) {}

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
        $severe = in_array($this->kind, [self::WARNING, self::MUTED, self::HIDDEN], true);

        return [
            'format' => 'filament',
            'title' => __('social.notices.'.$this->kind, $this->replace),
            'body' => $this->replace['reason'] ?? null,
            'icon' => $severe ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-chat-bubble-left-ellipsis',
            'iconColor' => $severe ? 'warning' : 'info',
            'duration' => 'persistent',
            'actions' => $this->postId !== null ? [[
                'name' => 'open',
                'label' => __('social.notices.open'),
                'url' => '/admin/feed?post='.$this->postId,
                'shouldMarkAsRead' => true,
            ]] : [],
            'kind' => 'social_'.$this->kind,
            'post_id' => $this->postId,
        ];
    }
}
