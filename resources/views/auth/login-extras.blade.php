@include('auth.provider-buttons')

{{-- Д-5: quick sign-in as a demo persona. Not rendered at all in production. --}}
@if (\App\Domain\Identity\Actions\PrepareDemoSignIn::available())
    @php($personas = \App\Domain\Identity\Actions\PrepareDemoSignIn::personas())
    @if ($personas->isNotEmpty())
        <details class="rounded-lg border border-dashed border-gray-300 p-3 text-sm dark:border-white/20" data-test="demo-sign-in">
            <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-200">{{ __('identity.demo.title') }} ({{ $personas->count() }})</summary>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('identity.demo.hint') }}</p>
            <ul class="mt-2 flex max-h-80 flex-col gap-1 overflow-y-auto">
                @foreach ($personas as $persona)
                    <li>
                        <form method="POST" action="{{ route('demo.sign-in', $persona) }}">
                            @csrf
                            <button type="submit" class="flex w-full items-center justify-between rounded px-2 py-1 text-left hover:bg-gray-100 dark:hover:bg-white/5">
                                <span>{{ $persona->person->fullName() }}</span>
                                <span class="text-xs text-gray-500">{{ $persona->isActive()
                                    ? implode(', ', \App\Filament\Resources\Users\UserResource::roleNamesOf($persona)) ?: '—'
                                    : $persona->status->label() }}</span>
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
@endif
