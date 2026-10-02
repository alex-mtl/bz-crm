<?php

declare(strict_types=1);

namespace App\Domain\Social;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\UserRole;
use App\Domain\Access\Scopes\ScopeType;
use App\Domain\Access\TerritorialAccess;
use App\Domain\Geo\Models\Territory;
use App\Domain\Groups\GroupAccess;
use App\Domain\Identity\Models\User;
use App\Domain\Social\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Who sees a post (ФО §6.4.1, ТЗ §18) — decided on the server, in SQL, in this one place. The feed, the search,
 * the API, notifications and counters all start from visibleTo(), so a post invisible to a user appears nowhere.
 *
 *   public   — everyone with posts.read, candidates and volunteers included (Д-25);
 *   regional — the territories of the post overlap the viewer's territorial access (Д-3), in either direction;
 *              not for a candidate, who beyond public posts sees only what is explicitly opened to them;
 *   group    — members of one of the post's groups;
 *   targeted — the people on the list and the holders of the listed roles;
 *   private  — the author alone.
 *
 * The author always sees their own posts. A post hidden by a moderator is seen only by its author.
 */
final readonly class PostVisibility
{
    public function __construct(
        private AuthorizationService $authorization,
        private TerritorialAccess $territories,
        private GroupAccess $groups,
    ) {}

    /**
     * @param  bool  $withOwnUnpublished  also the viewer's own drafts and scheduled posts ("my posts")
     * @return Builder<Post>
     */
    public function visibleTo(User $user, bool $withOwnUnpublished = false): Builder
    {
        $query = Post::query()->whereNull('posts.deleted_at');
        $grants = $this->authorization->grantsFor($user, 'posts.read');
        if ($grants === []) {
            return $query->whereRaw('1 = 0');
        }

        // "Канд — только явно открытое": a grant narrowed to "related" adds to public posts only groups and
        // targeted posts — no regional ones.
        $open = array_filter($grants, fn (array $grant): bool => $grant['data'] === null) !== [];
        $personId = $user->person_id;
        $groupIds = $this->groups->groupIdsOf($personId);
        $roles = $this->roleCodes($user);
        [$inside, $above] = $open ? $this->territoryPaths($personId) : [[], []];

        return $query->where(function (Builder $where) use ($personId, $withOwnUnpublished, $groupIds, $roles, $inside, $above): void {
            $where->where(fn (Builder $own) => $own
                ->where('posts.author_person_id', $personId)
                ->when(! $withOwnUnpublished, fn (Builder $q) => $q->where('posts.status', Post::PUBLISHED)));

            $where->orWhere(fn (Builder $others) => $others
                ->where('posts.status', Post::PUBLISHED)
                ->whereNull('posts.hidden_at')
                ->where(function (Builder $audience) use ($personId, $groupIds, $roles, $inside, $above): void {
                    // Д-25: a public post is for everyone who has an account — candidates and volunteers included.
                    $audience->where('posts.visibility', Post::PUBLIC);
                    if ($inside !== []) {
                        $audience->orWhere(fn (Builder $regional) => $regional
                            ->where('posts.visibility', Post::REGIONAL)
                            ->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('post_territories as pv_pt')
                                ->join('territories as pv_t', 'pv_t.id', '=', 'pv_pt.territory_id')
                                ->whereColumn('pv_pt.post_id', 'posts.id')
                                ->where(function (QueryBuilder $overlap) use ($inside, $above): void {
                                    foreach ($inside as $path) {
                                        $overlap->orWhere('pv_t.path', 'like', $path.'%');
                                    }
                                    $overlap->orWhereIn('pv_t.path', $above);
                                })));
                    }
                    if ($groupIds !== []) {
                        $audience->orWhere(fn (Builder $group) => $group
                            ->where('posts.visibility', Post::GROUP)
                            ->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('post_groups as pv_pg')
                                ->whereColumn('pv_pg.post_id', 'posts.id')->whereIn('pv_pg.group_id', $groupIds)));
                    }
                    $audience->orWhere(fn (Builder $targeted) => $targeted
                        ->where('posts.visibility', Post::TARGETED)
                        ->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('post_targets as pv_tg')
                            ->whereColumn('pv_tg.post_id', 'posts.id')
                            ->where(fn (QueryBuilder $who) => $who->where('pv_tg.person_id', $personId)
                                ->when($roles !== [], fn (QueryBuilder $q) => $q->orWhereIn('pv_tg.role_code', $roles)))));
                }));
        });
    }

    public function canSee(User $user, Post $post): bool
    {
        return $this->visibleTo($user, true)->whereKey($post->id)->exists();
    }

    /**
     * Users who see the post, among the given ones — for notifications: nobody is told about a post they cannot open.
     *
     * @param  iterable<User>  $users
     * @return list<User>
     */
    public function among(Post $post, iterable $users): array
    {
        $seeing = [];
        foreach ($users as $user) {
            if ($this->canSee($user, $post)) {
                $seeing[] = $user;
            }
        }

        return $seeing;
    }

    /**
     * May the person publish to this territory as "regional" by their own territorial access?
     */
    public function coversTerritory(User $user, Territory $territory): bool
    {
        foreach ($this->authorization->grantsFor($user, 'posts.publish.region') as $grant) {
            if ($grant['scope'] === ScopeType::Organization && $grant['data'] === null) {
                return true;
            }
        }

        return $this->territories->covers($user->person_id, $territory);
    }

    /**
     * Paths of the viewer's territories (a post inside one of them is seen) and of every territory above them
     * (a post addressed to a whole region is seen by the people of its sectors).
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function territoryPaths(int $personId): array
    {
        $inside = array_values(array_filter($this->territories->paths($personId)));
        $above = [];
        foreach ($inside as $path) {
            $ids = array_values(array_filter(explode('/', $path)));
            $prefix = '/';
            foreach ($ids as $id) {
                $prefix .= $id.'/';
                $above[$prefix] = true;
            }
        }

        return [$inside, array_keys($above)];
    }

    /**
     * @return list<string>
     */
    private function roleCodes(User $user): array
    {
        return UserRole::query()->inEffect()->where('user_id', $user->id)
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')->distinct()->pluck('roles.code')->all();
    }
}
