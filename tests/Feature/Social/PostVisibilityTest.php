<?php

use App\Domain\Groups\Actions\ManageGroups;
use App\Domain\Groups\Models\Group;
use App\Domain\Identity\Models\User;
use App\Domain\Social\Actions\ManageComments;
use App\Domain\Social\Actions\ManagePosts;
use App\Domain\Social\Exceptions\SocialRuleViolation;
use App\Domain\Social\Feed;
use App\Domain\Social\Models\Post;
use App\Domain\Social\PostVisibility;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\SocialFixture;

/*
 * ФО §6.4.1, ТЗ §18 — who sees a post and who may address which audience. The visibility is decided in SQL,
 * so the feed, its modes, its filters and the search can only narrow it.
 */

beforeEach(function () {
    $this->social = SocialFixture::build();
    $this->org = $this->social->org;
    $this->visibility = app(PostVisibility::class);
    $this->feed = app(Feed::class);
    $this->sees = fn (User $user, Post $post): bool => $this->visibility->canSee($user, $post);
    $this->inFeed = fn (User $user, string $mode = Feed::ALL, array $filters = []): array => $this->feed
        ->query($user, $mode, $filters)->pluck('posts.id')->all();
});

it('shows a public post to everyone and a private one to its author alone', function () {
    $o = $this->org;
    $public = $this->social->post($o->orgHead, 'Anunț pentru toți', ['visibility' => Post::PUBLIC]);
    $private = $this->social->post($o->a1, 'Notiță', ['visibility' => Post::PRIVATE]);

    foreach ([$o->a1, $o->b1, $o->balti1, $o->central1] as $user) {
        expect(($this->sees)($user, $public))->toBeTrue();
    }
    expect(($this->sees)($o->a1, $private))->toBeTrue()
        ->and(($this->sees)($o->a2, $private))->toBeFalse()
        ->and(($this->sees)($o->headA, $private))->toBeFalse()
        // Not even the super admin: a private post is nobody else's business.
        ->and(($this->sees)($o->admin, $private))->toBeFalse()
        ->and(($this->inFeed)($o->a2))->toBe([$public->id])
        ->and(($this->inFeed)($o->a1))->toBe([$private->id, $public->id]);
});

it('shows a regional post where the territories overlap, in both directions', function () {
    $o = $this->org;
    $centru = $this->social->centruPost();
    $chisinau = $this->social->post($o->regionHead, 'Pentru tot Chișinăul', ['visibility' => Post::REGIONAL, 'territory_ids' => [$o->chisinau->id]]);

    // A post to the sector: the sector, and everyone whose territory contains it.
    expect(($this->sees)($o->a2, $centru))->toBeTrue()
        ->and(($this->sees)($o->headA, $centru))->toBeTrue()
        ->and(($this->sees)($o->regionHead, $centru))->toBeTrue()
        ->and(($this->sees)($o->orgHead, $centru))->toBeTrue()
        ->and(($this->sees)($o->b1, $centru))->toBeFalse()
        ->and(($this->sees)($o->balti1, $centru))->toBeFalse()
        ->and(($this->sees)($o->central1, $centru))->toBeFalse()
        // A post to the whole region: the people of its sectors too — but not of another region.
        ->and(($this->sees)($o->a1, $chisinau))->toBeTrue()
        ->and(($this->sees)($o->b1, $chisinau))->toBeTrue()
        ->and(($this->sees)($o->balti1, $chisinau))->toBeFalse()
        ->and(($this->sees)($o->baltiHead, $chisinau))->toBeFalse();
});

it('lets an author address only the audiences their rights reach', function () {
    $o = $this->org;
    $regional = fn (User $user, int $territoryId) => fn () => $this->social->post($user, 'X', ['visibility' => Post::REGIONAL, 'territory_ids' => [$territoryId]]);

    expect(fn () => $this->social->post($o->a1, 'X', ['visibility' => Post::PUBLIC]))->toThrow(AuthorizationException::class)
        ->and($regional($o->a1, $o->botanica->id))->toThrow(AuthorizationException::class)
        // Not the region above one's own sector either.
        ->and($regional($o->a1, $o->chisinau->id))->toThrow(AuthorizationException::class)
        ->and($regional($o->baltiHead, $o->centru->id))->toThrow(AuthorizationException::class)
        ->and($regional($o->central1, $o->centru->id))->toThrow(AuthorizationException::class)
        ->and($regional($o->regionHead, $o->botanica->id)()->visibility)->toBe(Post::REGIONAL)
        ->and($regional($o->orgHead, $o->baltiTerritory->id)()->visibility)->toBe(Post::REGIONAL)
        ->and(fn () => $this->social->post($o->a1, 'X', ['visibility' => Post::REGIONAL]))->toThrow(SocialRuleViolation::class)
        ->and(fn () => $this->social->post($o->a1, 'X', ['visibility' => 'everyone']))->toThrow(SocialRuleViolation::class)
        ->and(fn () => $this->social->post($o->a1, '  ', ['visibility' => Post::PRIVATE]))->toThrow(SocialRuleViolation::class);
});

it('shows a group post to the members of the group only', function () {
    $o = $this->org;
    $group = $this->social->group($o->a1, 'Voluntari', Group::CLOSED, [$o->b1]);
    $secret = $this->social->group($o->balti1, 'Secret', Group::SECRET);
    $post = $this->social->post($o->a1, 'Pentru grup', ['visibility' => Post::GROUP, 'group_ids' => [$group->id]]);

    expect(($this->sees)($o->b1, $post))->toBeTrue()
        ->and(($this->sees)($o->a2, $post))->toBeFalse()
        ->and(($this->sees)($o->headA, $post))->toBeFalse()
        ->and(($this->sees)($o->orgHead, $post))->toBeFalse()
        ->and(($this->inFeed)($o->b1, Feed::GROUPS))->toBe([$post->id])
        ->and(($this->inFeed)($o->b1, Feed::ALL, ['group_id' => $group->id]))->toBe([$post->id])
        ->and(($this->inFeed)($o->a2, Feed::ALL, ['group_id' => $group->id]))->toBe([])
        // One publishes to the groups one belongs to.
        ->and(fn () => $this->social->post($o->a2, 'X', ['visibility' => Post::GROUP, 'group_ids' => [$group->id]]))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->social->post($o->a1, 'X', ['visibility' => Post::GROUP, 'group_ids' => [$secret->id]]))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->social->post($o->a1, 'X', ['visibility' => Post::GROUP]))->toThrow(SocialRuleViolation::class);
});

it('shows a targeted post to the listed people and to the holders of the listed roles', function () {
    $o = $this->org;
    $toPeople = $this->social->post($o->a1, 'Doar pentru voi', ['visibility' => Post::TARGETED, 'person_ids' => [$o->b1->person_id, $o->balti1->person_id]]);
    $toHeads = $this->social->post($o->orgHead, 'Către șefii de filiale', ['visibility' => Post::TARGETED, 'role_codes' => ['unit_head']]);

    expect(($this->sees)($o->b1, $toPeople))->toBeTrue()
        ->and(($this->sees)($o->balti1, $toPeople))->toBeTrue()
        ->and(($this->sees)($o->a2, $toPeople))->toBeFalse()
        ->and(($this->sees)($o->headA, $toPeople))->toBeFalse()
        ->and(($this->sees)($o->headA, $toHeads))->toBeTrue()
        ->and(($this->sees)($o->baltiHead, $toHeads))->toBeTrue()
        ->and(($this->sees)($o->a1, $toHeads))->toBeFalse()
        // A whole role is an organization-wide audience.
        ->and(fn () => $this->social->post($o->a1, 'X', ['visibility' => Post::TARGETED, 'role_codes' => ['unit_head']]))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->social->post($o->orgHead, 'X', ['visibility' => Post::TARGETED, 'role_codes' => ['nobody']]))->toThrow(SocialRuleViolation::class)
        ->and(fn () => $this->social->post($o->a1, 'X', ['visibility' => Post::TARGETED]))->toThrow(SocialRuleViolation::class);
});

it('narrows the feed by mode, territory and search without ever widening it', function () {
    $o = $this->org;
    $public = $this->social->post($o->orgHead, 'Adunarea generală', ['visibility' => Post::PUBLIC]);
    $centru = $this->social->centruPost('Adunarea sectorului Centru');
    $botanica = $this->social->post($o->b1, 'Adunarea sectorului Botanica', ['visibility' => Post::REGIONAL, 'territory_ids' => [$o->botanica->id]]);
    $mine = $this->social->post($o->a2, 'Notiță despre adunare', ['visibility' => Post::PRIVATE]);

    expect(($this->inFeed)($o->a2))->toBe([$mine->id, $centru->id, $public->id])
        ->and(($this->inFeed)($o->a2, Feed::REGION))->toBe([$centru->id])
        ->and(($this->inFeed)($o->a2, Feed::MINE))->toBe([$mine->id])
        ->and(($this->inFeed)($o->a2, Feed::ALL, ['search' => 'Botanica']))->toBe([])
        ->and(($this->inFeed)($o->a2, Feed::ALL, ['search' => 'sectorului']))->toBe([$centru->id])
        ->and(($this->inFeed)($o->a2, Feed::ALL, ['territory_id' => $o->botanica->id]))->toBe([])
        ->and(($this->inFeed)($o->regionHead, Feed::ALL, ['territory_id' => $o->chisinau->id]))->toBe([$botanica->id, $centru->id])
        ->and(($this->inFeed)($o->regionHead, Feed::ALL, ['territory_id' => $o->botanica->id]))->toBe([$botanica->id])
        ->and(($this->inFeed)($o->balti1, Feed::ALL, ['search' => 'Adunarea']))->toBe([$public->id]);
});

it('keeps drafts and scheduled posts to their author until they are published', function () {
    $o = $this->org;
    $audience = ['visibility' => Post::REGIONAL, 'territory_ids' => [$o->centru->id]];
    $posts = app(ManagePosts::class);
    $draft = $this->social->post($o->a1, 'Ciornă', [...$audience, 'intent' => 'draft']);
    $scheduled = $this->social->post($o->a1, 'Mâine', [...$audience, 'intent' => 'schedule', 'publish_at' => now()->addHours(2)]);

    expect($draft->status)->toBe(Post::DRAFT)
        ->and($scheduled->status)->toBe(Post::SCHEDULED)
        ->and(($this->inFeed)($o->a2))->toBe([])
        ->and(($this->inFeed)($o->a1))->toBe([])
        ->and(($this->inFeed)($o->a1, Feed::MINE))->toBe([$scheduled->id, $draft->id])
        ->and(fn () => $this->social->post($o->a1, 'X', [...$audience, 'intent' => 'schedule', 'publish_at' => now()->subMinute()]))->toThrow(SocialRuleViolation::class)
        ->and(fn () => $posts->publish($o->a2, $draft))->toThrow(AuthorizationException::class)
        ->and($posts->publishDue())->toBe(0);

    $this->travel(3)->hours();

    expect($posts->publishDue())->toBe(1)
        ->and($scheduled->fresh())->status->toBe(Post::PUBLISHED)
        ->and($scheduled->fresh()->published_at->diffInMinutes(now()))->toBeGreaterThan(50.0)
        ->and(($this->inFeed)($o->a2))->toBe([$scheduled->id]);

    $posts->publish($o->a1, $draft);

    expect(($this->inFeed)($o->a2))->toBe([$draft->id, $scheduled->id])
        ->and(journalCount('social.post.published'))->toBe(2);
});

it('shows a candidate only what is explicitly opened to them: groups they were invited to and posts addressed to them', function () {
    $o = $this->org;
    $candidate = userWithRoles('candidate');
    $group = $this->social->group($o->a1, 'Viitori colegi', Group::CLOSED, [$candidate]);
    $public = $this->social->post($o->orgHead, 'Anunț pentru toți', ['visibility' => Post::PUBLIC]);
    $inGroup = $this->social->post($o->a1, 'Bine ați venit', ['visibility' => Post::GROUP, 'group_ids' => [$group->id]]);
    $toHim = $this->social->post($o->a1, 'Vă așteptăm luni', ['visibility' => Post::TARGETED, 'person_ids' => [$candidate->person_id]]);

    expect(($this->sees)($candidate, $public))->toBeFalse()
        ->and(($this->inFeed)($candidate))->toBe([$toHim->id, $inGroup->id])
        // A candidate reads, comments and reacts where admitted, but does not publish and does not join by themselves.
        ->and(app(ManageComments::class)->add($candidate, $inGroup, 'Mulțumesc!')->id)->toBeInt()
        ->and(fn () => $this->social->post($candidate, 'X', ['visibility' => Post::GROUP, 'group_ids' => [$group->id]]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ManageGroups::class)->join($candidate, $this->social->group($o->a2, 'Deschis')))->toThrow(AuthorizationException::class);
});
