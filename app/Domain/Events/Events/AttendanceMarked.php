<?php

declare(strict_types=1);

namespace App\Domain\Events\Events;

use App\Domain\Events\Models\Event;

/**
 * A structured fact (ФО §5.3): the person was — or, after a correction, was not — at the event.
 * Other modules (CRM, later gamification and reports) listen to it instead of reading the tables of Events.
 */
final readonly class AttendanceMarked
{
    public function __construct(
        public Event $event,
        public int $personId,
        public bool $attended,
        public int $markedByUserId,
    ) {}
}
