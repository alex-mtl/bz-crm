<div class="flex max-h-96 flex-col gap-1 overflow-y-auto text-sm" data-test="receipts">
    @foreach ($receipts as $receipt)
        <div class="flex items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-1.5 dark:bg-white/5">
            <span>{{ $receipt->user->person->fullName() }}</span>
            @if ($receipt->acknowledged_at)
                <span class="text-xs text-success-600">{{ $receipt->acknowledged_at->isoFormat('LLL') }}</span>
            @else
                <span class="text-xs text-danger-600">{{ __('notifications.ui.not_acknowledged') }}</span>
            @endif
        </div>
    @endforeach
</div>
