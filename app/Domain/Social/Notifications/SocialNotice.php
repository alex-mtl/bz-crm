<?php

declare(strict_types=1);

namespace App\Domain\Social\Notifications;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Concerns\RoutesByPreference;
use App\Domain\Social\Models\Post;
use App\Domain\Social\PostVisibility;
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
    use RoutesByPreference;

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

    public function category(): string
    {
        return in_array($this->kind, [self::WARNING, self::MUTED, self::HIDDEN], true) ? 'moderation' : 'social';
    }

    /**
     * Checked at the moment of delivery: a notice about a post that its recipient can no longer see — hidden,
     * deleted or narrowed while the notice waited in the queue — is not delivered (ТЗ §37).
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($this->postId === null || $this->category() === 'moderation' || ! $notifiable instanceof User) {
            return true;
        }
        $post = Post::query()->find($this->postId);

        return $post !== null && app(PostVisibility::class)->canSee($notifiable, $post);
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
            'subject' => $this->postId !== null ? ['post', $this->postId] : null,
        ];
    }
}
