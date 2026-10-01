<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Exceptions\AccessRuleViolation;
use App\Domain\Access\Models\TerritoryGrant;
use App\Domain\Access\Scopes\ScopeType;
use App\Domain\Access\TerritorialAccess;
use App\Domain\Audit\EventJournal;
use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\OrgStructure;
use App\Domain\People\Models\Person;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Additional territories for a person (Д-3):
 *  - who may give: the direct manager (by relation), or someone above in the manager chain or with the right
 *    `access.territories.grant` in a scope covering the person;
 *  - only within the giver's own access; a reason is mandatory; an end date is optional and is enforced by the scheduler.
 * Refusals are journaled too.
 */
final readonly class ManageTerritoryGrants
{
    public function __construct(
        private AuthorizationService $authorization,
        private TerritorialAccess $territories,
        private OrgStructure $org,
        private EventJournal $journal,
    ) {}

    public function grant(User $actor, Person $person, Territory $territory, string $reason, ?Carbon $expiresAt = null): TerritoryGrant
    {
        if (trim($reason) === '') {
            throw AccessRuleViolation::because('reason_required');
        }
        if ($expiresAt !== null && $expiresAt->isPast()) {
            throw AccessRuleViolation::because('end_date_in_past');
        }
        if (! $this->mayManageFor($actor, $person)) {
            $this->refuse($actor, $person, $territory, 'not_allowed');
            throw new AuthorizationException(__('access.denied'));
        }
        if (! $this->coversTerritory($actor, $territory)) {
            $this->refuse($actor, $person, $territory, 'outside_own_access');
            throw AccessRuleViolation::because('territory_outside_own_access');
        }

        $grant = DB::transaction(function () use ($actor, $person, $territory, $reason, $expiresAt): TerritoryGrant {
            $grant = TerritoryGrant::query()->create([
                'person_id' => $person->id,
                'territory_id' => $territory->id,
                'granted_by_user_id' => $actor->id,
                'reason' => trim($reason),
                'granted_at' => now(),
                'expires_at' => $expiresAt,
            ]);
            $this->journal->record('access.territory.granted', $person, [], [
                'territory_id' => $territory->id,
                'territory' => $territory->code,
                'reason' => trim($reason),
                'expires_at' => $expiresAt?->toIso8601String(),
            ]);

            return $grant;
        });
        $this->authorization->forget();

        return $grant;
    }

    public function revoke(User $actor, TerritoryGrant $grant, string $comment): void
    {
        if ($grant->ended_at !== null) {
            return;
        }
        if (trim($comment) === '') {
            throw AccessRuleViolation::because('reason_required');
        }
        if (! $this->mayManageFor($actor, $grant->person)) {
            throw new AuthorizationException(__('access.denied'));
        }

        DB::transaction(function () use ($actor, $grant, $comment): void {
            $grant->update(['ended_at' => now(), 'end_reason' => 'revoked', 'ended_by_user_id' => $actor->id, 'end_comment' => trim($comment)]);
            $this->journal->record('access.territory.revoked', $grant->person, ['territory_id' => $grant->territory_id], ['reason' => trim($comment)]);
        });
        $this->authorization->forget();
    }

    /**
     * Automatic revocation by end date (Д-3 п. 2), run by the scheduler; each expiry is journaled.
     */
    public function expireDue(): int
    {
        $count = 0;
        foreach (TerritoryGrant::query()->with('person')->whereNull('ended_at')->whereNotNull('expires_at')->where('expires_at', '<=', now())->get() as $grant) {
            DB::transaction(function () use ($grant): void {
                $grant->update(['ended_at' => $grant->expires_at, 'end_reason' => 'expired']);
                $this->journal->record('access.territory.expired', $grant->person, ['territory_id' => $grant->territory_id], ['expired_at' => $grant->expires_at?->toIso8601String()]);
            });
            $count++;
        }
        if ($count > 0) {
            $this->authorization->forget();
        }

        return $count;
    }

    public function mayManageFor(User $actor, Person $person): bool
    {
        if ($actor->person_id === $person->id || ! $actor->isActive()) {
            return false;
        }
        if ($this->org->isDirectManager($actor->person_id, $person->id)) {
            return true;
        }
        $holdsCode = $this->authorization->grantsFor($actor, 'access.territories.grant') !== [];
        if ($holdsCode && in_array($actor->person_id, $this->org->managerChain($person->id), true)) {
            return true;
        }

        return $this->authorization->can($actor, 'access.territories.grant', $person);
    }

    /**
     * "Not more than you have" for territories (Д-3 п. 1): the giver's effective territories, or a scope of the
     * right that covers the territory (organization-wide or a territory subtree).
     */
    public function coversTerritory(User $actor, Territory $territory): bool
    {
        if ($this->territories->covers($actor->person_id, $territory)) {
            return true;
        }

        return $this->authorization->coversScope($actor, 'access.territories.grant', ScopeType::Territory, $territory->id);
    }

    private function refuse(User $actor, Person $person, Territory $territory, string $why): void
    {
        $this->journal->record('access.territory.grant_denied', $person, [], [
            'territory_id' => $territory->id, 'territory' => $territory->code, 'actor_user_id' => $actor->id, 'why' => $why,
        ]);
    }
}
