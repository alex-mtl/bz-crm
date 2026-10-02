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

    <x-filament::section>
        <x-slot name="heading">{{ __('system_status.antivirus') }}</x-slot>
        <x-slot name="description">{{ __('system_status.antivirus_hint') }}</x-slot>

        <x-slot name="afterHeader">
            {{ $this->switchAntivirusAction }}
        </x-slot>

        <dl class="divide-y divide-gray-100 dark:divide-white/5">
            <div class="flex items-center justify-between py-3">
                <dt class="text-sm font-medium">{{ __('system_status.antivirus') }}</dt>
                <dd>
                    <x-filament::badge :color="$antivirusEnabled ? 'success' : 'warning'">
                        {{ $antivirusEnabled ? __('system_status.antivirus_on') : __('system_status.antivirus_off') }}
                    </x-filament::badge>
                </dd>
            </div>
            <div class="flex items-center justify-between py-3">
                <dt class="text-sm font-medium">{{ __('system_status.antivirus_service') }}</dt>
                <dd>
                    <x-filament::badge :color="$antivirusReachable ? 'success' : ($antivirusEnabled ? 'danger' : 'gray')">
                        {{ $antivirusReachable ? __('system_status.ok') : __('system_status.antivirus_service_down') }}
                    </x-filament::badge>
                </dd>
            </div>
        </dl>
    </x-filament::section>
</x-filament-panels::page>
