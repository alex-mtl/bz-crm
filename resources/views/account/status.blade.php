<x-layouts.public :title="__('identity.status_page.title')">
    <h1 class="mb-2 text-xl font-semibold">{{ __('identity.status_page.title') }}</h1>
    <p class="mb-4 text-sm text-stone-600">{{ __('identity.status_page.greeting', ['name' => $user->person->fullName()]) }}</p>

    @if (! $user->hasVerifiedEmail())
        <section class="mb-4 rounded border border-amber-300 bg-amber-50 p-4 text-sm" data-test="email-unverified">
            <p class="mb-3">{{ __('identity.status_page.confirm_email', ['email' => $user->email]) }}</p>
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button type="submit" class="rounded bg-amber-500 px-3 py-1.5 font-medium text-white hover:bg-amber-600">
                    {{ __('identity.status_page.resend') }}
                </button>
            </form>
        </section>
    @endif

    @if ($user->status === \App\Domain\Identity\Enums\UserStatus::Rejected)
        <section class="mb-4 rounded border border-red-200 bg-red-50 p-4 text-sm" data-test="application-rejected">
            <p class="font-medium">{{ __('identity.status_page.rejected') }}</p>
            @if ($application?->rejection_reason)
                <p class="mt-2">{{ __('identity.mail.reason', ['reason' => $application->rejection_reason]) }}</p>
            @endif
        </section>
    @elseif ($user->status === \App\Domain\Identity\Enums\UserStatus::PendingApproval)
        <section class="mb-4 rounded border border-stone-200 bg-stone-50 p-4 text-sm" data-test="application-pending">
            <p class="font-medium">{{ __('identity.status_page.pending') }}</p>
            @if ($application)
                <p class="mt-2 text-stone-600">{{ __('identity.status_page.submitted_at', ['date' => $application->submitted_at->isoFormat('LL')]) }}</p>
            @endif
        </section>
    @endif

    <p class="mb-6 text-sm">
        {{ __('identity.status_page.party_site') }}
        <a href="{{ $partySiteUrl }}" class="font-medium text-amber-700 underline" rel="noopener" target="_blank">{{ $partySiteUrl }}</a>
    </p>

    <form method="POST" action="{{ route('account.logout') }}">
        @csrf
        <button type="submit" class="text-sm text-stone-600 underline hover:text-stone-900">{{ __('identity.status_page.logout') }}</button>
    </form>
</x-layouts.public>
