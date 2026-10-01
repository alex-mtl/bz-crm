<?php

declare(strict_types=1);

namespace App\Domain\Social\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Domain\Social\Exceptions\SocialRuleViolation;
use App\Domain\Social\Models\Comment;
use App\Domain\Social\Models\Post;
use App\Domain\Social\Models\Reaction;
use App\Domain\Social\Moderation;
use App\Domain\Social\Notifications\SocialNotice;
use App\Domain\Social\PostVisibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Comments, reactions and subscriptions (ФО §6.4.2–6.4.3). None of them has a visibility of its own: whoever
 * does not see the post can neither read, nor write, nor react under it.
 */
final readonly class ManageComments
{
    public function __construct(
        private AuthorizationService $authorization,
        private PostVisibility $visibility,
        private Moderation $moderation,
        private EventJournal $journal,
    ) {}

    public function add(User $actor, Post $post, string $body, ?Comment $parent = null, ?Comment $quoted = null): Comment
    {
        $this->authorization->authorize($actor, 'comments.create');
        $this->ensureOpen($actor, $post);
        $this->moderation->ensureNotMuted($actor);
        $body = trim($body);
        if ($body === '') {
            throw SocialRuleViolation::because('empty_comment');
        }
        foreach ([$parent, $quoted] as $other) {
            if ($other !== null && ($other->post_id !== $post->id || $other->deleted_at !== null || $other->isHidden())) {
                throw SocialRuleViolation::because('comment_of_other_post');
            }
        }

        $comment = DB::transaction(function () use ($actor, $post, $body, $parent, $quoted): Comment {
            $comment = Comment::query()->create([
                'post_id' => $post->id,
                'parent_id' => $parent?->id,
                'quoted_comment_id' => $quoted?->id,
                // The tree is kept readable: beyond the maximum depth replies stay on the last level.
                'depth' => $parent !== null ? min($parent->depth + 1, Comment::MAX_DEPTH) : 0,
                'author_person_id' => $actor->person_id,
                'body' => $body,
            ]);
            $this->journal->record('social.comment.created', $comment, [], ['post_id' => $post->id, 'parent_id' => $parent?->id]);

            return $comment;
        });

        // The author of the post, and the author of the comment answered: told only if they still see the post.
        $recipients = array_unique(array_filter([
            SocialNotice::COMMENT => $post->author_person_id !== $actor->person_id ? $post->author_person_id : null,
            SocialNotice::REPLY => $parent !== null && $parent->author_person_id !== $actor->person_id ? $parent->author_person_id : null,
        ]));
        foreach ($recipients as $kind => $personId) {
            $user = User::query()->where('person_id', $personId)->first();
            if ($user !== null && $this->visibility->canSee($user, $post)) {
                $user->notify(new SocialNotice($kind, ['name' => $actor->person->fullName()], $post->id));
            }
        }

        return $comment;
    }

    public function delete(User $actor, Comment $comment): void
    {
        if ($comment->author_person_id !== $actor->person_id) {
            throw new AuthorizationException(__('access.denied'));
        }

        DB::transaction(function () use ($comment): void {
            $comment->update(['deleted_at' => now()]);
            $this->journal->record('social.comment.deleted', $comment, [], ['post_id' => $comment->post_id]);
        });
    }

    /**
     * Comments of a post as the viewer reads them: a flat list in tree order with the depth of each.
     * Hidden and deleted comments keep their place (the thread stays readable) but lose their text.
     *
     * @return Collection<int, Comment>
     */
    public function thread(User $viewer, Post $post): Collection
    {
        if (! $this->visibility->canSee($viewer, $post)) {
            return collect();
        }
        $byParent = Comment::query()->with(['author', 'quoted.author'])->where('post_id', $post->id)->orderBy('id')->get()->groupBy(fn (Comment $c) => $c->parent_id ?? 0);
        $ordered = collect();
        $walk = function (int $parentId) use (&$walk, $byParent, $ordered): void {
            foreach ($byParent->get($parentId, collect()) as $comment) {
                $ordered->push($comment);
                $walk($comment->id);
            }
        };
        $walk(0);

        return $ordered;
    }

    /**
     * Sets, changes or — when the same reaction is given again — removes the reaction of the person.
     */
    public function react(User $actor, Post|Comment $target, string $reactionCode): ?Reaction
    {
        $this->authorization->authorize($actor, 'reactions.add');
        $this->ensureOpen($actor, $target instanceof Post ? $target : $target->post);
        if ($target instanceof Comment && ($target->deleted_at !== null || $target->isHidden())) {
            throw new AuthorizationException(__('access.denied'));
        }
        if (! CatalogItem::query()->ofCatalog('reaction_types')->selectable()->where('code', $reactionCode)->exists()) {
            throw SocialRuleViolation::because('invalid_reaction');
        }
        $key = ['reactable_type' => $target instanceof Post ? Reaction::POST : Reaction::COMMENT, 'reactable_id' => $target->id, 'person_id' => $actor->person_id];
        $existing = Reaction::query()->where($key)->first();
        if ($existing !== null && $existing->reaction_code === $reactionCode) {
            $existing->delete();

            return null;
        }

        return Reaction::query()->updateOrCreate($key, ['reaction_code' => $reactionCode]);
    }

    /**
     * Follows an author: their new posts — those the follower can see — are announced to the follower.
     */
    public function follow(User $actor, Person $author): void
    {
        $this->authorization->authorize($actor, 'posts.read');
        $this->authorization->authorize($actor, 'people.read', $author);
        if ($author->id === $actor->person_id) {
            throw SocialRuleViolation::because('follow_self');
        }
        DB::table('author_subscriptions')->insertOrIgnore([
            'follower_person_id' => $actor->person_id, 'author_person_id' => $author->id, 'created_at' => now(),
        ]);
    }

    public function isFollowing(User $actor, Person $author): bool
    {
        return DB::table('author_subscriptions')->where('follower_person_id', $actor->person_id)->where('author_person_id', $author->id)->exists();
    }

    public function unfollow(User $actor, Person $author): void
    {
        DB::table('author_subscriptions')->where('follower_person_id', $actor->person_id)->where('author_person_id', $author->id)->delete();
    }

    private function ensureOpen(User $actor, Post $post): void
    {
        if (! $post->isPublished() || $post->isHidden() || ! $this->visibility->canSee($actor, $post)) {
            throw new AuthorizationException(__('access.denied'));
        }
    }
}
