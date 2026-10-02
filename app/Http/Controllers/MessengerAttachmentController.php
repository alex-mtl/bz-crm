<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Actions\SendMessages;
use App\Domain\Messaging\Models\MessageAttachment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Files of messages (ТЗ §68): served through the application to those who may read the chat, and only after the
 * antivirus let the file through — never by a public link.
 */
final class MessengerAttachmentController
{
    public function __invoke(Request $request, MessageAttachment $attachment, SendMessages $messages): BinaryFileResponse
    {
        $user = $request->user();
        assert($user instanceof User);
        $path = $messages->attachmentPath($user, $attachment);

        // Pictures, sound and video are shown in the chat itself; everything else is downloaded.
        return in_array($attachment->kind, ['image', 'audio', 'video'], true)
            ? response()->file($path, ['Content-Type' => (string) $attachment->mime, 'Cache-Control' => 'private, max-age=300'])
            : response()->download($path, $attachment->original_name);
    }
}
