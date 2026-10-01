<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Social\Actions\ManagePosts;
use App\Domain\Social\Models\PostAttachment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Files attached to posts (ТЗ §68): served through the application, to those who see the post — never by a public link.
 */
final class SocialAttachmentController
{
    public function __invoke(Request $request, PostAttachment $attachment, ManagePosts $posts): BinaryFileResponse
    {
        $user = $request->user();
        assert($user instanceof User);
        $path = $posts->attachmentPath($user, $attachment);

        return $attachment->kind === 'image'
            ? response()->file($path, ['Content-Type' => (string) $attachment->mime, 'Cache-Control' => 'private, max-age=300'])
            : response()->download($path, $attachment->original_name);
    }
}
