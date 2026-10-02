<?php

namespace Database\Seeders\Demo;

use App\Domain\Access\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Actions\ManageChats;
use App\Domain\Messaging\Actions\SendMessages;
use App\Domain\Messaging\ChatAccess;
use App\Domain\Messaging\ChatReader;
use App\Domain\Messaging\DirectMessagePolicy;
use App\Domain\Messaging\Discussions;
use App\Domain\Messaging\Models\Chat;
use App\Domain\Messaging\Models\ChatMember;
use App\Domain\Messaging\Models\Message;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\ManageTasks;
use App\Domain\Tasks\Models\Task;
use Illuminate\Database\Seeder;

/**
 * The messenger of the demo world (plan 6.2): dialogs in every state of reading, a draft and a scheduled message;
 * a group chat with roles, a pinned message, a poll, mentions, reactions, files, a deleted message; a closed chat
 * of the heads and a message forwarded out of it; the chats of a group, a project and a task — the last one with
 * a thread four levels deep and a task made from it; invitation links: a live one, an expired one, a used-up one;
 * one rule of the "who writes first" table changed by the administrator.
 */
class MessagingDemoSeeder extends Seeder
{
    use DemoSteps;

    public const string LOGISTICS = 'Logistică evenimente';

    public const string HEADS = 'Conducerea Chișinău';

    public const string BRANCH_A = 'Filiala A — operativ';

    public const string CAMPAIGN = 'Campania de toamnă — Centru';

    public const string BUDGET = 'Bugetul pe trimestrul următor este redus cu 15%';

    public const string THREAD_ROOT = 'Câte pliante ne trebuie pentru ieșirea de sâmbătă?';

    public const string TASK_FROM_THREAD = 'Comandă suplimentară de pliante';

    public const string SCHEDULED = 'Nu uita: mâine la 9:00 ridicăm materialele';

    public const string DRAFT = 'Ana, am o întrebare despre';

    public static function chat(string $title): Chat
    {
        return Chat::query()->where('type', Chat::GROUP)->where('title', $title)->firstOrFail();
    }

    public static function direct(string $personaA, string $personaB): Chat
    {
        return Chat::query()->where('direct_key', Chat::directKey(Personas::user($personaA)->person_id, Personas::user($personaB)->person_id))->firstOrFail();
    }

    public function run(): void
    {
        try {
            $this->at(15, fn () => $this->rules());
            $this->at(12, fn () => $this->dialogs());
            $this->at(10, fn () => $this->groupChat());
            $this->at(8, fn () => $this->closedChatAndForward());
            $this->at(6, fn () => $this->discussions());
            $this->at(1, fn () => $this->draftAndScheduled());
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
        // The membership and the rules are cached for a request; a seeder is one long request.
        app(ChatAccess::class)->forget();

        return $this->as($user, fn () => $step($user));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function say(string $key, Chat $chat, string $body, array $data = []): Message
    {
        return $this->by($key, fn (User $author): Message => app(SendMessages::class)->send($author, $chat, ['body' => $body, ...$data]));
    }

    private function read(string $key, Chat $chat): void
    {
        $this->by($key, fn (User $reader) => app(SendMessages::class)->markRead($reader, $chat));
    }

    private function pid(string $key): int
    {
        return Personas::user($key)->person_id;
    }

    /**
     * Д-26: the starter table lets everyone but a candidate write first. The administrator adds one rule of the
     * organization's own: a volunteer does not write first to the security service.
     */
    private function rules(): void
    {
        $this->by('super_admin', fn (User $admin) => app(DirectMessagePolicy::class)->set(
            $admin, Role::query()->where('code', 'volunteer')->firstOrFail(), Role::query()->where('code', 'security')->firstOrFail(), false,
        ));
    }

    private function dialogs(): void
    {
        $chats = app(ManageChats::class);
        $messages = app(SendMessages::class);

        // Read: Ion and Maria talk, both have seen everything.
        $ionMaria = $this->by('branch_a_employee_1', fn (User $ion): Chat => $chats->direct($ion, Personas::user('branch_a_employee_2')->person));
        $this->say('branch_a_employee_1', $ionMaria, 'Maria, ai lista cu adresele pentru sâmbătă?');
        $this->say('branch_a_employee_2', $ionMaria, 'Da, o trimit în chatul de logistică.');
        $this->at(11, function () use ($ionMaria): void {
            $this->say('branch_a_employee_1', $ionMaria, 'Mulțumesc!');
            $this->read('branch_a_employee_2', $ionMaria);
            $this->read('branch_a_employee_1', $ionMaria);
        });

        // Delivered, not read: Sergiu has opened the messenger since, but not this dialog.
        $this->at(3, function () use ($chats, $messages): void {
            $anaSergiu = $this->by('branch_a_head', fn (User $ana): Chat => $chats->direct($ana, Personas::user('branch_a_employee_3')->person));
            $this->say('branch_a_head', $anaSergiu, 'Sergiu, treci te rog mâine pe la mine.');
            $this->at(2, fn () => $this->by('branch_a_employee_3', fn (User $sergiu) => $messages->markDelivered($sergiu)));
        });

        // Sent only: Pavel has not opened the messenger yet.
        $this->at(1, function () use ($chats): void {
            $mihaiPavel = $this->by('chisinau_head', fn (User $mihai): Chat => $chats->direct($mihai, Personas::user('branch_b_head')->person));
            $this->say('chisinau_head', $mihaiPavel, 'Pavel, raportul filialei B îl aștept până vineri.');
        });

        // A volunteer writes first to the head of his branch (Д-26: to anyone he sees), and gets an answer.
        $this->at(9, function () use ($chats): void {
            $raduAna = $this->by('volunteer', fn (User $radu): Chat => $chats->direct($radu, Personas::user('branch_a_head')->person));
            $this->say('volunteer', $raduAna, 'Bună ziua! Pot ajuta și la evenimentele din alte sectoare?');
            $this->at(8, function () use ($raduAna): void {
                $this->say('branch_a_head', $raduAna, 'Bună ziua, Radu! Sigur — te trec pe lista pentru Botanica.');
                $this->read('volunteer', $raduAna);
            });
        });

        // The security service writes first; the volunteer may only answer there.
        $this->at(7, function () use ($chats): void {
            $securityRadu = $this->by('security', fn (User $alexandru): Chat => $chats->direct($alexandru, Personas::user('volunteer')->person));
            $this->say('security', $securityRadu, 'Bună ziua. Vă rog să confirmați că ați activat autentificarea în doi pași.');
            $this->say('volunteer', $securityRadu, 'Da, am activat-o ieri.');
        });
    }

    /**
     * A thematic chat with everything in it.
     */
    private function groupChat(): void
    {
        $chats = app(ManageChats::class);
        $messages = app(SendMessages::class);
        $chat = $this->by('branch_a_employee_2', fn (User $maria): Chat => $chats->createGroup($maria, self::LOGISTICS, [
            $this->pid('branch_a_employee_1'), $this->pid('branch_a_employee_3'), $this->pid('volunteer'), $this->pid('branch_b_employee_1'),
        ]));
        $this->by('branch_a_employee_2', function (User $maria) use ($chats, $chat): void {
            $chats->setRole($maria, $chat, Personas::user('branch_a_employee_1')->person, ChatMember::ADMIN);
            $chats->setRole($maria, $chat, Personas::user('branch_b_employee_1')->person, ChatMember::MODERATOR);
        });

        $rules = $this->say('branch_a_employee_2', $chat, 'Aici discutăm doar logistica: transport, materiale, săli. Restul — în chaturile filialelor.');
        $this->by('branch_a_employee_2', fn (User $maria) => $messages->pin($maria, $rules));

        // Files: a list and a picture. No scanner in the demo environment — they are marked as unchecked.
        $list = tempnam(sys_get_temp_dir(), 'demo');
        file_put_contents($list, "Pliante — 500 buc.\nBanner — 2 buc.\nMasă pliantă — 1 buc.\n");
        $picture = tempnam(sys_get_temp_dir(), 'demo');
        file_put_contents($picture, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $this->by('branch_a_employee_2', fn (User $maria) => $messages->send($maria, $chat, ['body' => 'Lista materialelor și schema amplasării.'], [
            ['source' => $list, 'name' => 'lista-materiale.txt', 'mime' => 'text/plain'],
            ['source' => $picture, 'name' => 'schema-amplasare.png', 'mime' => 'image/png'],
        ]));
        @unlink($list);
        @unlink($picture);

        $this->at(9, function () use ($chat, $messages): void {
            $poll = $this->say('branch_a_employee_1', $chat, 'Cu ce mergem sâmbătă?', ['poll_options' => ['Microbuzul organizației', 'Mașini personale', 'Transport public']]);
            [$bus, $cars] = $poll->pollOptions()->get()->all();
            foreach (['branch_a_employee_2' => $bus, 'branch_a_employee_3' => $bus, 'volunteer' => $cars, 'branch_b_employee_1' => $bus] as $key => $option) {
                $this->by($key, fn (User $voter) => $messages->vote($voter, $poll, $option->id));
            }
            $this->by('branch_a_employee_2', fn (User $maria) => $messages->react($maria, $poll, 'like'));
            $this->by('volunteer', fn (User $radu) => $messages->react($radu, $poll, 'thanks'));
        });

        $this->at(8, function () use ($chat, $messages): void {
            // A mention of one person, and "@all" by the owner of the chat.
            $this->say('branch_a_employee_2', $chat, 'Ion, poți rezerva microbuzul?', ['mention_person_ids' => [$this->pid('branch_a_employee_1')]]);
            $this->say('branch_a_employee_1', $chat, 'Rezervat, plecăm la 8:30.');
            $this->say('branch_a_employee_2', $chat, 'Plecarea sâmbătă la 8:30 de la sediu. Vă rog să confirmați.', ['mention_all' => true]);

            // Deleted with a mark: by a moderator of the chat (journaled) and by its own author.
            $offTopic = $this->say('branch_a_employee_3', $chat, 'Apropo, vinde cineva un bilet la meci?');
            $this->by('branch_b_employee_1', fn (User $olga) => $messages->delete($olga, $offTopic));
            $typo = $this->say('volunteer', $chat, 'Vin si eu, confrm.');
            $this->by('volunteer', fn (User $radu) => $messages->delete($radu, $typo));
            $this->say('volunteer', $chat, 'Vin și eu, confirm.');
        });

        // Invitation links: one used up, one expired, one still good.
        $this->at(10, function () use ($chat, $chats): void {
            $single = $this->by('branch_a_employee_2', fn (User $maria): array => $chats->inviteByLink($maria, $chat, null, 1));
            $this->by('central_employee', fn (User $diana) => $chats->joinByLink($diana, $single['token']));
            $this->by('branch_a_employee_2', fn (User $maria) => $chats->inviteByLink($maria, $chat, now()->addDays(3)));
        });
        $this->at(1, fn () => $this->by('branch_a_employee_1', fn (User $ion) => $chats->inviteByLink($ion, $chat, now()->addDays(14), 10)));

        foreach (['branch_a_employee_1', 'branch_a_employee_2', 'branch_b_employee_1'] as $key) {
            $this->at(7, fn () => $this->read($key, $chat));
        }
    }

    /**
     * A chat of the heads, and a message forwarded from it to the branch: the branch sees that something was
     * forwarded, not what.
     */
    private function closedChatAndForward(): void
    {
        $chats = app(ManageChats::class);
        $heads = $this->by('chisinau_head', fn (User $mihai): Chat => $chats->createGroup($mihai, self::HEADS, [$this->pid('branch_a_head'), $this->pid('branch_b_head')]));
        $budget = $this->say('chisinau_head', $heads, self::BUDGET.'. Detaliile — la ședința de luni.');
        $this->say('branch_b_head', $heads, 'Am înțeles. La filiala B amânăm tipăriturile.');

        $branch = $this->by('branch_a_head', fn (User $ana): Chat => $chats->createGroup($ana, self::BRANCH_A, [
            $this->pid('branch_a_employee_1'), $this->pid('branch_a_employee_2'), $this->pid('branch_a_employee_3'),
        ]));
        $this->say('branch_a_head', $branch, 'Colegi, chatul operativ al filialei. Aici — tot ce ține de lucrul curent.');
        $this->at(7, function () use ($branch, $budget): void {
            $this->by('branch_a_head', fn (User $ana) => app(SendMessages::class)->forward($ana, $budget, $branch, 'Luni discutăm cum ne afectează.'));
            $this->say('branch_a_employee_1', $branch, 'Despre ce este vorba? Nu văd mesajul.');
            $this->say('branch_a_head', $branch, 'Așa e — e din chatul conducerii. Vă povestesc luni.');
        });
    }

    /**
     * The chats of objects: a group, a project, a task. In the task — a long thread and a task made from it.
     */
    private function discussions(): void
    {
        $discussions = app(Discussions::class);
        $messages = app(SendMessages::class);

        // The chat of the open group already has two messages (phase 4): a reply inside a thread now.
        $groupChat = $discussions->forSubject(SocialDemoSeeder::group(SocialDemoSeeder::GROUP_OPEN));
        $question = $groupChat->messages()->orderByDesc('id')->firstOrFail();
        $this->say('branch_a_employee_1', $groupChat, 'La 8:30 la sediu, plecăm împreună.', ['parent_id' => $question->id]);

        // The chat of the project: opened from the page of the project by those who may read it.
        $project = Project::query()->where('name', self::CAMPAIGN)->firstOrFail();
        $projectChat = $discussions->forSubject($project);
        $this->say('branch_a_head', $projectChat, 'Etapa a doua a început. Raportul intermediar — până vineri.');
        $this->say('branch_a_employee_3', $projectChat, 'Materialele sunt la sediu, le-am recepționat.');
        $this->say('volunteer', $projectChat, 'Pot ajuta la împărțit vineri după-amiază.');

        // The chat of a task: a thread four levels deep, a quotation, and a task made out of the discussion.
        $task = Task::query()->where('project_id', $project->id)->whereHas('people', fn ($people) => $people->where('person_id', $this->pid('volunteer')))->orderBy('id')->firstOrFail();
        $taskChat = $discussions->forSubject($task);
        $root = $this->say('branch_a_head', $taskChat, self::THREAD_ROOT);
        $first = $this->say('branch_a_employee_1', $taskChat, 'Pentru 12 blocuri — vreo 600.', ['parent_id' => $root->id]);
        $second = $this->say('volunteer', $taskChat, 'La ultima ieșire au ajuns 400 pentru 10 blocuri.', ['parent_id' => $first->id]);
        $third = $this->say('branch_a_head', $taskChat, 'Atunci 500 ar trebui să ajungă. Câte avem în stoc?', ['parent_id' => $second->id]);
        $this->say('branch_a_employee_1', $taskChat, 'În stoc sunt 300. Mai trebuie comandate 200.', ['parent_id' => $third->id, 'quoted_message_id' => $third->id]);
        $this->say('branch_a_employee_1', $taskChat, 'Am confirmat sala pentru instructaj, apropo.');

        $this->at(5, function () use ($messages, $root): void {
            $this->by('branch_a_head', function (User $ana) use ($messages, $root): void {
                $created = app(ManageTasks::class)->create($ana, [
                    'title' => self::TASK_FROM_THREAD, 'type_code' => 'assignment',
                    'description' => app(ChatReader::class)->threadDigest($ana, $root), 'due_at' => now()->addDays(4),
                ], [$this->pid('branch_a_employee_3')]);
                $messages->linkTask($ana, $root, $created->id, $created->title);
            });
        });
    }

    private function draftAndScheduled(): void
    {
        $chats = app(ManageChats::class);
        $messages = app(SendMessages::class);
        $ionAna = $this->by('branch_a_employee_1', fn (User $ion): Chat => $chats->direct($ion, Personas::user('branch_a_head')->person));
        $this->say('branch_a_head', $ionAna, 'Ion, cum stăm cu rezervarea microbuzului?');
        $this->by('branch_a_employee_1', fn (User $ion) => $messages->saveDraft($ion, $ionAna, self::DRAFT.' microbuz — '));
        // Typed today, to be sent tomorrow morning.
        $this->at(0, fn () => $this->say('branch_a_head', $ionAna, self::SCHEDULED.'.', ['send_at' => now()->addDay()->setTime(9, 0)]));
    }
}
