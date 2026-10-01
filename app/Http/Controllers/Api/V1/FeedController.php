<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AuthorizationService;
use App\Domain\Identity\Models\User;
use App\Domain\Social\Feed;
use App\Domain\Social\Models\Post;
use App\Domain\Social\PostCards;
use App\Domain\Social\PostVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The feed over the API (ТЗ §18, ADR-010). The same Feed and PostVisibility as the screen: a post the user
 * does not see is absent from the list and answers 404 by its id — its existence is not disclosed.
 */
final class FeedController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly Feed $feed,
        private readonly PostVisibility $visibility,
        private readonly PostCards $cards,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'mode' => ['nullable', 'in:'.implode(',', Feed::MODES)],
            'search' => ['nullable', 'string', 'max:200'],
            'group_id' => ['nullable', 'integer'],
            'territory_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $page = $this->feed->query($user, $validated['mode'] ?? Feed::ALL, $validated)
            ->simplePaginate((int) ($validated['per_page'] ?? 20));
        $posts = Collection::make($page->items());

        return response()->json([
            'data' => $this->present($user, $posts),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'has_more' => $page->hasMorePages()],
        ]);
    }

    public function show(Request $request, int $post): JsonResponse
    {
        $user = $this->user($request);
        $found = $this->visibility->visibleTo($user, true)->whereKey($post)->get();
        abort_if($found->isEmpty(), 404);

        return response()->json(['data' => $this->present($user, $found)[0]]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->authorization->authorize($user, 'posts.read');

        return $user;
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return list<array<string, mixed>>
     */
    private function present(User $viewer, Collection $posts): array
    {
        $cards = $this->cards->for($viewer, $posts);

        $data = [];
        foreach ($posts as $post) {
            $card = $cards[$post->id];
            $data[] = [
                'id' => $post->id,
                'author' => ['person_id' => $post->author_person_id, 'name' => $post->author->fullName()],
                'body' => $post->body,
                'status' => $post->status,
                'visibility' => $post->visibility,
                'audience' => $card['audience'],
                'published_at' => $post->published_at?->toIso8601String(),
                'edited' => $post->edited_at !== null,
                'hidden' => $post->isHidden(),
                'repost_of' => $card['original'] !== null
                    ? ['id' => $card['original']->id, 'author' => $card['original']->author->fullName(), 'body' => $card['original']->body]
                    : null,
                'repost_unavailable' => $card['original_unavailable'],
                'attachments' => $post->attachments->map(fn ($attachment): array => [
                    'id' => $attachment->id, 'name' => $attachment->original_name, 'kind' => $attachment->kind,
                    'size' => $attachment->size, 'url' => route('social.attachment', $attachment),
                ])->all(),
                'poll' => $card['poll'],
                'reactions' => $card['reactions'],
                'my_reaction' => $card['my_reaction'],
                'comments' => $card['comments'],
                'pinned' => array_map(fn ($pin): array => ['scope' => $pin->scope, 'scope_id' => $pin->scope_id], $card['pins']),
            ];
        }

        return $data;
    }
}
