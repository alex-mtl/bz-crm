<?php

declare(strict_types=1);

namespace App\Domain\Social;

use App\Domain\Access\TerritorialAccess;
use App\Domain\Geo\Models\Territory;
use App\Domain\Groups\GroupAccess;
use App\Domain\Identity\Models\User;
use App\Domain\Social\Models\Post;
use App\Domain\Social\Models\PostPin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The feed (ФО §6.4.2, ТЗ §18): a query over the canonical posts, never a copy. Every mode starts from
 * PostVisibility, so a mode, a filter or a search can only narrow what the viewer may see.
 */
final readonly class Feed
{
    public const string ALL = 'all';

    public const string IMPORTANT = 'important';

    public const string GROUPS = 'groups';

    public const string REGION = 'region';

    public const string FOLLOWING = 'following';

    public const string MINE = 'mine';

    public const array MODES = [self::ALL, self::IMPORTANT, self::GROUPS, self::REGION, self::FOLLOWING, self::MINE];

    public function __construct(
        private PostVisibility $visibility,
        private GroupAccess $groups,
        private TerritorialAccess $territories,
    ) {}

    /**
     * @param  array{group_id?: int|null, territory_id?: int|null, search?: string|null}  $filters
     * @return Builder<Post>
     */
    public function query(User $viewer, string $mode = self::ALL, array $filters = []): Builder
    {
        $query = $this->visibility->visibleTo($viewer, withOwnUnpublished: $mode === self::MINE);

        switch ($mode) {
            case self::MINE:
                $query->where('posts.author_person_id', $viewer->person_id);
                break;
            case self::IMPORTANT:
                // What the leadership pinned — for everyone, for the viewer's territories, in the viewer's groups.
                $query->whereIn('posts.id', $this->pinnedFor($viewer));
                break;
            case self::GROUPS:
                $query->where('posts.visibility', Post::GROUP);
                break;
            case self::REGION:
                $query->where('posts.visibility', Post::REGIONAL);
                break;
            case self::FOLLOWING:
                $query->whereIn('posts.author_person_id', fn (QueryBuilder $sub) => $sub->select('author_person_id')
                    ->from('author_subscriptions')->where('follower_person_id', $viewer->person_id));
                break;
        }

        if (filled($filters['group_id'] ?? null)) {
            $query->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('post_groups as f_pg')
                ->whereColumn('f_pg.post_id', 'posts.id')->where('f_pg.group_id', (int) $filters['group_id']));
        }
        if (filled($filters['territory_id'] ?? null)) {
            $path = (string) Territory::query()->whereKey((int) $filters['territory_id'])->value('path');
            $query->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('post_territories as f_pt')
                ->join('territories as f_t', 'f_t.id', '=', 'f_pt.territory_id')
                ->whereColumn('f_pt.post_id', 'posts.id')->where('f_t.path', 'like', ($path !== '' ? $path : '/none/').'%'));
        }
        if (filled($filters['search'] ?? null)) {
            $query->where('posts.body', 'like', '%'.$filters['search'].'%');
        }

        return $mode === self::MINE
            ? $query->orderByDesc('posts.id')
            : $query->orderByDesc('posts.published_at')->orderByDesc('posts.id');
    }

    /**
     * Ids of the posts pinned where the viewer belongs.
     */
    public function pinnedFor(User $viewer): QueryBuilder
    {
        $groupIds = $this->groups->groupIdsOf($viewer->person_id);
        $territoryIds = $this->ownAndAncestorTerritoryIds($viewer->person_id);

        return PostPin::query()->select('post_id')->where(fn (Builder $where) => $where
            ->where('scope', PostPin::GLOBAL)
            ->orWhere(fn (Builder $territory) => $territory->where('scope', PostPin::TERRITORY)->whereIn('scope_id', $territoryIds))
            ->orWhere(fn (Builder $group) => $group->where('scope', PostPin::GROUP)->whereIn('scope_id', $groupIds)))
            ->toBase();
    }

    /**
     * @return list<int> the viewer's territories, everything inside them and everything above them
     */
    private function ownAndAncestorTerritoryIds(int $personId): array
    {
        $ids = [];
        foreach ($this->territories->paths($personId) as $path) {
            foreach (array_filter(explode('/', $path)) as $id) {
                $ids[(int) $id] = true;
            }
            foreach (Territory::query()->where('path', 'like', $path.'%')->pluck('id') as $id) {
                $ids[(int) $id] = true;
            }
        }

        return array_keys($ids);
    }
}
