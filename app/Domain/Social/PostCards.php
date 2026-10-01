<?php

declare(strict_types=1);

namespace App\Domain\Social;

use App\Domain\Access\Models\Role;
use App\Domain\Groups\GroupAccess;
use App\Domain\Identity\Models\User;
use App\Domain\Social\Models\Comment;
use App\Domain\Social\Models\PollVote;
use App\Domain\Social\Models\Post;
use App\Domain\Social\Models\PostPin;
use App\Domain\Social\Models\Reaction;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What surrounds a post on the screen and in the API — reactions, the poll, the audience, the reposted original —
 * as this viewer may see it. The posts themselves come from Feed / PostVisibility; nothing here widens them:
 * an original the viewer cannot open is reported as unavailable, a secret group in the audience is not named.
 */
final readonly class PostCards
{
    public function __construct(
        private PostVisibility $visibility,
        private GroupAccess $groups,
    ) {}

    /**
     * @param  Collection<int, Post>  $posts
     * @return array<int, array{
     *     reactions: array<string, int>, my_reaction: string|null, comments: int,
     *     poll: array{options: list<array{id: int, text: string, votes: int}>, total: int, my_option: int|null}|null,
     *     audience: list<string>, pins: list<PostPin>, original: Post|null, original_unavailable: bool, following: bool
     * }>
     */
    public function for(User $viewer, Collection $posts): array
    {
        if ($posts->isEmpty()) {
            return [];
        }
        (new EloquentCollection($posts->all()))->loadMissing(['author', 'attachments', 'pollOptions', 'territories', 'groups', 'targets', 'pins']);
        $ids = $posts->pluck('id')->all();

        $reactions = Reaction::query()->where('reactable_type', Reaction::POST)->whereIn('reactable_id', $ids)
            ->selectRaw('reactable_id, reaction_code, count(*) as total')->groupBy('reactable_id', 'reaction_code')->get()->groupBy('reactable_id');
        $mine = Reaction::query()->where('reactable_type', Reaction::POST)->whereIn('reactable_id', $ids)
            ->where('person_id', $viewer->person_id)->pluck('reaction_code', 'reactable_id');
        $comments = Comment::query()->whereIn('post_id', $ids)->whereNull('deleted_at')->whereNull('hidden_at')
            ->selectRaw('post_id, count(*) as total')->groupBy('post_id')->pluck('total', 'post_id');
        $votes = PollVote::query()->whereIn('post_id', $ids)->selectRaw('option_id, count(*) as total')->groupBy('option_id')->pluck('total', 'option_id');
        $myVotes = PollVote::query()->whereIn('post_id', $ids)->where('person_id', $viewer->person_id)->pluck('option_id', 'post_id');
        $following = DB::table('author_subscriptions')->where('follower_person_id', $viewer->person_id)
            ->whereIn('author_person_id', $posts->pluck('author_person_id')->unique()->all())->pluck('author_person_id')->flip();

        $originalIds = $posts->pluck('repost_of_post_id')->filter()->unique()->all();
        $originals = $originalIds === [] ? collect() : $this->visibility->visibleTo($viewer)->with(['author', 'attachments'])
            ->whereIn('posts.id', $originalIds)->whereNull('posts.hidden_at')->get()->keyBy('id');
        $visibleGroupIds = $this->groups->visible($viewer)->pluck('id')->flip();
        $roleNames = Role::query()->get()->mapWithKeys(fn (Role $role): array => [$role->code => $role->name()]);

        $cards = [];
        foreach ($posts as $post) {
            $options = $post->pollOptions->map(fn ($option): array => [
                'id' => (int) $option->id, 'text' => (string) $option->text, 'votes' => (int) ($votes[$option->id] ?? 0),
            ])->values()->all();

            $cards[$post->id] = [
                'reactions' => ($reactions[$post->id] ?? collect())->mapWithKeys(fn ($row): array => [(string) $row->reaction_code => (int) $row->getAttribute('total')])->all(),
                'my_reaction' => isset($mine[$post->id]) ? (string) $mine[$post->id] : null,
                'comments' => (int) ($comments[$post->id] ?? 0),
                'poll' => $options === [] ? null : [
                    'options' => $options, 'total' => array_sum(array_column($options, 'votes')),
                    'my_option' => isset($myVotes[$post->id]) ? (int) $myVotes[$post->id] : null,
                ],
                'audience' => $this->audience($post, $visibleGroupIds, $roleNames),
                'pins' => $post->pins->all(),
                'original' => $post->repost_of_post_id !== null ? $originals->get($post->repost_of_post_id) : null,
                'original_unavailable' => $post->repost_of_post_id !== null && ! $originals->has($post->repost_of_post_id),
                'following' => $following->has($post->author_person_id),
            ];
        }

        return $cards;
    }

    /**
     * Reactions under the comments of a thread: counts by reaction and the viewer's own.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array<int, array{reactions: array<string, int>, my_reaction: string|null}>
     */
    public function commentReactions(User $viewer, Collection $comments): array
    {
        $result = [];
        if ($comments->isEmpty()) {
            return $result;
        }
        $rows = Reaction::query()->where('reactable_type', Reaction::COMMENT)->whereIn('reactable_id', $comments->pluck('id')->all())->get();
        foreach ($rows as $row) {
            $result[$row->reactable_id] ??= ['reactions' => [], 'my_reaction' => null];
            $result[$row->reactable_id]['reactions'][$row->reaction_code] = ($result[$row->reactable_id]['reactions'][$row->reaction_code] ?? 0) + 1;
            if ($row->person_id === $viewer->person_id) {
                $result[$row->reactable_id]['my_reaction'] = $row->reaction_code;
            }
        }

        return $result;
    }

    /**
     * To whom the post is addressed, in words. People of a targeted post are counted, not named.
     *
     * @param  Collection<int|string, mixed>  $visibleGroupIds
     * @param  Collection<string, string>  $roleNames
     * @return list<string>
     */
    private function audience(Post $post, Collection $visibleGroupIds, Collection $roleNames): array
    {
        return match ($post->visibility) {
            Post::REGIONAL => $post->territories->map(fn ($territory): string => $territory->name())->values()->all(),
            Post::GROUP => $post->groups->filter(fn ($group): bool => $visibleGroupIds->has($group->id))->map(fn ($group): string => $group->name)->values()->all(),
            Post::TARGETED => array_values(array_filter([
                ...$post->targets->whereNotNull('role_code')->map(fn ($target): string => (string) ($roleNames[$target->role_code] ?? $target->role_code))->all(),
                $post->targets->whereNotNull('person_id')->count() > 0
                    ? trans_choice('social.audience.people', $post->targets->whereNotNull('person_id')->count()) : null,
            ])),
            default => [],
        };
    }
}
