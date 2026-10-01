<?php

declare(strict_types=1);

namespace App\Domain\Social;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Scopes\PersonLocator;
use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use App\Domain\Groups\Models\GroupMember;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Digests;
use App\Domain\Notifications\NotificationCategories;
use App\Domain\Organization\OrgStructure;
use App\Domain\People\PersonReferences;
use App\Domain\Social\Console\PublishScheduledPostsCommand;
use App\Domain\Social\Models\Comment;
use App\Domain\Social\Models\Post;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

final class SocialServiceProvider extends ServiceProvider
{
    public function boot(EventTypeRegistry $types): void
    {
        $types->register(
            new EventType('social.post.created', EventCategory::Business),
            new EventType('social.post.published', EventCategory::Business),
            new EventType('social.post.updated', EventCategory::Business),
            new EventType('social.post.audience_changed', EventCategory::Business, EventSeverity::Notice),
            new EventType('social.post.deleted', EventCategory::Business),
            new EventType('social.post.pinned', EventCategory::Business),
            new EventType('social.post.unpinned', EventCategory::Business),
            new EventType('social.comment.created', EventCategory::Business),
            new EventType('social.comment.deleted', EventCategory::Business),
            new EventType('social.report.created', EventCategory::Security, EventSeverity::Notice),
            new EventType('social.report.dismissed', EventCategory::Security, EventSeverity::Notice),
            new EventType('social.content.hidden', EventCategory::Security, EventSeverity::Warning),
            new EventType('social.content.restored', EventCategory::Security, EventSeverity::Notice),
            new EventType('social.user.warned', EventCategory::Security, EventSeverity::Warning),
            new EventType('social.user.muted', EventCategory::Security, EventSeverity::Warning),
            new EventType('social.user.unmuted', EventCategory::Security, EventSeverity::Notice),
            new EventType('social.user.mute_expired', EventCategory::Security, EventSeverity::Notice),
            new EventType('social.revisions.viewed', EventCategory::Access, EventSeverity::Notice),
        );

        if ($this->app->runningInConsole()) {
            $this->commands([PublishScheduledPostsCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('social:tick')->everyMinute()->withoutOverlapping();
        });

        $this->callAfterResolving(NotificationCategories::class, function (NotificationCategories $categories): void {
            $categories->register('social', 'social');
            // A sanction is not something to unsubscribe from.
            $categories->register('moderation', 'social', [NotificationCategories::IN_APP, NotificationCategories::EMAIL], mandatory: true);
        });

        $this->callAfterResolving(Digests::class, function (Digests $digests): void {
            // What was published for the reader during the period — through the reader's own feed.
            $digests->section('social', function (User $user, Carbon $from, Carbon $to): ?array {
                $posts = $this->app->make(Feed::class)->query($user)->where('posts.author_person_id', '!=', $user->person_id)
                    ->whereBetween('posts.published_at', [$from, $to]);
                $count = (clone $posts)->count();
                if ($count === 0) {
                    return null;
                }
                $lines = (clone $posts)->with('author')->limit(3)->get()
                    ->map(fn (Post $post): string => $post->author->fullName().': '.str($post->body ?? '')->limit(80))->all();

                return ['title' => trans_choice('social.digest.new_posts', $count), 'lines' => $lines, 'url' => '/admin/feed'];
            });
        });

        $this->callAfterResolving(PersonReferences::class, function (PersonReferences $references): void {
            $references->register('posts', 'author_person_id');
            $references->register('post_comments', 'author_person_id');
            $references->register('post_targets', 'person_id');
            $references->register('reactions', 'person_id', ['reactable_type', 'reactable_id']);
            $references->register('author_subscriptions', 'follower_person_id', ['author_person_id']);
            $references->register('author_subscriptions', 'author_person_id', ['follower_person_id']);
            $references->register('moderation_actions', 'person_id');
        });

        $this->callAfterResolving(AuthorizationService::class, fn (AuthorizationService $authorization) => $this->configure($authorization));
    }

    private function configure(AuthorizationService $authorization): void
    {
        // Content is moderated where its author works: a regional moderator and a unit head reach the posts and
        // comments of the people of their scope (catalog §6).
        $org = $this->app->make(OrgStructure::class);
        $byAuthor = fn (): PersonLocator => new PersonLocator(
            $org, fn (object $content): ?int => $content instanceof Post || $content instanceof Comment ? $content->author_person_id : null, 'author_person_id',
        );
        $authorization->registerLocator(Post::class, $byAuthor());
        $authorization->registerLocator(Comment::class, $byAuthor());

        // "Св": a moderator of a group moderates what is published in that group — without any system role.
        $moderatedGroups = fn (User $user): QueryBuilder => GroupMember::query()->where('person_id', $user->person_id)
            ->whereIn('role', GroupMember::MODERATORS)->select('group_id')->toBase();
        $inModeratedGroup = fn (User $user, Post $post): bool => $post->visibility === Post::GROUP && $post->groups()
            ->whereIn('groups.id', $moderatedGroups($user))->exists();

        foreach (['moderation.queue.read', 'moderation.hide', 'moderation.revisions.read'] as $code) {
            $authorization->addRelation($code, AuthorizationService::RELATION_GRANT,
                fn (User $user, object $content): bool => match (true) {
                    $content instanceof Post => $inModeratedGroup($user, $content),
                    $content instanceof Comment => $inModeratedGroup($user, $content->post),
                    default => false,
                },
                function (User $user, Builder $query) use ($moderatedGroups): void {
                    $postColumn = $query->getModel() instanceof Comment ? $query->getModel()->qualifyColumn('post_id') : $query->getModel()->qualifyColumn('id');
                    $query->whereIn($postColumn, fn (QueryBuilder $sub) => $sub->select('mod_p.id')->from('posts as mod_p')
                        ->join('post_groups as mod_pg', 'mod_pg.post_id', '=', 'mod_p.id')
                        ->where('mod_p.visibility', Post::GROUP)->whereIn('mod_pg.group_id', $moderatedGroups($user)));
                });
        }
    }
}
