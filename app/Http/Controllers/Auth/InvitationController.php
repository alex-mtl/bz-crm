<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Access\Admission\AcceptInvitation;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * The invited person sets up their account from the link in the e-mail (ФО §6.1).
 */
final class InvitationController
{
    public function show(AcceptInvitation $accept, string $token): View
    {
        try {
            $invitation = $accept->findUsable($token);
        } catch (IdentityRuleViolation $exception) {
            return view('invitation.unusable', ['message' => $exception->getMessage()]);
        }

        return view('invitation.accept', ['invitation' => $invitation, 'token' => $token]);
    }

    public function accept(Request $request, AcceptInvitation $accept, string $token): RedirectResponse|View
    {
        /** @var array{first_name: string, last_name: ?string, password: string} $data */
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'confirmed', Password::default()],
        ]);

        try {
            $user = $accept($token, $data['first_name'], $data['last_name'] ?? null, $data['password'], app()->getLocale());
        } catch (IdentityRuleViolation $exception) {
            return view('invitation.unusable', ['message' => $exception->getMessage()]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect('/admin');
    }
}
