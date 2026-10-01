@php
    $viewer = \Filament\Facades\Filament::auth()->user();
    $pendingCritical = $viewer instanceof \App\Domain\Identity\Models\User
        ? app(\App\Domain\Notifications\Announcements::class)->pendingFor($viewer)->limit(3)->get()
        : collect();
@endphp
@foreach ($pendingCritical as $critical)
    {{-- ФО §6.13: a critical notice stays in front of the person until they confirm reading it. --}}
    <div class="flex flex-wrap items-center justify-center gap-3 bg-warning-500 px-4 py-2 text-sm text-black" data-test="critical-banner">
        <span><strong>{{ $critical->title }}</strong> — {{ str($critical->body)->limit(200) }}</span>
        <form method="POST" action="{{ route('announcements.acknowledge', $critical) }}">
            @csrf
            <button type="submit" class="rounded-md bg-white px-3 py-1 font-medium hover:bg-warning-50">
                {{ __('notifications.acknowledge') }}
            </button>
        </form>
    </div>
@endforeach
