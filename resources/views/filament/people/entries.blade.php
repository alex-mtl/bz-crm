{{-- Entries of a confidential layer. The page already applied the access rules and journaled the view. --}}
<div class="flex flex-col gap-4" data-test="layer-entries">
    @forelse ($entries as $entry)
        <article class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            @if (! empty($entry['meta']))
                <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">{{ $entry['meta'] }}</p>
            @endif
            <dl class="grid gap-1 text-sm">
                @foreach ($entry['lines'] as $label => $value)
                    @if (filled($value))
                        <div class="grid grid-cols-3 gap-2">
                            <dt class="font-medium text-gray-600 dark:text-gray-300">{{ $label }}</dt>
                            <dd class="col-span-2 whitespace-pre-line">{{ $value }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
        </article>
    @empty
        <p class="text-sm text-gray-500">{{ __('admin.people.layer_empty') }}</p>
    @endforelse
</div>
