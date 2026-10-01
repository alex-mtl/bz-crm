<div class="flex flex-col gap-3" data-test="task-discussion">
    <div class="flex max-h-96 flex-col gap-2 overflow-y-auto">
        @forelse ($messages as $message)
            <div class="rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/5">
                <div class="mb-1 text-xs text-gray-500">{{ $message->author->fullName() }} · {{ $message->created_at->isoFormat('LLL') }}</div>
                <div class="whitespace-pre-line">{{ $message->body }}</div>
            </div>
        @empty
            <p class="text-sm text-gray-500">{{ __('admin.tasks.no_messages') }}</p>
        @endforelse
    </div>
    <form wire:submit="post" class="flex flex-col gap-2">
        <textarea wire:model="body" rows="2" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"
                  placeholder="{{ __('admin.tasks.write_message') }}"></textarea>
        @if ($error)
            <p class="text-sm text-danger-600">{{ $error }}</p>
        @endif
        <div>
            <x-filament::button type="submit" size="sm">{{ __('admin.tasks.send') }}</x-filament::button>
        </div>
    </form>
</div>
