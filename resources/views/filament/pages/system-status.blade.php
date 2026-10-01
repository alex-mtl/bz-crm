<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ __('system_status.checks') }}</x-slot>

        <x-slot name="afterHeader">
            <x-filament::button wire:click="refreshChecks" size="sm" color="gray" icon="heroicon-o-arrow-path">
                {{ __('system_status.refresh') }}
            </x-filament::button>
        </x-slot>

        <dl class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($checks as $name => $ok)
                <div class="flex items-center justify-between py-3">
                    <dt class="text-sm font-medium">{{ __('system_status.check.'.$name) }}</dt>
                    <dd>
                        <x-filament::badge :color="$ok ? 'success' : 'danger'">
                            {{ $ok ? __('system_status.ok') : __('system_status.fail') }}
                        </x-filament::badge>
                    </dd>
                </div>
            @endforeach

            <div class="flex items-center justify-between py-3">
                <dt class="text-sm font-medium">{{ __('system_status.failed_jobs') }}</dt>
                <dd>
                    <x-filament::badge :color="$failedJobs === 0 ? 'success' : 'warning'">
                        {{ $failedJobs ?? '—' }}
                    </x-filament::badge>
                </dd>
            </div>
        </dl>
    </x-filament::section>
</x-filament-panels::page>
