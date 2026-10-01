<?php

declare(strict_types=1);

namespace App\Domain\Access\Console;

use App\Domain\Access\Actions\ManageTerritoryGrants;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\UserRole;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Automatic revocation by end date (ФО §3.5, Д-3): territory grants end, temporary roles and delegations
 * stop counting (they already stop at the end date — here the expiry is recorded in the journal).
 */
final class ExpireAccessCommand extends Command
{
    protected $signature = 'access:expire';

    protected $description = 'Revoke territory grants, temporary roles and delegations whose end date has passed';

    public function handle(ManageTerritoryGrants $grants, EventJournal $journal, AuthorizationService $authorization): int
    {
        $territories = $grants->expireDue();

        $roles = 0;
        $due = UserRole::query()->with('role')->whereNotNull('expires_at')->where('expires_at', '<=', now())->whereNull('expiry_recorded_at')->get();
        foreach ($due as $assignment) {
            DB::transaction(function () use ($assignment, $journal): void {
                $assignment->update(['expiry_recorded_at' => now()]);
                $journal->record('access.role.expired', User::query()->find($assignment->user_id), [], [
                    'role' => $assignment->role->code,
                    'kind' => $assignment->kind,
                    'scope' => $assignment->scope_type,
                    'scope_id' => $assignment->scope_id,
                    'expired_at' => $assignment->expires_at?->toIso8601String(),
                ]);
            });
            $roles++;
        }
        if ($roles > 0) {
            $authorization->forget();
        }

        $this->info("Expired: {$territories} territory grant(s), {$roles} role assignment(s).");

        return self::SUCCESS;
    }
}
