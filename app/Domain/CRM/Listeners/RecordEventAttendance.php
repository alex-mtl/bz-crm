<?php

declare(strict_types=1);

namespace App\Domain\CRM\Listeners;

use App\Domain\Audit\EventJournal;
use App\Domain\CRM\Models\Interaction;
use App\Domain\Events\Events\AttendanceMarked;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;

/**
 * ФО §5.3, §6.7: a visit of an event is a structured fact in the feed of the person — "был на N мероприятиях"
 * in segments counts exactly these. The mark of attendance is the authority: the fact appears when the person
 * is marked as present and disappears when the mark is corrected.
 */
final readonly class RecordEventAttendance
{
    public const string KIND = 'event_visit';

    public function __construct(private EventJournal $journal) {}

    public function handle(AttendanceMarked $marked): void
    {
        $key = [
            'person_id' => $marked->personId, 'kind_code' => self::KIND,
            'subject_type' => $marked->event->getMorphClass(), 'subject_id' => $marked->event->id,
        ];
        $existing = Interaction::query()->where($key)->first();

        if (! $marked->attended) {
            $existing?->delete();

            return;
        }
        if ($existing !== null) {
            return;
        }

        $interaction = Interaction::query()->create([
            ...$key,
            'occurred_at' => $marked->event->starts_at,
            'summary' => $marked->event->title,
            'author_person_id' => User::query()->whereKey($marked->markedByUserId)->value('person_id'),
        ]);
        $person = Person::query()->find($marked->personId);
        if ($person !== null) {
            $this->journal->record('crm.interaction.recorded', $person, [], ['interaction_id' => $interaction->id, 'kind' => self::KIND, 'event_id' => $marked->event->id]);
        }
    }
}
