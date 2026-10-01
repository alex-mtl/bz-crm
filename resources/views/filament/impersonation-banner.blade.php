@php
    $impersonation = app(\App\Http\Impersonation\ImpersonationSession::class)->current(session()->driver());
@endphp
@if ($impersonation !== null)
    {{-- Д-19: always visible while acting through someone else's account. --}}
    <div class="sticky top-0 z-50 flex flex-wrap items-center justify-center gap-3 bg-danger-600 px-4 py-2 text-sm font-medium text-white">
        <span>
            {{ __('admin.impersonation.banner', [
                'target' => $impersonation->target->getFilamentName(),
                'impersonator' => $impersonation->impersonator->getFilamentName(),
                'minutes' => $impersonation->minutesLeft(),
            ]) }}
        </span>
        <form method="POST" action="{{ route('impersonation.leave') }}">
            @csrf
            <button type="submit" class="rounded-md bg-white px-3 py-1 text-danger-700 hover:bg-danger-50">
                {{ __('admin.impersonation.leave') }}
            </button>
        </form>
    </div>
@endif
