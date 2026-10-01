<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Actions\PrepareDemoSignIn;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class DemoSignInController
{
    public function __invoke(Request $request, PrepareDemoSignIn $prepare, User $user): RedirectResponse
    {
        abort_unless(PrepareDemoSignIn::available(), 404);

        try {
            $prepare($user);
        } catch (IdentityRuleViolation $exception) {
            return redirect()->route('filament.admin.auth.login')->withErrors(['oauth' => $exception->getMessage()]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect('/admin');
    }
}
