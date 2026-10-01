<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Actions\SignInWithProvider;
use App\Domain\Identity\Contracts\OAuthGateway;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\AuthProvider;
use App\Domain\Identity\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Sign-in / registration / connecting a provider through OAuth (ФО §6.1, ADR-007).
 * A user with two-factor authentication must pass it here too: the provider replaces
 * only the password, not the second factor.
 */
final class OAuthController
{
    private const string PENDING_KEY = 'oauth.pending_user';

    public function redirect(OAuthGateway $gateway, string $provider): SymfonyRedirect
    {
        return $gateway->redirect($this->provider($provider));
    }

    public function callback(Request $request, OAuthGateway $gateway, SignInWithProvider $signIn, string $provider): RedirectResponse
    {
        $authProvider = $this->provider($provider);
        $current = $request->user();

        try {
            $identity = $gateway->identity($authProvider);
            $user = $signIn($identity, $current instanceof User ? $current : null);
        } catch (IdentityRuleViolation $exception) {
            return $this->fail($current !== null, $exception->getMessage());
        } catch (Throwable $exception) {
            Log::warning('OAuth callback failed', ['provider' => $provider, 'error' => $exception->getMessage()]);

            return $this->fail($current !== null, __('identity.errors.provider_failed'));
        }

        if ($current !== null) {
            return redirect()->route('filament.admin.auth.profile')->with('status', __('identity.profile.connected'));
        }
        if ($user->status === UserStatus::Deactivated) {
            return $this->fail(false, __('identity.errors.account_deactivated'));
        }
        if ($user->hasAppAuthentication()) {
            $request->session()->put(self::PENDING_KEY, $user->id);

            return redirect()->route('oauth.two-factor');
        }

        return $this->complete($request, $user);
    }

    public function twoFactorForm(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has(self::PENDING_KEY)) {
            return redirect()->route('filament.admin.auth.login');
        }

        return view('auth.oauth-two-factor');
    }

    public function twoFactor(Request $request, AppAuthentication $mfa): RedirectResponse
    {
        $user = User::query()->find($request->session()->get(self::PENDING_KEY));
        if ($user === null || $user->status === UserStatus::Deactivated) {
            $request->session()->forget(self::PENDING_KEY);

            return redirect()->route('filament.admin.auth.login');
        }

        $code = (string) $request->input('code', '');
        $secret = (string) $user->getAppAuthenticationSecret();
        $valid = $code !== '' && ($mfa->verifyCode($code, $secret, shouldPreventCodeReuse: true)
            || ($user->getAppAuthenticationRecoveryCodes() !== null && $user->getAppAuthenticationRecoveryCodes() !== [] && $mfa->verifyRecoveryCode($code, $user)));

        if (! $valid) {
            return back()->withErrors(['code' => __('identity.errors.two_factor_invalid')]);
        }

        $request->session()->forget(self::PENDING_KEY);

        return $this->complete($request, $user);
    }

    private function complete(Request $request, User $user): RedirectResponse
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect('/admin');
    }

    private function provider(string $code): AuthProvider
    {
        return AuthProvider::query()->usable()->where('code', $code)->firstOrFail();
    }

    private function fail(bool $signedIn, string $message): RedirectResponse
    {
        return $signedIn
            ? redirect()->route('filament.admin.auth.profile')->withErrors(['oauth' => $message])
            : redirect()->route('filament.admin.auth.login')->withErrors(['oauth' => $message]);
    }
}
