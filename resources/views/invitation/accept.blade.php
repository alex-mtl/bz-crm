<x-layouts.public :title="__('identity.invitation_page.title')">
    <h1 class="mb-2 text-xl font-semibold">{{ __('identity.invitation_page.title') }}</h1>
    <p class="mb-6 text-sm text-stone-600">{{ __('identity.invitation_page.intro', ['email' => $invitation->email]) }}</p>

    <form method="POST" action="{{ route('invitation.store', $token) }}" class="space-y-4">
        @csrf
        <label class="block text-sm">
            <span class="mb-1 block font-medium">{{ __('identity.fields.first_name') }}</span>
            <input name="first_name" value="{{ old('first_name', $invitation->first_name) }}" required maxlength="100"
                   class="w-full rounded border border-stone-300 px-3 py-2">
        </label>
        <label class="block text-sm">
            <span class="mb-1 block font-medium">{{ __('identity.fields.last_name') }}</span>
            <input name="last_name" value="{{ old('last_name', $invitation->last_name) }}" maxlength="100"
                   class="w-full rounded border border-stone-300 px-3 py-2">
        </label>
        <label class="block text-sm">
            <span class="mb-1 block font-medium">{{ __('identity.fields.password') }}</span>
            <input type="password" name="password" required autocomplete="new-password"
                   class="w-full rounded border border-stone-300 px-3 py-2">
        </label>
        <label class="block text-sm">
            <span class="mb-1 block font-medium">{{ __('identity.fields.password_confirmation') }}</span>
            <input type="password" name="password_confirmation" required autocomplete="new-password"
                   class="w-full rounded border border-stone-300 px-3 py-2">
        </label>
        <button type="submit" class="w-full rounded bg-amber-500 px-3 py-2 font-medium text-white hover:bg-amber-600">
            {{ __('identity.invitation_page.submit') }}
        </button>
    </form>
</x-layouts.public>
