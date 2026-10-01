<?php

declare(strict_types=1);

namespace App\Domain\Social\Console;

use App\Domain\Audit\JournalContext;
use App\Domain\Social\Actions\ManagePosts;
use App\Domain\Social\Moderation;
use Illuminate\Console\Command;

/**
 * ФО §6.4.1: scheduled posts are published when their time comes; ФО §6.4.4: mutes whose time is over are closed.
 */
final class PublishScheduledPostsCommand extends Command
{
    protected $signature = 'social:tick';

    protected $description = 'Publish scheduled posts whose time has come and close expired mutes';

    public function handle(ManagePosts $posts, Moderation $moderation, JournalContext $context): int
    {
        $context->asSystem('scheduler:social:tick');
        $this->info('Published: '.$posts->publishDue().'; mutes expired: '.$moderation->expireMutes());

        return self::SUCCESS;
    }
}
