<?php

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Register;
use App\Http\Middleware\ApplyImpersonation;
use App\Http\Middleware\EnsureSessionEpoch;
use App\Http\Middleware\RedirectApplicantsToStatusPage;
use App\Http\Middleware\SetLocale;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->registration(Register::class)
            ->passwordReset()
            ->profile(EditProfile::class, isSimple: false)
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ])
            // The organization's logo with its name (from the bunaziua website); larger on the sign-in pages.
            ->brandName(fn (): string => (string) config('app.brand'))
            ->brandLogo('/brand/logo.png')
            ->darkModeBrandLogo('/brand/logo-dark.png')
            ->brandLogoHeight(fn (): string => request()->routeIs('filament.admin.auth.*') ? '4rem' : '2.25rem')
            ->favicon('/brand/icon-32.png')
            // Installable as an application (PWA): manifest + iOS tags in the head, the install prompt at the end.
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): View => view('filament.pwa-head'))
            ->renderHook(PanelsRenderHook::BODY_END, fn (): View => view('filament.pwa-install'))
            ->colors([
                'primary' => Color::Amber,
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->renderHook(PanelsRenderHook::BODY_START, fn (): View => view('filament.impersonation-banner'))
            ->renderHook(PanelsRenderHook::BODY_START, fn (): View => view('filament.critical-banner'))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn (): View => view('auth.login-extras'))
            ->renderHook(PanelsRenderHook::AUTH_REGISTER_FORM_AFTER, fn (): View => view('auth.provider-buttons'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetLocale::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Order: signed in → session not terminated (ADR-007) → applicants see only their status (ФО §6.1).
            ->authMiddleware([
                Authenticate::class,
                EnsureSessionEpoch::class,
                ApplyImpersonation::class,
                RedirectApplicantsToStatusPage::class,
            ], isPersistent: true);
    }
}
