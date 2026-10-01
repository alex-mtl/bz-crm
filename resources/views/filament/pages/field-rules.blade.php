<x-filament-panels::page>
    <x-filament::section :description="__('admin.field_rules.hint')">
        <div class="overflow-x-auto">
            <table class="w-full text-sm" data-test="field-rules">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="py-2 pr-4">{{ __('admin.fields.role') }}</th>
                        @foreach ($this->groups as $label)
                            <th class="px-2 py-2 text-center font-medium">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->roles as $roleId => $roleName)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-4 font-medium">{{ $roleName }}</td>
                            @foreach ($this->groups as $key => $label)
                                <td class="px-2 py-2 text-center">
                                    <x-filament::input.checkbox wire:model="matrix.{{ $roleId }}.{{ $key }}" aria-label="{{ $roleName }} — {{ $label }}" />
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            <x-filament::button wire:click="save">{{ __('admin.save') }}</x-filament::button>
        </div>
    </x-filament::section>
</x-filament-panels::page>
