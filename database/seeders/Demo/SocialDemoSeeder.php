<?php

namespace Database\Seeders\Demo;

use App\Domain\CRM\SegmentQuery;
use App\Domain\Groups\Actions\ManageGroups;
use App\Domain\Groups\Models\Group;
use App\Domain\Groups\Models\GroupMember;
use App\Domain\Identity\Models\User;
use App\Domain\Social\Actions\ManageComments;
use App\Domain\Social\Actions\ManagePosts;
use App\Domain\Social\Models\Comment;
use App\Domain\Social\Models\Post;
use App\Domain\Social\Models\PostPin;
use App\Domain\Social\Moderation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * The social network of the demo world (plan 4.2): groups of all three types with roles, requests and
 * invitations; posts of the same cast in ro/ru/en at every visibility level; a draft, a scheduled post, an edited
 * one, pins (global, territory, group); comment threads with quoting; reactions; moderation — a hidden post,
 * a complaint waiting in the queue, a dismissed one, a warning, a running mute and an expired one.
 */
class SocialDemoSeeder extends Seeder
{
    use DemoSteps;

    public const string GROUP_OPEN = 'Voluntari Chișinău';

    public const string GROUP_CLOSED = 'Filiala A — echipa';

    public const string GROUP_SECRET = 'Grup de lucru: alegeri locale';

    public const string GROUP_ARCHIVED = 'Campania de iarnă';

    public const string WELCOME = 'Bine ați venit în rețeaua internă a organizației!';

    public const string HR_NOTICE = 'Коллеги, до конца месяца обновите, пожалуйста, свои профили';

    public const string RULES = 'Community rules: be kind, stay on topic, no personal data in posts';

    public const string CHISINAU = 'Adunarea activului din municipiul Chișinău';

    public const string CENTRU_POLL = 'Când organizăm ieșirea în sectorul Centru?';

    public const string BOTANICA = 'Субботник в секторе Ботаника';

    public const string BALTI = 'Întâlnire cu susținătorii din Bălți';

    public const string CAHUL = 'Field visit to Cahul next week';

    public const string IN_OPEN_GROUP = 'Programul voluntarilor pentru luna aceasta';

    public const string IN_CLOSED_GROUP = 'Ședința echipei filialei A';

    public const string IN_SECRET_GROUP = 'Lista preliminară a candidaților';

    public const string TO_HEADS = 'Către toți șefii organizațiilor regionale și ai filialelor';

    public const string TO_PEOPLE = 'Ion, Maria — vă rog să pregătiți lista de contacte';

    public const string PRIVATE_NOTE = 'Notițe pentru mine: de sunat partenerii';

    public const string EDITED = 'Punctul de colectare se mută pe strada Columna';

    public const string REPOST = 'De citit tuturor colegilor din Centru';

    public const string DRAFT = 'Ciornă: raportul lunii';

    public const string SCHEDULED = 'Mâine începem înscrierile la training';

    public const string HIDDEN = 'Продаю автомобиль, недорого';

    public const string REPORTED = 'Am auzit că sediul din Centru se închide';

    public const string AFTER_MUTE = 'Mulțumesc tuturor pentru răbdare';

    public static function group(string $name): Group
    {
        return Group::query()->where('name', $name)->firstOrFail();
    }

    public static function post(string $body): Post
    {
        return Post::query()->where('body', 'like', $body.'%')->orderBy('id')->firstOrFail();
    }

    public function run(): void
    {
        try {
            $this->at(30, fn () => $this->groups());
            $this->at(29, fn () => $this->subscriptions());
            $this->at(28, fn () => $this->publicPosts());
            $this->at(25, fn () => $this->regionalPosts());
            $this->at(24, fn () => $this->discussion());
            $this->at(20, fn () => $this->groupPosts());
            $this->at(18, fn () => $this->targetedAndPrivate());
            $this->at(15, fn () => $this->editedAndReposted());
            $this->at(12, fn () => $this->moderation());
            $this->at(2, fn () => $this->draftAndScheduled());
        } finally {
            $this->resetClock();
        }
    }

    /**
     * @template T
     *
     * @param  callable(User): T  $step
     * @return T
     */
    private function by(string $key, callable $step): mixed
    {
        $user = Personas::user($key);

        return $this->as($user, fn () => $step($user));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function publish(string $key, string $body, array $data = []): Post
    {
        return $this->by($key, fn (User $author): Post => app(ManagePosts::class)->create($author, ['body' => $body, ...$data]));
    }

    /**
     * @return array<string, mixed>
     */
    private function region(string ...$codes): array
    {
        return ['visibility' => Post::REGIONAL, 'territory_ids' => array_map(fn (string $code): int => Personas::territory($code)->id, $codes)];
    }

    private function comment(string $key, Post $post, string $body, ?Comment $parent = null, ?Comment $quoted = null): Comment
    {
        return $this->by($key, fn (User $author): Comment => app(ManageComments::class)->add($author, $post, $body, $parent, $quoted));
    }

    private function react(string $key, Post|Comment $target, string $code): void
    {
        $this->by($key, fn (User $user) => app(ManageComments::class)->react($user, $target, $code));
    }

    private function groups(): void
    {
        $groups = app(ManageGroups::class);
        $person = fn (string $key) => Personas::user($key)->person;

        // Open: anyone joins. Maria runs it, Ion moderates.
        $open = $this->by('branch_a_employee_2', fn (User $maria): Group => $groups->create($maria, [
            'name' => self::GROUP_OPEN, 'description' => 'Tot ce ține de voluntariat în municipiu: anunțuri, grafice, fotografii.',
            'rules' => "Scriem la subiect.\nFără date personale ale susținătorilor.",
        ]));
        foreach (['branch_a_employee_1', 'branch_a_employee_3', 'volunteer', 'branch_b_employee_1', 'central_employee'] as $key) {
            $this->by($key, fn (User $user) => $groups->join($user, $open));
        }
        $this->by('branch_a_employee_2', fn (User $maria) => $groups->setRole($maria, $open, $person('branch_a_employee_1'), GroupMember::MODERATOR));

        // Closed, bound to branch A: its head invites the branch in bulk; people from outside ask to join.
        $closed = $this->by('branch_a_head', function (User $ana) use ($groups): Group {
            $group = $groups->create($ana, [
                'name' => self::GROUP_CLOSED, 'type' => Group::CLOSED, 'org_unit_id' => Personas::unit('branch_a')->id,
                'description' => 'Grupul de lucru al filialei A (Centru).',
            ]);
            $groups->inviteBulk($ana, $group, app(SegmentQuery::class)->build(['unit_id' => Personas::unit('branch_a')->id]));

            return $group;
        });
        foreach (['branch_a_employee_1' => true, 'branch_a_employee_2' => true, 'volunteer' => false] as $key => $accept) {
            $this->by($key, fn (User $user) => $groups->answerInvitation($user, $groups->invitationsFor($user)->where('group_id', $closed->id)->firstOrFail(), $accept));
        }
        $this->by('branch_a_head', fn (User $ana) => $groups->setRole($ana, $closed, $person('branch_a_employee_2'), GroupMember::ADMIN));
        $rejected = $this->by('branch_b_employee_2', fn (User $dan) => $groups->join($dan, $closed, 'Vreau să văd cum lucrați'));
        $this->by('branch_a_employee_2', fn (User $maria) => $groups->decideRequest($maria, $rejected, false));
        // A request still waiting for a decision.
        $this->by('branch_b_employee_1', fn (User $olga) => $groups->join($olga, $closed, 'Coordonăm împreună acțiunile din sectoare'));

        // Secret: exists only for its members. One invitation is still unanswered, one person came by a link.
        $secret = $this->by('org_head', function (User $elena) use ($groups, $person): Group {
            $group = $groups->create($elena, [
                'name' => self::GROUP_SECRET, 'type' => Group::SECRET, 'description' => 'Pregătirea listelor. Nimic din acest grup nu se discută în afara lui.',
            ]);
            foreach (['chisinau_head', 'balti_head', 'branch_a_head'] as $key) {
                $groups->invite($elena, $group, $person($key));
            }

            return $group;
        });
        foreach (['chisinau_head', 'balti_head'] as $key) {
            $this->by($key, fn (User $user) => $groups->answerInvitation($user, $groups->invitationsFor($user)->where('group_id', $secret->id)->firstOrFail(), true));
        }
        $link = $this->by('org_head', fn (User $elena): array => $groups->inviteByLink($elena, $secret, now()->addDays(60), 3));
        $this->by('branch_b_head', fn (User $pavel) => $groups->joinByLink($pavel, $link['token']));

        // Archived: stays visible to its members only.
        $this->by('branch_a_employee_2', function (User $maria) use ($groups): void {
            $group = $groups->create($maria, ['name' => self::GROUP_ARCHIVED, 'description' => 'Colectarea de haine calde. Campania s-a încheiat.']);
            $groups->archive($maria, $group);
        });
    }

    private function subscriptions(): void
    {
        $follow = fn (string $follower, string $author) => $this->by($follower, fn (User $user) => app(ManageComments::class)->follow($user, Personas::user($author)->person));
        $follow('branch_a_employee_2', 'branch_a_employee_1');
        $follow('branch_b_employee_1', 'org_head');
        // Tatiana from Bălți follows Ion — and still gets nothing about his posts for the sector Centru.
        $follow('balti_employee_1', 'branch_a_employee_1');
    }

    private function publicPosts(): void
    {
        $welcome = $this->publish('org_head', self::WELCOME." Aici publicăm anunțuri, discutăm și ne ajutăm între noi.\nScrieți, comentați, propuneți.", ['visibility' => Post::PUBLIC]);
        $this->by('org_head', fn (User $elena) => app(ManagePosts::class)->pin($elena, $welcome, PostPin::GLOBAL));
        $this->at(27, fn () => $this->publish('hr', self::HR_NOTICE.': контакты, навыки, языки. Это помогает находить нужных людей.', ['visibility' => Post::PUBLIC]));
        $this->at(26, fn () => $this->publish('moderator', self::RULES.'. Reports go to the moderators of your region.', ['visibility' => Post::PUBLIC]));

        foreach (['branch_a_head' => 'support', 'branch_a_employee_1' => 'like', 'balti_employee_1' => 'like', 'branch_b_employee_1' => 'celebrate', 'volunteer' => 'thanks'] as $key => $reaction) {
            $this->react($key, $welcome, $reaction);
        }
    }

    private function regionalPosts(): void
    {
        // A whole region, a sector, another sector (ru), another region, a district (en).
        $chisinau = $this->publish('chisinau_head', self::CHISINAU.' — sâmbătă, ora 11:00, la sediul regional. Participă ambele filiale.', $this->region('chisinau'));
        $this->by('chisinau_head', fn (User $mihai) => app(ManagePosts::class)->pin($mihai, $chisinau, PostPin::TERRITORY, Personas::territory('chisinau')->id));
        $this->at(23, fn () => $this->publish('branch_b_employee_1', self::BOTANICA.': собираемся в субботу в 10:00 у парка. Перчатки и мешки будут.', $this->region('chisinau/sectorul-botanica')));
        $balti = $this->at(22, fn () => $this->publish('balti_head', self::BALTI.' — joi, la Casa de Cultură.', $this->region('balti')));
        $this->at(21, fn () => $this->publish('north_south_employee', self::CAHUL.'. Two days, three villages; volunteers are welcome.', $this->region('cahul')));

        $this->at(21, function () use ($balti, $chisinau): void {
            $this->comment('balti_employee_2', $balti, 'Vin cu doi voluntari.');
            $this->react('balti_employee_2', $balti, 'support');
            $this->comment('branch_b_head', $chisinau, 'Filiala B vine în componență deplină.');
            $this->react('branch_a_head', $chisinau, 'important');
            $this->react('branch_b_employee_3', $chisinau, 'like');
        });
    }

    /**
     * A poll with a thread four levels deep, a quotation, and a comment hidden by the author of the post.
     */
    private function discussion(): void
    {
        $post = $this->publish('branch_a_employee_1', self::CENTRU_POLL, [...$this->region('chisinau/sectorul-centru'), 'poll_options' => ['Sâmbătă dimineață', 'Sâmbătă după-amiază', 'Duminică']]);
        [$morning, $afternoon, $sunday] = $post->pollOptions()->get()->all();
        foreach (['branch_a_employee_2' => $morning, 'branch_a_employee_3' => $morning, 'branch_a_head' => $afternoon, 'volunteer' => $sunday] as $key => $option) {
            $this->by($key, fn (User $voter) => app(ManagePosts::class)->vote($voter, $post, $option->id));
        }

        $first = $this->comment('branch_a_employee_2', $post, 'Sâmbătă dimineață e cel mai bine — până la prânz lumea e acasă.');
        $second = $this->comment('branch_a_employee_3', $post, 'De acord, dar atunci avem nevoie de mașină la 8:00.', $first);
        $third = $this->comment('branch_a_head', $post, 'Mașina o rezolv eu.', $second, $first);
        $this->comment('branch_a_employee_1', $post, 'Mulțumesc! Atunci rămâne sâmbătă dimineață.', $third);
        $offTopic = $this->comment('volunteer', $post, 'Apropo, cine vinde bilete la concert?');
        $this->by('branch_a_employee_1', fn (User $ion) => app(Moderation::class)->hide($ion, $offTopic, 'Nu ține de subiect'));

        $this->react('branch_a_head', $post, 'important');
        $this->react('branch_a_employee_2', $post, 'like');
        $this->react('branch_a_employee_1', $first, 'like');
        $this->react('branch_a_employee_2', $third, 'thanks');
    }

    private function groupPosts(): void
    {
        $open = self::group(self::GROUP_OPEN);
        $source = tempnam(sys_get_temp_dir(), 'demo');
        file_put_contents($source, "Sâmbătă — sectorul Centru\nDuminică — sectorul Botanica\n");
        $program = $this->by('branch_a_employee_2', fn (User $maria): Post => app(ManagePosts::class)->create($maria, [
            'body' => self::IN_OPEN_GROUP.' — în fișierul atașat.', 'visibility' => Post::GROUP, 'group_ids' => [$open->id],
        ], [['source' => $source, 'name' => 'program-voluntari.txt', 'mime' => 'text/plain']]));
        @unlink($source);
        $this->by('branch_a_employee_2', fn (User $maria) => app(ManagePosts::class)->pin($maria, $program, PostPin::GROUP, $open->id));
        $this->comment('volunteer', $program, 'Mă înscriu pentru sâmbătă.');
        $this->react('branch_b_employee_1', $program, 'thanks');
        $this->by('branch_a_employee_2', fn (User $maria) => app(ManageGroups::class)->post($maria, $open, 'Bună tuturor! Întrebările rapide — aici, în chat.'));
        $this->by('volunteer', fn (User $radu) => app(ManageGroups::class)->post($radu, $open, 'Salut! Unde ne întâlnim sâmbătă?'));

        $this->at(19, function (): void {
            $candidates = $this->publish('org_head', self::IN_SECRET_GROUP.' — vă rog observații până vineri.', ['visibility' => Post::GROUP, 'group_ids' => [self::group(self::GROUP_SECRET)->id]]);
            $this->comment('chisinau_head', $candidates, 'Pentru Chișinău propun încă două persoane.');
        });
        $this->at(11, fn () => $this->publish('branch_a_head', self::IN_CLOSED_GROUP.' — luni, 18:00.', ['visibility' => Post::GROUP, 'group_ids' => [self::group(self::GROUP_CLOSED)->id]]));
    }

    private function targetedAndPrivate(): void
    {
        // "All regional heads": a whole role is addressed — only by someone who may publish to the whole organization.
        $this->publish('org_head', self::TO_HEADS.': rapoartele lunare — până pe data de 5.', ['visibility' => Post::TARGETED, 'role_codes' => ['unit_head']]);
        $this->at(17, fn () => $this->publish('branch_a_head', self::TO_PEOPLE.' până miercuri.', [
            'visibility' => Post::TARGETED,
            'person_ids' => [Personas::user('branch_a_employee_1')->person_id, Personas::user('branch_a_employee_2')->person_id],
        ]));
        $this->at(16, fn () => $this->publish('branch_a_employee_1', self::PRIVATE_NOTE.' și de verificat lista.', ['visibility' => Post::PRIVATE]));
    }

    private function editedAndReposted(): void
    {
        $post = $this->publish('branch_a_employee_2', 'Punctul de colectare rămâne pe strada Pușkin.', $this->region('chisinau/sectorul-centru'));
        $this->at(14, fn () => $this->by('branch_a_employee_2', fn (User $maria) => app(ManagePosts::class)->update($maria, $post, 'Punctul de colectare se mută pe strada Columna, nr. 12.')));
        $this->at(13, fn () => $this->by('branch_a_employee_2', fn (User $maria) => app(ManagePosts::class)->update($maria, $post->fresh() ?? $post, self::EDITED.', nr. 12. Program: 9:00–18:00.')));

        $this->at(14, fn () => $this->publish('branch_a_employee_3', self::REPOST, [...$this->region('chisinau/sectorul-centru'), 'repost_of_post_id' => self::post(self::WELCOME)->id]));
    }

    private function moderation(): void
    {
        $moderation = app(Moderation::class);
        $person = fn (string $key) => Personas::user($key)->person;

        // A comment complained about and found acceptable: the complaint is dismissed.
        $remark = $this->comment('branch_b_employee_2', self::post(self::CHISINAU), 'Iar sâmbătă? Poate măcar o dată într-o zi de lucru.');
        $complaint = $this->by('branch_b_employee_1', fn (User $olga) => $moderation->report($olga, $remark, 'insult'));
        $this->at(11, fn () => $this->by('moderator', fn (User $eugen) => $moderation->dismiss($eugen, $complaint)));

        // An advertisement in the feed of the sector: reported, then hidden by the head of the author's branch.
        $this->at(10, function () use ($moderation): void {
            $advert = $this->publish('branch_b_employee_2', self::HIDDEN.'. Писать в личку.', $this->region('chisinau/sectorul-botanica'));
            $this->by('branch_b_employee_3', fn (User $irina) => $moderation->report($irina, $advert, 'spam', 'Реклама, не по теме'));
            $this->by('branch_b_head', fn (User $pavel) => $moderation->hide($pavel, $advert, 'Реклама не относится к работе организации'));
        });

        // A mute that has already ended by itself.
        $this->at(9, fn () => $this->by('moderator', fn (User $eugen) => $moderation->mute($eugen, $person('balti_employee_2'), now()->addDays(2), 'Repetarea aceluiași mesaj în comentarii')));
        $this->at(6, fn () => Artisan::call('social:tick'));
        $this->at(5, fn () => $this->publish('balti_employee_2', self::AFTER_MUTE.'.', $this->region('balti')));

        // A rumour: the complaint is still waiting in the queue.
        $this->at(6, function () use ($moderation): void {
            $rumour = $this->publish('branch_a_employee_3', self::REPORTED.' luna viitoare. Știe cineva ceva?', $this->region('chisinau/sectorul-centru'));
            $this->by('branch_a_employee_2', fn (User $maria) => $moderation->report($maria, $rumour, 'false_info', 'Informație neconfirmată'));
        });

        // A warning, then a mute that is still running.
        $this->at(4, fn () => $this->by('moderator', fn (User $eugen) => $moderation->warn($eugen, $person('branch_b_employee_2'), 'Reclama personală nu are ce căuta în flux')));
        $this->at(1, fn () => $this->by('moderator', fn (User $eugen) => $moderation->mute($eugen, $person('branch_b_employee_2'), now()->addDays(3), 'Reclamă repetată după avertisment')));
    }

    private function draftAndScheduled(): void
    {
        $this->publish('branch_a_employee_1', self::DRAFT.' — de completat cifrele.', [...$this->region('chisinau/sectorul-centru'), 'intent' => ManagePosts::INTENT_DRAFT]);
        $this->at(0, fn () => $this->publish('branch_a_head', self::SCHEDULED.'. Locuri limitate.', [
            ...$this->region('chisinau/sectorul-centru'), 'intent' => ManagePosts::INTENT_SCHEDULE, 'publish_at' => now()->addDays(2),
        ]));
    }
}
