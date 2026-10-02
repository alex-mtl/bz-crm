<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use Illuminate\Support\ServiceProvider;

final class FilesServiceProvider extends ServiceProvider
{
    public function boot(EventTypeRegistry $types): void
    {
        $types->register(
            new EventType('files.antivirus.enabled', EventCategory::Security, EventSeverity::Notice),
            new EventType('files.antivirus.disabled', EventCategory::Security, EventSeverity::Warning),
        );
    }
}
