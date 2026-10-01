<x-layouts.public :title="__('identity.two_factor_page.title')">
    <h1 class="mb-2 text-xl font-semibold">{{ __('identity.two_factor_page.title') }}</h1>
    <p class="mb-6 text-sm text-stone-600">{{ __('identity.two_factor_page.intro') }}</p>

    <form method="POST" action="{{ route('oauth.two-factor.verify') }}" class="space-y-4">
        @csrf
        <label class="block text-sm">
            <span class="mb-1 block font-medium">{{ __('identity.two_factor_page.code') }}</span>
            <input name="code" required autofocus autocomplete="one-time-code" class="w-full rounded border border-stone-300 px-3 py-2">
        </label>
        <button type="submit" class="w-full rounded bg-amber-500 px-3 py-2 font-medium text-white hover:bg-amber-600">
            {{ __('identity.two_factor_page.submit') }}
        </button>
    </form>
</x-layouts.public>
