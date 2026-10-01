<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Access\Actions\AssignRole;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Scopes\ScopeType;
use App\Domain\Catalogs\Actions\ImportReferenceCatalogs;
use App\Domain\Groups\Actions\ManageGroups;
use App\Domain\Groups\Models\Group;
use App\Domain\Identity\Models\User;
use App\Domain\Social\Actions\ManagePosts;
use App\Domain\Social\Models\Post;

/**
 * The social network on top of the small organization of OrgFixture: a moderator of the Chișinău region
 * and shortcuts for publishing.
 */
final class SocialFixture
{
    public OrgFixture $org;

    public User $moderator;

    public static function build(): self
    {
        $f = new self;
        $f->org = OrgFixture::build();
        app(ImportReferenceCatalogs::class)();

        $f->moderator = $f->org->member($f->org->central);
        app(AssignRole::class)($f->org->admin, $f->moderator, Role::query()->where('code', 'moderator')->sole(),
            scope: ScopeType::Territory, scopeId: $f->org->chisinau->id);
        app(AuthorizationService::class)->forget();

        return $f;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function post(User $author, string $body, array $data = []): Post
    {
        return app(ManagePosts::class)->create($author, ['body' => $body, ...$data]);
    }

    /**
     * A regional post addressed to the sector Centru by an employee of branch A.
     */
    public function centruPost(string $body = 'Întâlnire în Centru'): Post
    {
        return $this->post($this->org->a1, $body, ['visibility' => Post::REGIONAL, 'territory_ids' => [$this->org->centru->id]]);
    }

    /**
     * @param  list<User>  $members
     */
    public function group(User $owner, string $name, string $type = Group::OPEN, array $members = []): Group
    {
        $groups = app(ManageGroups::class);
        $group = $groups->create($owner, ['name' => $name, 'type' => $type]);
        foreach ($members as $member) {
            $groups->answerInvitation($member, $groups->invite($owner, $group, $member->person), true);
        }

        return $group;
    }
}
