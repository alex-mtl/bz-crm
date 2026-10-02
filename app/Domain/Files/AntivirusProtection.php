<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Files\Exceptions\AntivirusUnavailable;
use App\Domain\Identity\Models\User;
use App\Support\Settings\SystemSettings;
use Illuminate\Support\Facades\DB;

/**
 * Д-28: the antivirus check of uploaded files is a system setting the super admin turns on and off in the panel.
 * Off (the starting state) — files are accepted unchecked; on — every file goes to the scanner. Where the scanner
 * lives is infrastructure (.env), whether it is used is this switch.
 */
final readonly class AntivirusProtection
{
    public const string SETTING = 'files.antivirus_enabled';

    public function __construct(
        private SystemSettings $settings,
        private AuthorizationService $authorization,
        private EventJournal $journal,
        private AttachmentScanner $scanner,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->get(self::SETTING) === true;
    }

    /** Does the antivirus service answer right now. */
    public function reachable(): bool
    {
        return $this->scanner->reachable();
    }

    public function set(User $actor, bool $enabled): void
    {
        $this->authorization->authorize($actor, 'system.settings.manage');
        if ($this->enabled() === $enabled) {
            return;
        }
        // Turning it on with no service behind would stop every upload: refuse, and say why.
        if ($enabled && ! $this->scanner->reachable()) {
            throw AntivirusUnavailable::make();
        }

        DB::transaction(function () use ($actor, $enabled): void {
            $this->settings->put(self::SETTING, $enabled, $actor->id);
            $this->journal->record($enabled ? 'files.antivirus.enabled' : 'files.antivirus.disabled', $actor, ['enabled' => ! $enabled], ['enabled' => $enabled]);
        });
    }
}
