<x-filament-panels::page>
    @if ($this->may('system.settings.manage'))
        <x-filament::section :heading="__('profiles.settings.notes360_scope')">
            <div class="flex flex-wrap items-end gap-3">
                <x-filament::input.wrapper class="w-80">
                    <x-filament::input.select wire:model="notes360Scope">
                        <option value="own_unit">{{ __('profiles.settings.own_unit') }}</option>
                        <option value="any">{{ __('profiles.settings.any') }}</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <x-filament::button wire:click="saveNotesScope">{{ __('admin.save') }}</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    @if ($this->may('tasks.workflow.manage'))
        <x-filament::section :heading="__('admin.work_settings.escalation')" :description="__('admin.work_settings.escalation_hint')">
            <div class="flex items-end gap-3">
                <x-filament::input.wrapper class="w-32">
                    <x-filament::input type="number" min="1" max="60" wire:model="escalationDays" />
                </x-filament::input.wrapper>
                <x-filament::button wire:click="saveEscalation">{{ __('admin.save') }}</x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('admin.work_settings.transitions')" :description="__('admin.work_settings.transitions_hint')">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500">
                    <th class="py-1">{{ __('admin.work_settings.from') }}</th><th>{{ __('admin.work_settings.to') }}</th>
                    <th>{{ __('admin.work_settings.requires') }}</th><th></th>
                </tr></thead>
                <tbody>
                    @foreach ($this->transitions as $t)
                        <tr @class(['border-t border-gray-100 dark:border-white/5', 'opacity-50' => ! $t->is_active])>
                            <td class="py-1">{{ $this->statuses[$t->from_status] ?? $t->from_status }}</td>
                            <td>{{ $this->statuses[$t->to_status] ?? $t->to_status }}</td>
                            <td>{{ __('admin.tasks.requires.'.$t->requires) }}</td>
                            <td class="text-right">
                                <x-filament::link tag="button" wire:click="toggle({{ $t->id }})">
                                    {{ $t->is_active ? __('admin.catalogs.deactivate') : __('admin.catalogs.reactivate') }}
                                </x-filament::link>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-4 flex flex-wrap items-end gap-2">
                @foreach (['newFrom' => 'from', 'newTo' => 'to'] as $model => $label)
                    <x-filament::input.wrapper class="w-48">
                        <x-filament::input.select wire:model="{{ $model }}">
                            <option value="">{{ __('admin.work_settings.'.$label) }}</option>
                            @foreach ($this->statuses as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @endforeach
                <x-filament::input.wrapper class="w-48">
                    <x-filament::input.select wire:model="newRequires">
                        @foreach (\App\Domain\Tasks\Actions\ManageWorkflow::REQUIRES as $r)<option value="{{ $r }}">{{ __('admin.tasks.requires.'.$r) }}</option>@endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <x-filament::button wire:click="addTransition">{{ __('admin.work_settings.add') }}</x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
