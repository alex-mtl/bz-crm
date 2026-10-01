<?php

declare(strict_types=1);

namespace App\Domain\CRM\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CRM\Models\Interaction;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records a touch with a person — a call, a meeting, a note about a contact (ФО §6.9.1). It may point at what it
 * was about: a lead, an appeal, a task.
 */
final readonly class RecordInteraction
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    /**
     * @param  array{summary?: string|null, direction?: string|null, duration_minutes?: int|null, occurred_at?: Carbon|string|null}  $data
     */
    public function __invoke(User $actor, Person $person, string $kindCode, array $data = [], ?Model $subject = null): Interaction
    {
        $this->authorization->authorize($actor, 'crm.interactions.create', $person);

        if (! CatalogItem::query()->ofCatalog('interaction_kinds')->selectable()->where('code', $kindCode)->exists()) {
            throw CrmRuleViolation::because('invalid_interaction_kind');
        }
        $direction = $data['direction'] ?? null;
        if ($direction !== null && ! in_array($direction, [Interaction::IN, Interaction::OUT], true)) {
            throw CrmRuleViolation::because('invalid_direction');
        }
        $occurredAt = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();
        if ($occurredAt->isFuture()) {
            throw CrmRuleViolation::because('interaction_in_future');
        }

        return DB::transaction(function () use ($actor, $person, $kindCode, $data, $direction, $occurredAt, $subject): Interaction {
            $interaction = Interaction::query()->create([
                'person_id' => $person->id,
                'kind_code' => $kindCode,
                'direction' => $direction,
                'occurred_at' => $occurredAt,
                'summary' => filled($data['summary'] ?? null) ? trim((string) $data['summary']) : null,
                'duration_minutes' => $data['duration_minutes'] ?? null,
                'author_person_id' => $actor->person_id,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
            ]);
            // The text of the contact stays in the feed; the journal keeps the fact.
            $this->journal->record('crm.interaction.recorded', $person, [], [
                'interaction_id' => $interaction->id, 'kind' => $kindCode, 'direction' => $direction,
            ]);

            return $interaction;
        });
    }
}
