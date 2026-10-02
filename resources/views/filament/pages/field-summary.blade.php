<x-filament-panels::page>
    @php
        $g = fn (string $key, array $replace = []): string => __('geo.ui.'.$key, $replace);
        $box = 'rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10';
        $cell = 'px-3 py-2 text-right tabular-nums';
    @endphp

    @if ($within !== null)
        <div class="text-sm">
            <a class="underline" href="{{ \App\Filament\Pages\FieldSummary::getUrl() }}">{{ $g('summary_all') }}</a>
            <span class="text-gray-500">/ {{ $within->name() }}</span>
        </div>
    @endif

    <section class="{{ $box }} overflow-x-auto" data-test="summary-territories">
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-3 py-2">{{ $g('territory') }}</th>
                    <th class="{{ $cell }}">{{ $g('houses') }}</th>
                    <th class="{{ $cell }}">{{ $g('apartments') }}</th>
                    <th class="{{ $cell }}">{{ $g('visited') }}</th>
                    <th class="{{ $cell }}">%</th>
                    <th class="{{ $cell }}">{{ $g('supporters') }}</th>
                    <th class="{{ $cell }}">%</th>
                    <th class="{{ $cell }}">{{ $g('attempts') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($rows as $row)
                    <tr data-test="summary-row" data-territory="{{ $row['territory']->code }}">
                        <td class="px-3 py-2" style="padding-left: {{ 0.75 + $row['depth'] * 1.25 }}rem">
                            <a class="underline" href="{{ \App\Filament\Pages\FieldSummary::getUrl(['territory' => $row['territory']->id]) }}">{{ $row['territory']->name() }}</a>
                        </td>
                        <td class="{{ $cell }}">{{ $row['figures']['houses'] }}</td>
                        <td class="{{ $cell }}">{{ $row['figures']['apartments'] }}</td>
                        <td class="{{ $cell }}">{{ $row['figures']['visited'] }}</td>
                        <td class="{{ $cell }} font-semibold">{{ $row['figures']['visited_pct'] }}%</td>
                        <td class="{{ $cell }}">{{ $row['figures']['supporters'] }}</td>
                        <td class="{{ $cell }} font-semibold">{{ $row['figures']['supporter_pct'] }}%</td>
                        <td class="{{ $cell }}">{{ $row['figures']['attempts'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-6 text-center text-gray-500" data-test="summary-empty">{{ $g('summary_empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    @if ($houses !== [])
        <section class="{{ $box }} overflow-x-auto" data-test="summary-houses">
            <h3 class="text-sm font-semibold">{{ $g('houses') }}</h3>
            <table class="mt-2 w-full text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($houses as $item)
                        <tr>
                            <td class="px-3 py-2">
                                <a class="underline" href="{{ \App\Filament\Resources\Houses\HouseResource::getUrl('view', ['record' => $item['house']]) }}">{{ $item['house']->label() }}</a>
                            </td>
                            <td class="{{ $cell }}">{{ $item['figures']['visited'] ?? 0 }} / {{ $item['figures']['apartments'] ?? 0 }}</td>
                            <td class="{{ $cell }} font-semibold">{{ $item['figures']['visited_pct'] ?? 0 }}%</td>
                            <td class="{{ $cell }}">{{ $g('supporters') }}: {{ $item['figures']['supporters'] ?? 0 }} · {{ $item['figures']['supporter_pct'] ?? 0 }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</x-filament-panels::page>
