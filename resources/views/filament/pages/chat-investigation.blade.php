<x-filament-panels::page>
    @php($m = fn (string $key): string => __('messaging.ui.'.$key))

    <div class="rounded-lg bg-warning-50 px-4 py-2 text-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">{{ $m('investigation_hint') }}</div>

    <div>{{ $this->startAction }}</div>

    @if ($personId)
        <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10" data-test="investigation-chats">
            <h3 class="text-sm font-semibold">{{ \App\Filament\Support\PersonSearch::label($personId) }} — {{ $m('reason') }}: {{ $reason }}</h3>
            @forelse ($chats as $row)
                <button type="button" class="mt-1 block w-full rounded-lg px-2 py-1 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5" wire:click="read({{ $row['id'] }})" wire:key="inv-{{ $row['id'] }}">
                    {{ $row['title'] }} <span class="text-xs text-gray-500">· {{ $m('types.'.$row['type']) }}</span>
                </button>
            @empty
                <p class="mt-2 text-sm text-gray-500">{{ $m('no_chats') }}</p>
            @endforelse
        </section>
    @endif

    @if ($openChatId)
        <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10" data-test="investigation-transcript">
            @foreach ($this->transcript as $message)
                <div class="border-t border-gray-100 py-1 text-sm dark:border-white/5" style="margin-inline-start: {{ min($message->depth, 8) * 1.25 }}rem">
                    <span class="text-xs text-gray-500">{{ $message->author->fullName() }} · {{ $message->created_at->isoFormat('LLL') }}@if ($message->isDeleted()) · {{ $m('deleted') }}@endif</span>
                    <div class="whitespace-pre-line">{{ $message->body }}</div>
                </div>
            @endforeach
        </section>
    @endif
</x-filament-panels::page>
