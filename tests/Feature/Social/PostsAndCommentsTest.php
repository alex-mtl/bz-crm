<?php

use App\Domain\Groups\Models\Group;
use App\Domain\Social\Actions\ManageComments;
use App\Domain\Social\Actions\ManagePosts;
use App\Domain\Social\Exceptions\SocialRuleViolation;
use App\Domain\Social\Feed;
use App\Domain\Social\Models\Comment;
use App\Domain\Social\Models\PollVote;
use App\Domain\Social\Models\Post;
use App\Domain\Social\Models\PostPin;
use App\Domain\Social\Models\Reaction;
use App\Domain\Social\Notifications\SocialNotice;
use App\Domain\Social\PostVisibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SocialFixture;

/*
 * ФО §6.4.1–6.4.3 — editing with history, changing the audience, polls, reposts, attachments, pins;
 * comments as a tree with quoting, reactions, subscriptions to authors.
 */

beforeEach(function () {
    $this->social = SocialFixture::build();
    $this->org = $this->social->org;
    $this->posts = app(ManagePosts::class);
    $this->comments = app(ManageComments::class);
});

it('keeps the previous text of an edited post and marks it as edited', function () {
    $o = $this->org;
    $post = $this->social->centruPost('Prima versiune');
    $draft = $this->social->post($o->a1, 'Ciornă', ['intent' => 'draft']);

    $this->posts->update($o->a1, $post, 'A doua versiune');
    $this->posts->update($o->a1, $post->fresh(), 'A treia versiune');
    $this->posts->update($o->a1, $draft, 'Ciornă nouă');

    expect($post->fresh())->body->toBe('A treia versiune')->edited_at->not->toBeNull()
        ->and($post->revisions()->pluck('body')->all())->toBe(['Prima versiune', 'A doua versiune'])
        // A draft has no public history yet.
        ->and($draft->revisions()->count())->toBe(0)
        ->and($draft->fresh()->edited_at)->toBeNull()
        ->and(journalCount('social.post.updated'))->toBe(3)
        ->and(fn () => $this->posts->update($o->a2, $post->fresh(), 'Al altcuiva'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->posts->update($o->headA, $post->fresh(), 'Șeful'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->posts->update($o->a1, $post->fresh(), ' '))->toThrow(SocialRuleViolation::class);
});

it('changes the audience only to one the changer may address, and drops the pins', function () {
    $o = $this->org;
    $post = $this->social->centruPost();
    $group = $this->social->group($o->a1, 'Voluntari', Group::OPEN, [$o->b1]);
    $this->posts->pin($o->headA, $post, PostPin::TERRITORY, $o->centru->id);

    expect(fn () => $this->posts->changeAudience($o->a1, $post, ['visibility' => Post::PUBLIC]))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->posts->changeAudience($o->a2, $post, ['visibility' => Post::PRIVATE]))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->posts->changeAudience($o->headB, $post, ['visibility' => Post::PRIVATE]))->toThrow(AuthorizationException::class);

    $this->posts->changeAudience($o->a1, $post, ['visibility' => Post::GROUP, 'group_ids' => [$group->id]]);

    expect($post->fresh()->visibility)->toBe(Post::GROUP)
        ->and($post->territories()->count())->toBe(0)
        ->and($post->pins()->count())->toBe(0)
        ->and(app(PostVisibility::class)->canSee($o->a2, $post))->toBeFalse()
        ->and(app(PostVisibility::class)->canSee($o->b1, $post))->toBeTrue()
        ->and(journalCount('social.post.audience_changed'))->toBe(1);

    // The head of the author's branch moderates the post: narrows it to its author.
    $this->posts->changeAudience($o->headA, $post->fresh(), ['visibility' => Post::PRIVATE]);

    expect(app(PostVisibility::class)->canSee($o->b1, $post))->toBeFalse()
        ->and(app(PostVisibility::class)->canSee($o->a1, $post))->toBeTrue();
});

it('runs a poll: one vote per person, changeable', function () {
    $o = $this->org;
    $post = $this->social->post($o->a1, 'Când ne întâlnim?', [
        'visibility' => Post::REGIONAL, 'territory_ids' => [$o->centru->id], 'poll_options' => ['Sâmbătă', ' Duminică ', ''],
    ]);
    [$saturday, $sunday] = $post->pollOptions()->get()->all();

    $this->posts->vote($o->a2, $post, $saturday->id);
    $this->posts->vote($o->a2, $post, $sunday->id);
    $this->posts->vote($o->a1, $post, $sunday->id);

    expect($post->pollOptions()->pluck('text')->all())->toBe(['Sâmbătă', 'Duminică'])
        ->and(PollVote::query()->where('post_id', $post->id)->count())->toBe(2)
        ->and(PollVote::query()->where('option_id', $sunday->id)->count())->toBe(2)
        ->and(fn () => $this->posts->vote($o->b1, $post, $sunday->id))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->posts->vote($o->a2, $post, 999999))->toThrow(SocialRuleViolation::class)
        ->and(fn () => $this->social->post($o->a1, 'X', ['poll_options' => ['Unul']]))->toThrow(SocialRuleViolation::class);
});

it('reposts only what the reposter sees, and stores attachments off the public disk', function () {
    Storage::fake('local');
    $o = $this->org;
    $source = tempnam(sys_get_temp_dir(), 'post');
    file_put_contents($source, 'plan');
    $post = $this->posts->create($o->a1, ['visibility' => Post::REGIONAL, 'territory_ids' => [$o->centru->id]],
        [['source' => $source, 'name' => 'Plan.PDF', 'mime' => 'application/pdf']]);
    $attachment = $post->attachments()->sole();

    $repost = $this->posts->create($o->a2, ['body' => 'De citit', 'visibility' => Post::PRIVATE, 'repost_of_post_id' => $post->id]);

    expect($attachment)->kind->toBe('file')->original_name->toBe('Plan.PDF')->size->toBe(4)
        ->and($attachment->path)->toStartWith('social/posts/'.$post->id.'/')->toEndWith('.pdf')
        ->and(Storage::disk('local')->exists($attachment->path))->toBeTrue()
        ->and($repost->original->id)->toBe($post->id)
        ->and(fn () => $this->posts->create($o->b1, ['visibility' => Post::PRIVATE, 'repost_of_post_id' => $post->id]))->toThrow(AuthorizationException::class);
});

it('pins globally, in a territory and in a group — each where the pinner has the right', function () {
    $o = $this->org;
    $public = $this->social->post($o->orgHead, 'Anunț', ['visibility' => Post::PUBLIC]);
    $centru = $this->social->centruPost();
    $group = $this->social->group($o->a1, 'Voluntari', Group::OPEN, [$o->a2]);
    $inGroup = $this->social->post($o->a2, 'În grup', ['visibility' => Post::GROUP, 'group_ids' => [$group->id]]);
    $private = $this->social->post($o->orgHead, 'Notiță', ['visibility' => Post::PRIVATE]);

    $this->posts->pin($o->orgHead, $public, PostPin::GLOBAL);
    $this->posts->pin($o->orgHead, $public, PostPin::GLOBAL);
    $pin = $this->posts->pin($o->headA, $centru, PostPin::TERRITORY, $o->centru->id);
    $this->posts->pin($o->a1, $inGroup, PostPin::GROUP, $group->id);

    $important = fn ($user): array => app(Feed::class)->query($user, Feed::IMPORTANT)->pluck('posts.id')->sort()->values()->all();

    expect(PostPin::query()->count())->toBe(3)
        ->and(journalCount('social.post.pinned'))->toBe(3)
        ->and($important($o->a2))->toBe([$public->id, $centru->id, $inGroup->id])
        ->and($important($o->b1))->toBe([$public->id])
        ->and($important($o->regionHead))->toBe([$public->id, $centru->id])
        // A head pins inside the territories of their own access; an employee and a plain member do not pin.
        ->and(fn () => $this->posts->pin($o->headA, $public, PostPin::GLOBAL))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->posts->pin($o->headA, $public, PostPin::TERRITORY, $o->botanica->id))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->posts->pin($o->headB, $centru, PostPin::TERRITORY, $o->centru->id))->toThrow(SocialRuleViolation::class)
        ->and(fn () => $this->posts->pin($o->a1, $centru, PostPin::TERRITORY, $o->centru->id))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->posts->pin($o->a2, $inGroup, PostPin::GROUP, $group->id))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->posts->pin($o->orgHead, $private, PostPin::GLOBAL))->toThrow(SocialRuleViolation::class)
        ->and(fn () => $this->posts->unpin($o->a1, $pin))->toThrow(AuthorizationException::class);

    $this->posts->unpin($o->headA, $pin);
    $this->posts->delete($o->orgHead, $public);

    expect(PostPin::query()->count())->toBe(1)
        ->and($important($o->a2))->toBe([$inGroup->id])
        ->and(journalCount('social.post.unpinned'))->toBe(1)
        ->and(fn () => $this->posts->delete($o->headA, $centru))->toThrow(AuthorizationException::class);
});

it('builds a comment tree with quoting and tells the authors', function () {
    Notification::fake();
    $o = $this->org;
    $post = $this->social->centruPost();

    $first = $this->comments->add($o->a2, $post, ' Vin și eu ');
    $reply = $this->comments->add($o->a1, $post, 'Te așteptăm', $first);
    $deeper = $this->comments->add($o->headA, $post, 'Și eu', $reply, quoted: $first);
    $second = $this->comments->add($o->regionHead, $post, 'Succes');

    $thread = $this->comments->thread($o->a2, $post);

    expect($thread->pluck('id')->all())->toBe([$first->id, $reply->id, $deeper->id, $second->id])
        ->and($thread->pluck('depth')->all())->toBe([0, 1, 2, 0])
        ->and($first->body)->toBe('Vin și eu')
        ->and($deeper->quoted->id)->toBe($first->id)
        ->and(journalCount('social.comment.created'))->toBe(4)
        ->and(fn () => $this->comments->add($o->a2, $post, '  '))->toThrow(SocialRuleViolation::class);
    Notification::assertSentTo($o->a1, SocialNotice::class, fn (SocialNotice $n) => $n->kind === SocialNotice::COMMENT && $n->postId === $post->id);
    Notification::assertSentTo($o->a2, SocialNotice::class, fn (SocialNotice $n) => $n->kind === SocialNotice::REPLY);
});

it('gives a comment exactly the visibility of its post', function () {
    Notification::fake();
    $o = $this->org;
    $post = $this->social->centruPost();
    $other = $this->social->post($o->orgHead, 'Altă postare', ['visibility' => Post::PUBLIC]);
    $comment = $this->comments->add($o->a2, $post, 'Vin');

    expect($this->comments->thread($o->b1, $post))->toBeEmpty()
        ->and(fn () => $this->comments->add($o->b1, $post, 'Și eu'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->comments->react($o->b1, $comment, 'like'))->toThrow(AuthorizationException::class)
        // A reply must stay under the same post.
        ->and(fn () => $this->comments->add($o->a1, $other, 'X', $comment))->toThrow(SocialRuleViolation::class)
        ->and(fn () => $this->comments->delete($o->a1, $comment))->toThrow(AuthorizationException::class);

    $this->comments->delete($o->a2, $comment);

    expect($comment->fresh()->deleted_at)->not->toBeNull()
        ->and($this->comments->thread($o->a1, $post))->toHaveCount(1)
        ->and(fn () => $this->comments->add($o->a1, $post, 'X', $comment->fresh()))->toThrow(SocialRuleViolation::class);
    Notification::assertNotSentTo($o->b1, SocialNotice::class);
});

it('keeps replies beyond the maximum depth on the last level', function () {
    $o = $this->org;
    $post = $this->social->centruPost();
    $parent = null;
    for ($i = 0; $i <= Comment::MAX_DEPTH + 1; $i++) {
        $parent = $this->comments->add($i % 2 === 0 ? $o->a1 : $o->a2, $post, 'Nivel '.$i, $parent);
    }

    expect($parent->depth)->toBe(Comment::MAX_DEPTH);
});

it('sets, changes and removes a reaction', function () {
    $o = $this->org;
    $post = $this->social->centruPost();
    $comment = $this->comments->add($o->a2, $post, 'Vin');

    $this->comments->react($o->a2, $post, 'like');
    $this->comments->react($o->a2, $post, 'support');
    $this->comments->react($o->headA, $post, 'like');
    $this->comments->react($o->a1, $comment, 'thanks');

    expect(Reaction::query()->where('reactable_type', Reaction::POST)->pluck('reaction_code')->sort()->values()->all())->toBe(['like', 'support'])
        ->and(Reaction::query()->where('reactable_type', Reaction::COMMENT)->count())->toBe(1)
        ->and($this->comments->react($o->headA, $post, 'like'))->toBeNull()
        ->and(Reaction::query()->where('reactable_type', Reaction::POST)->count())->toBe(1)
        ->and(fn () => $this->comments->react($o->a2, $post, 'hate'))->toThrow(SocialRuleViolation::class)
        ->and(fn () => $this->comments->react($o->balti1, $post, 'like'))->toThrow(AuthorizationException::class);
});

it('announces a new post only to the followers who can see it', function () {
    Notification::fake();
    $o = $this->org;
    $this->comments->follow($o->a2, $o->a1->person);
    $this->comments->follow($o->b1, $o->a1->person);
    $this->comments->follow($o->b1, $o->a1->person);
    $this->comments->follow($o->headA, $o->a1->person);
    $this->comments->unfollow($o->headA, $o->a1->person);

    $post = $this->social->centruPost();
    $this->social->post($o->a1, 'Notiță', ['visibility' => Post::PRIVATE]);
    $this->social->post($o->a1, 'Ciornă', ['visibility' => Post::REGIONAL, 'territory_ids' => [$o->centru->id], 'intent' => 'draft']);

    expect(app(Feed::class)->query($o->a2, Feed::FOLLOWING)->pluck('posts.id')->all())->toBe([$post->id])
        ->and(app(Feed::class)->query($o->b1, Feed::FOLLOWING)->count())->toBe(0)
        ->and(fn () => $this->comments->follow($o->a1, $o->a1->person))->toThrow(SocialRuleViolation::class);
    Notification::assertSentToTimes($o->a2, SocialNotice::class, 1);
    Notification::assertNotSentTo($o->b1, SocialNotice::class);
    Notification::assertNotSentTo($o->headA, SocialNotice::class);
});
