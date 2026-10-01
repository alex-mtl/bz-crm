<x-layouts.public :title="__('identity.invitation_page.title')">
    <h1 class="mb-2 text-xl font-semibold">{{ __('identity.invitation_page.title') }}</h1>
    <p class="mb-6 text-sm" data-test="invitation-unusable">{{ $message }}</p>
    <a href="{{ route('filament.admin.auth.login') }}" class="text-sm font-medium text-amber-700 underline">{{ __('identity.invitation_page.to_login') }}</a>
</x-layouts.public>
