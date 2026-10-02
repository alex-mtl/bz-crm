<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\UserRole;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\DirectMessageRule;
use Illuminate\Support\Facades\DB;

/**
 * Д-26 (ФО §6.6.1): who may start a direct dialog with whom — a table "sender role → recipient role" kept by the
 * super admin. A cell nobody has set takes its starter value: a candidate may not, everyone else may.
 *
 * People hold several roles. The sender needs one role that is let through to every role of the recipient:
 * a head of the organization who is also an employee stays as protected as the strictest of the two rules says.
 * Answering in a dialog somebody else started is not a matter of this table.
 */
final class DirectMessagePolicy
{
    /** @var array<string, bool>|null "senderRoleId:recipientRoleId" => allowed */
    private ?array $rules = null;

    /** @var list<int>|null ids of the roles that start no dialogs unless a rule says otherwise */
    private ?array $restricted = null;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly EventJournal $journal,
    ) {}

    public function mayStart(User $sender, User $recipient): bool
    {
        $senderRoles = $this->roleIds($sender);
        $recipientRoles = $this->roleIds($recipient);
        if ($senderRoles === []) {
            return false;
        }
        foreach ($senderRoles as $senderRole) {
            $blocked = false;
            foreach ($recipientRoles as $recipientRole) {
                if (! $this->allowed($senderRole, $recipientRole)) {
                    $blocked = true;
                    break;
                }
            }
            if (! $blocked) {
                return true;
            }
        }

        return false;
    }

    public function allowed(int $senderRoleId, int $recipientRoleId): bool
    {
        $this->rules ??= DirectMessageRule::query()->get()
            ->mapWithKeys(fn (DirectMessageRule $rule): array => [$rule->sender_role_id.':'.$rule->recipient_role_id => $rule->allowed])->all();

        return $this->rules[$senderRoleId.':'.$recipientRoleId] ?? $this->startsDialogsByDefault($senderRoleId);
    }

    public function set(User $actor, Role $sender, Role $recipient, bool $allowed): void
    {
        $this->authorization->authorize($actor, 'messaging.policies.manage');
        if ($this->allowed($sender->id, $recipient->id) === $allowed) {
            return;
        }

        DB::transaction(function () use ($sender, $recipient, $allowed): void {
            DirectMessageRule::query()->updateOrCreate(
                ['sender_role_id' => $sender->id, 'recipient_role_id' => $recipient->id], ['allowed' => $allowed],
            );
            $this->journal->record('messaging.policy.changed', $sender, ['allowed' => ! $allowed], ['recipient_role' => $recipient->code, 'allowed' => $allowed]);
        });
        $this->rules = null;
    }

    /**
     * The starter value of a cell nobody has set (Д-26): a candidate starts no dialogs; everyone else writes to
     * everyone they see. It holds for roles created later too, with no rows to keep in step.
     */
    public function startsDialogsByDefault(int $senderRoleId): bool
    {
        $this->restricted ??= Role::query()->where('code', 'candidate')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return ! in_array($senderRoleId, $this->restricted, true);
    }

    public function forget(): void
    {
        $this->rules = null;
        $this->restricted = null;
    }

    /**
     * @return list<int>
     */
    private function roleIds(User $user): array
    {
        return UserRole::query()->inEffect()->where('user_id', $user->id)->distinct()->pluck('role_id')->map(fn ($id): int => (int) $id)->all();
    }
}
