<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Actions\ConfirmEmail;
use App\Domain\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Confirmation of e-mail ownership for e-mail registration (ФО §6.1). Uses Laravel's standard
 * route names, so the framework's VerifyEmail notification links here.
 */
final class EmailVerificationController
{
    public function verify(Request $request, ConfirmEmail $confirm, string $id, string $hash): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        abort_unless(hash_equals((string) $user->getKey(), $id), 403);
        abort_unless($user->email !== null && hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        $confirm($user);

        return redirect()->route('account.status')->with('status', __('identity.status_page.email_confirmed'));
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('status', __('identity.status_page.verification_sent'));
    }
}
