<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-3">
        <x-filament::input.wrapper class="w-80">
            <x-filament::input.select wire:model.live="pipelineId">
                @foreach ($this->pipelines as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <label class="flex items-center gap-2 text-sm">
            <x-filament::input.checkbox wire:model.live="onlyMine" /> {{ __('admin.leads.mine') }}
        </label>
        @if (\App\Filament\Resources\Leads\LeadResource::canCreate() && $this->pipelineId)
            <x-filament::button tag="a" size="sm" icon="heroicon-o-plus"
                                href="{{ \App\Filament\Resources\Leads\LeadResource::getUrl('create', ['pipeline' => $this->pipelineId]) }}">
                {{ __('admin.leads.new') }}
            </x-filament::button>
        @endif
    </div>

    @if ($this->columns === [])
        <p class="text-sm text-gray-500">{{ __('admin.leads.no_pipelines') }}</p>
    @endif

    <div class="flex gap-3 overflow-x-auto pb-4" data-test="lead-board">
        @foreach ($this->columns as $column)
            @php($stage = $column['stage'])
            <section @class([
                'flex w-64 shrink-0 flex-col gap-2 rounded-xl p-2',
                'bg-gray-100 dark:bg-white/5' => $stage->kind === 'open',
                'bg-success-50 dark:bg-success-500/10' => $stage->kind === 'won',
                'bg-danger-50 dark:bg-danger-500/10' => $stage->kind === 'lost',
            ])>
                <h3 class="flex items-center justify-between px-1 text-sm font-semibold">
                    {{ $stage->name() }}
                    <span class="rounded-full bg-white px-2 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300" data-test="stage-count">{{ $column['count'] }}</span>
                </h3>
                @foreach ($column['leads'] as $lead)
                    <article class="rounded-lg bg-white p-2 text-sm shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                        <a href="{{ \App\Filament\Resources\Leads\LeadResource::getUrl('view', ['record' => $lead]) }}" class="font-medium hover:underline">
                            {{ $lead->person->fullName() }}
                        </a>
                        @if ($lead->title)
                            <div class="text-xs text-gray-600 dark:text-gray-300">{{ $lead->title }}</div>
                        @endif
                        <div class="mt-1 text-xs text-gray-500">{{ $lead->responsible?->fullName() ?? '—' }}</div>
                        @if ($lead->isStageOverdue())
                            <div class="mt-1 text-xs font-semibold text-danger-600">{{ __('admin.leads.stage_overdue') }}</div>
                        @endif
                        @if ($lead->status === 'frozen')
                            <div class="mt-1 text-xs font-semibold text-warning-600">
                                {{ __('crm.lead_statuses.frozen') }} · {{ $lead->frozen_until?->isoFormat('L') }}
                            </div>
                        @endif
                        @if ($this->mayMove($lead))
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach ($this->columns as $target)
                                    @continue($target['stage']->id === $stage->id)
                                    <button type="button" wire:click="mountAction('move', { lead: {{ $lead->id }}, stage: {{ $target['stage']->id }} })"
                                            class="rounded border border-gray-200 px-1.5 py-0.5 text-xs hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5">
                                        → {{ $target['stage']->name() }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </article>
                @endforeach
                @if ($column['count'] > $column['leads']->count())
                    <p class="px-1 text-xs text-gray-500">{{ __('admin.leads.more', ['count' => $column['count'] - $column['leads']->count()]) }}</p>
                @endif
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
