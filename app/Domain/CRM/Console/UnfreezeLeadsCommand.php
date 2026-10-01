<?php

declare(strict_types=1);

namespace App\Domain\CRM\Console;

use App\Domain\Audit\JournalContext;
use App\Domain\CRM\Actions\ManageLeads;
use Illuminate\Console\Command;

/**
 * ФО §6.9.2: a frozen lead returns to work on its date; the responsible is notified.
 */
final class UnfreezeLeadsCommand extends Command
{
    protected $signature = 'crm:unfreeze-leads';

    protected $description = 'Return frozen leads whose date has come back to work';

    public function handle(ManageLeads $leads, JournalContext $context): int
    {
        $context->asSystem('scheduler:crm:unfreeze-leads');
        $this->info('Unfrozen: '.$leads->unfreezeDue());

        return self::SUCCESS;
    }
}
