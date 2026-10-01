<?php

declare(strict_types=1);

namespace App\Domain\People\Events;

use App\Domain\People\Models\Person;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A card was created or its identifying fields (name, e-mail, phone) changed — e.g. the CRM looks for duplicates.
 */
final class PersonSaved
{
    use Dispatchable;

    public function __construct(public readonly Person $person, public readonly bool $created) {}
}
