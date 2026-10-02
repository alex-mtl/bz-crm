<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Jobs;

use App\Domain\Audit\EventJournal;
use App\Domain\Audit\JournalContext;
use App\Domain\Files\FileGate;
use App\Domain\Files\ScanVerdict;
use App\Domain\Messaging\Events\ChatUpdated;
use App\Domain\Messaging\Models\MessageAttachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

/**
 * The antivirus check of a file sent to a chat (ФО §6.6.4). Until it says "clean" the file cannot be opened;
 * an infected file is removed from the disk and stays in the chat only as a mark.
 */
final class ScanMessageAttachment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 10;

    public function __construct(public readonly int $attachmentId) {}

    public function handle(FileGate $gate, EventJournal $journal, JournalContext $context): void
    {
        $attachment = MessageAttachment::query()->with('message')->find($this->attachmentId);
        if ($attachment === null || $attachment->scan_status !== MessageAttachment::PENDING) {
            return;
        }
        $verdict = Storage::disk('local')->exists($attachment->path)
            ? $gate->scan(Storage::disk('local')->path($attachment->path))
            : ScanVerdict::Infected;

        if ($verdict === ScanVerdict::Unavailable) {
            // The scanner is down: the file waits, the check is tried again.
            $this->release(60);

            return;
        }

        $attachment->update(['scan_status' => $verdict->value, 'scanned_at' => now()]);
        if ($verdict === ScanVerdict::Infected) {
            Storage::disk('local')->delete($attachment->path);
            $context->asSystem('queue:messaging:scan');
            $journal->record('messaging.attachment.infected', $attachment->message, [], ['file' => $attachment->original_name]);
        }
        event(new ChatUpdated($attachment->message->chat_id, 'attachment', $attachment->message_id));
    }
}
