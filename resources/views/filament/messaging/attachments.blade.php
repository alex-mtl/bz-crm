@if ($attachments->isNotEmpty())
    <div class="mt-1 flex flex-wrap gap-2" data-test="attachments">
        @foreach ($attachments as $attachment)
            @if ($attachment->scan_status === 'pending')
                <span class="rounded border border-gray-200 px-2 py-0.5 text-xs text-gray-500 dark:border-white/10">⏳ {{ $attachment->original_name }} — {{ __('messaging.ui.scan.pending') }}</span>
            @elseif ($attachment->scan_status === 'infected')
                <span class="rounded border border-danger-300 px-2 py-0.5 text-xs text-danger-600">⚠ {{ $attachment->original_name }} — {{ __('messaging.ui.scan.infected') }}</span>
            @elseif ($attachment->kind === 'image')
                <a href="{{ route('messenger.attachment', $attachment) }}" target="_blank">
                    <img src="{{ route('messenger.attachment', $attachment) }}" alt="{{ $attachment->original_name }}" class="max-h-40 rounded-lg" loading="lazy" />
                </a>
            @elseif ($attachment->kind === 'audio')
                <audio controls preload="none" src="{{ route('messenger.attachment', $attachment) }}" title="{{ $attachment->original_name }}"></audio>
            @elseif ($attachment->kind === 'video')
                <video controls preload="none" class="max-h-48 rounded-lg" src="{{ route('messenger.attachment', $attachment) }}" title="{{ $attachment->original_name }}"></video>
            @else
                <a href="{{ route('messenger.attachment', $attachment) }}" class="rounded border border-gray-200 px-2 py-0.5 text-xs hover:bg-white dark:border-white/10">📎 {{ $attachment->original_name }}</a>
            @endif
        @endforeach
    </div>
@endif
