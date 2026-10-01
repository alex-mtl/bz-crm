@php
    $providers = \App\Domain\Identity\Models\AuthProvider::query()->usable()->get();
    $oauthError = session('errors')?->first('oauth');
@endphp

@if ($oauthError)
    <p class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-700 dark:bg-danger-500/10 dark:text-danger-400" role="alert" data-test="oauth-error">{{ $oauthError }}</p>
@endif

<nav class="flex justify-center gap-3 text-xs" aria-label="{{ __('identity.fields.locale') }}" data-test="locale-switch">
    @foreach (config('app.supported_locales') as $code)
        <a href="{{ route('locale.switch', $code) }}" hreflang="{{ $code }}"
           @class(['font-semibold text-primary-600 dark:text-primary-400' => app()->getLocale() === $code, 'text-gray-500 hover:text-gray-700 dark:text-gray-400' => app()->getLocale() !== $code])>
            {{ __('identity.locales.'.$code) }}
        </a>
    @endforeach
</nav>

@if ($providers->isNotEmpty())
    <div class="flex flex-col gap-2" data-test="oauth-providers">
        <p class="text-center text-sm text-gray-500 dark:text-gray-400">{{ __('identity.login.or_continue_with') }}</p>
        @foreach ($providers as $provider)
            <x-filament::button tag="a" color="gray" outlined :href="route('oauth.redirect', $provider->code)">
                {{ $provider->display_name }}
            </x-filament::button>
        @endforeach
    </div>
@endif
