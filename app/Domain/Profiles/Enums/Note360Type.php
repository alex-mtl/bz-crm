<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Enums;

/**
 * Д-14: the author chooses the type when creating the note; it never changes afterwards.
 */
enum Note360Type: string
{
    /** author + the subject's direct manager + organization head (+ HR by setting) */
    case Feedback = 'feedback';

    /** author + the author's management (manager chain and organization head) */
    case Personal = 'personal';

    public function label(): string
    {
        return __('profiles.note360_types.'.$this->value);
    }
}
