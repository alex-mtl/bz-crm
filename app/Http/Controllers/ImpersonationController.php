<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Models\Impersonation;
use App\Http\Impersonation\ImpersonationSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Return to my account" from the impersonation banner (Д-19).
 */
final class ImpersonationController
{
    public function leave(Request $request, ImpersonationSession $session): RedirectResponse
    {
        $targetId = $session->current($request->session())?->target_user_id;
        $back = $session->leave($request->session(), Impersonation::END_STOPPED);

        if ($back === null) {
            return redirect()->route('filament.admin.auth.login');
        }

        return redirect()->to($targetId !== null ? '/admin/users/'.$targetId : '/admin');
    }
}
