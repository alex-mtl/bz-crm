<div class="flex flex-col gap-3" data-test="revisions">
    @forelse ($revisions as $revision)
        <div class="rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/5">
            <div class="mb-1 text-xs text-gray-500">{{ __('social.ui.version_until', ['at' => $revision->created_at->isoFormat('LLL')]) }}</div>
            <div class="whitespace-pre-line">{{ $revision->body }}</div>
        </div>
    @empty
        <p class="text-sm text-gray-500">{{ __('social.ui.no_revisions') }}</p>
    @endforelse
</div>
