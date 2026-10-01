<?php

declare(strict_types=1);

namespace App\Domain\People;

use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use Illuminate\Support\ServiceProvider;

final class PeopleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PersonReferences::class);
    }

    public function boot(EventTypeRegistry $types): void
    {
        $this->callAfterResolving(PersonReferences::class, function (PersonReferences $references): void {
            $references->register('account_link_hints', 'new_person_id', ['existing_person_id']);
            $references->register('account_link_hints', 'existing_person_id', ['new_person_id']);
        });

        $types->register(
            new EventType('people.candidate.registered', EventCategory::Data, EventSeverity::Notice),
            new EventType('people.exported', EventCategory::Access, EventSeverity::Notice),
            new EventType('people.person.created', EventCategory::Data),
            new EventType('people.person.updated', EventCategory::Data),
            new EventType('people.person.archived', EventCategory::Data, EventSeverity::Notice),
            new EventType('people.person.restored', EventCategory::Data, EventSeverity::Notice),
            new EventType('people.link_hint.created', EventCategory::Data, EventSeverity::Notice),
            new EventType('people.link_hint.dismissed', EventCategory::Data),
        );
    }
}
