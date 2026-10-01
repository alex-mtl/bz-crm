<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Announcements;
use App\Domain\Notifications\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "I have read it" under a critical notice (ФО §6.13) — pressed on the bar shown on every page.
 */
final class AnnouncementController
{
    public function acknowledge(Request $request, Announcement $announcement, Announcements $announcements): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);
        $announcements->acknowledge($user, $announcement);

        return back();
    }
}
