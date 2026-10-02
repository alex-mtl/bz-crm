<x-filament-panels::page>
    @php
        $g = fn (string $key, array $replace = []): string => __('geo.ui.'.$key, $replace);
        $box = 'rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10';
        $smallButton = 'rounded border border-gray-200 px-2 py-0.5 text-xs hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5';
        // Colours of the statuses catalog → classes the theme knows.
        $tone = fn (?string $color): string => match ($color) {
            'amber' => 'bg-amber-500 text-white', 'blue' => 'bg-blue-500 text-white', 'green' => 'bg-green-600 text-white',
            'violet' => 'bg-violet-500 text-white', 'red' => 'bg-red-600 text-white', 'slate' => 'bg-slate-600 text-white',
            default => 'bg-gray-300 text-gray-800',
        };
        $statusName = fn (string $code): string => $statuses->get($code)?->name() ?? $code;
    @endphp

    @if ($house->isArchived())
        <div class="rounded-lg bg-gray-100 px-4 py-2 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-300" data-test="house-archived">{{ $g('archived') }}</div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="{{ $box }} lg:col-span-2" data-test="house-about">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <x-filament::badge color="info">{{ $type }}</x-filament::badge>
                <x-filament::badge color="gray">{{ $house->territory->name() }}</x-filament::badge>
            </div>
            <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                @foreach (['entrances' => $house->entrances, 'floors' => $house->floors, 'residents' => $house->residents_count] as $label => $value)
                    @if ($value !== null)
                        <div><dt class="inline text-gray-500">{{ $g($label) }}:</dt> <dd class="inline">{{ $value }}</dd></div>
                    @endif
                @endforeach
                <div><dt class="inline text-gray-500">{{ $g('apartments') }}:</dt> <dd class="inline">{{ $figures['apartments'] }}</dd></div>
            </dl>
            @if ($house->description)
                <p class="mt-3 whitespace-pre-line text-sm">{{ $house->description }}</p>
            @endif

            <h3 class="mt-4 text-sm font-semibold">{{ $g('agitators') }}</h3>
            <ul class="mt-1 space-y-1 text-sm" data-test="house-assignments">
                @forelse ($assignments as $assignment)
                    <li class="flex flex-wrap items-center gap-2">
                        <span>{{ $assignment->person->fullName() }}</span>
                        @if ($assignment->territory_id !== null)
                            <x-filament::badge color="gray" size="sm">{{ $g('by_territory', ['name' => $assignment->territory->name()]) }}</x-filament::badge>
                        @endif
                        <span class="text-xs text-gray-500">{{ $assignment->assigned_at->isoFormat('LL') }}</span>
                        @if ($mayAssign)
                            <button type="button" class="{{ $smallButton }}" wire:click="mountAction('endAssignment', { assignment: {{ $assignment->id }} })">{{ $g('end_assignment') }}</button>
                        @endif
                    </li>
                @empty
                    <li class="text-gray-500">{{ $g('no_agitators') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- ФО §6.11: «Сводка по дому: % обойдённых квартир, % сторонников». --}}
        <section class="{{ $box }}" data-test="house-summary">
            <h3 class="text-sm font-semibold">{{ $g('summary') }}</h3>
            <dl class="mt-2 space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-gray-500">{{ $g('visited') }}</dt><dd data-test="visited-pct">{{ $figures['visited'] }} / {{ $figures['apartments'] }} · {{ $figures['visited_pct'] }}%</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">{{ $g('supporters') }}</dt><dd data-test="supporter-pct">{{ $figures['supporters'] }} · {{ $figures['supporter_pct'] }}%</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">{{ $g('contacts') }}</dt><dd>{{ $figures['contacts'] }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">{{ $g('attempts') }}</dt><dd>{{ $figures['attempts'] }}</dd></div>
            </dl>
            <div class="mt-3 flex flex-wrap gap-1 text-xs">
                @foreach ($figures['by_status'] as $code => $count)
                    <span class="rounded px-2 py-0.5 {{ $tone($statuses->get($code)?->property('color')) }}">{{ $statusName($code) }}: {{ $count }}</span>
                @endforeach
            </div>
        </section>
    </div>

    @if ($map !== null)
        <div wire:ignore>
            <div class="bz-map bz-map-small" data-bz-map data-config='@json($map)'></div>
        </div>
        @vite('resources/js/field-map.js')
    @endif

    <section class="{{ $box }}" data-test="house-apartments">
        <h3 class="text-sm font-semibold">{{ $g('apartments') }}</h3>
        @forelse ($entrances as $entrance => $apartments)
            @if ($entrance > 0)
                <h4 class="mt-3 text-xs font-semibold uppercase text-gray-500">{{ $g('entrance_n', ['number' => $entrance]) }}</h4>
            @endif
            <div class="mt-2 grid grid-cols-4 gap-2 sm:grid-cols-8 lg:grid-cols-12">
                @foreach ($apartments as $apartment)
                    @php
                        $title = $statusName($apartment->status_code)
                            .($apartment->attempts > 0 ? ' · '.$g('attempts_n', ['count' => $apartment->attempts]) : '')
                            .($apartment->last_visit_at ? ' · '.$apartment->last_visit_at->isoFormat('L').' '.$apartment->lastVisitor?->fullName() : '')
                            .($apartment->next_visit_on ? ' · '.$g('return_on', ['date' => $apartment->next_visit_on->isoFormat('L')]) : '');
                    @endphp
                    <div class="relative">
                        <button type="button" title="{{ $title }}" data-test="apartment" data-number="{{ $apartment->number }}" data-status="{{ $apartment->status_code }}"
                            class="w-full rounded-lg px-1 py-2 text-center text-sm font-semibold {{ $tone($statuses->get($apartment->status_code)?->property('color')) }} {{ $mayVisit ? '' : 'cursor-default' }}"
                            @if ($mayVisit) wire:click="mountAction('visit', { apartment: {{ $apartment->id }} })" @endif>
                            {{ $apartment->number }}@if ($apartment->next_visit_on && $apartment->next_visit_on->lte(today())) !@endif
                        </button>
                        @if ($mayManage && $apartment->attempts === 0)
                            <button type="button" class="absolute -right-1 -top-1 rounded-full bg-white px-1 text-xs text-gray-500 ring-1 ring-gray-300" title="{{ $g('remove_apartment') }}"
                                wire:click="mountAction('removeApartment', { apartment: {{ $apartment->id }} })">×</button>
                        @endif
                    </div>
                @endforeach
            </div>
        @empty
            <p class="mt-2 text-sm text-gray-500">{{ $g('no_apartments') }}</p>
        @endforelse
        <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
            @foreach ($statuses as $status)
                <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm {{ $tone($status->property('color')) }}"></span>{{ $status->name() }}</span>
            @endforeach
        </div>
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        @if ($visits !== null)
            <section class="{{ $box }}" data-test="house-visits">
                <h3 class="text-sm font-semibold">{{ $g('visits') }}</h3>
                <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-white/5">
                    @forelse ($visits as $visit)
                        <li class="flex flex-wrap items-center gap-2 py-1.5">
                            <span class="w-28 text-xs text-gray-500">{{ $visit->visited_at->isoFormat('L LT') }}</span>
                            <span class="font-medium">{{ $g('apartment') }} {{ $visit->apartment->number }}</span>
                            <span class="rounded px-2 py-0.5 text-xs {{ $tone($statuses->get($visit->status_code)?->property('color')) }}">{{ $statusName($visit->status_code) }}</span>
                            <span class="text-gray-500">{{ $visit->person->fullName() }} · {{ $g('attempt_no', ['number' => $visit->attempt_no]) }}</span>
                            @if ($visit->source === 'offline')
                                <x-filament::badge color="gray" size="sm">{{ $g('offline') }}</x-filament::badge>
                            @endif
                            @if ($visit->task_id)
                                <a class="text-xs underline" href="/admin/tasks/{{ $visit->task_id }}">{{ $g('task') }}</a>
                            @endif
                        </li>
                    @empty
                        <li class="py-1.5 text-gray-500">{{ $g('no_visits') }}</li>
                    @endforelse
                </ul>
            </section>
        @endif

        {{-- Notes: the reader's own, and those written for the staff if the reader holds the right (FieldNotes). --}}
        <section class="{{ $box }}" data-test="house-notes">
            <h3 class="text-sm font-semibold">{{ $g('notes') }}</h3>
            <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-white/5">
                @forelse ($notes as $note)
                    <li class="py-1.5">
                        <div class="text-xs text-gray-500">
                            {{ $note->created_at->isoFormat('L LT') }} · {{ $g('apartment') }} {{ $note->apartment->number }} · {{ $note->author->fullName() }}
                            <x-filament::badge :color="$note->visibility === 'personal' ? 'warning' : 'gray'" size="sm">{{ $g('visibility_'.$note->visibility) }}</x-filament::badge>
                        </div>
                        <p class="whitespace-pre-line">{{ $note->body }}</p>
                    </li>
                @empty
                    <li class="py-1.5 text-gray-500">{{ $g('no_notes') }}</li>
                @endforelse
            </ul>
        </section>
    </div>

    @if ($appeals->isNotEmpty())
        <section class="{{ $box }}" data-test="house-appeals">
            <h3 class="text-sm font-semibold">{{ $g('appeals') }}</h3>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($appeals as $appeal)
                    <li class="flex flex-wrap items-center gap-2">
                        <a class="underline" href="/admin/appeals/{{ $appeal->id }}">{{ $appeal->number }} · {{ $appeal->title }}</a>
                        <button type="button" class="{{ $smallButton }}" wire:click="mountAction('unlinkAppeal', { appeal: {{ $appeal->id }} })">{{ $g('unlink') }}</button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-filament-panels::page>
