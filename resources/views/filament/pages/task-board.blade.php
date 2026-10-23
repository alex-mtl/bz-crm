<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-3">
        <x-filament::input.wrapper class="w-72">
            <x-filament::input.select wire:model.live="projectId">
                <option value="">{{ __('admin.views.all_projects') }}</option>
                @foreach ($this->projects as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <label class="flex items-center gap-2 text-sm">
            <x-filament::input.checkbox wire:model.live="onlyMine" /> {{ __('admin.tasks.tabs.mine') }}
        </label>
    </div>

    @php($columns = $this->columns)
    {{-- The board scrolls sideways from a bar above it as well as below; it fits the screen, so the lower bar is always in sight
         and each column scrolls on its own. --}}
    <div x-data="{
            width: 0,
            init() {
                const measure = () => this.width = this.$refs.board.scrollWidth;
                new ResizeObserver(measure).observe(this.$refs.board);
                new MutationObserver(measure).observe(this.$refs.board, { childList: true, subtree: true });
                measure();
            },
        }">
        <div class="bz-board-top" x-ref="top" x-on:scroll="$refs.board.scrollLeft = $refs.top.scrollLeft">
            <div x-bind:style="{ width: width + 'px' }" style="height: 1px"></div>
        </div>
        <div class="bz-board flex gap-3 overflow-x-auto pb-2" x-ref="board" x-on:scroll="$refs.top.scrollLeft = $refs.board.scrollLeft" data-test="task-board">
            @foreach ($columns as $code => $column)
                <section class="bz-column flex w-64 shrink-0 flex-col gap-2 overflow-y-auto rounded-xl p-2" style="--bz-c: {{ $column['color'] }}" data-status="{{ $code }}">
                    <h3 class="bz-column-head flex items-center justify-between px-1 text-sm font-semibold">
                        <span class="flex items-center gap-2"><span class="bz-status-dot"></span>{{ $column['label'] }}</span>
                        <span class="rounded-full bg-white px-2 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $column['tasks']->count() }}</span>
                    </h3>
                    @foreach ($column['tasks'] as $task)
                        <article class="bz-card rounded-lg bg-white p-2 text-sm shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                            <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}" class="font-medium hover:underline">{{ $task->title }}</a>
                            <div class="mt-1 text-xs text-gray-500">
                                {{ $task->assignees->map->fullName()->implode(', ') ?: '—' }}
                            </div>
                            @if ($task->due_at)
                                <div @class(['mt-1 text-xs', 'font-semibold text-danger-600' => $task->isOverdue(), 'text-gray-500' => ! $task->isOverdue()])>
                                    {{ $task->due_at->isoFormat('L LT') }}@if ($task->isOverdue()) · {{ __('admin.tasks.overdue') }}@endif
                                </div>
                            @endif
                            @php($next = $this->nextStatuses($task))
                            @if ($next !== [])
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @foreach ($next as $to)
                                        <button type="button" wire:click="mountAction('move', { task: {{ $task->id }}, to: '{{ $to }}' })"
                                                class="bz-move rounded px-1.5 py-0.5 text-xs" style="--bz-c: {{ $columns[$to]['color'] ?? '#9ca3af' }}">
                                            → {{ $columns[$to]['label'] ?? $to }}
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </article>
                    @endforeach
                </section>
            @endforeach
        </div>
    </div>

</x-filament-panels::page>
