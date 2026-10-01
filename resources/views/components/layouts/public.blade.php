@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? config('app.name') }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-stone-100 text-stone-900 antialiased">
    <main class="mx-auto flex min-h-screen max-w-lg flex-col justify-center px-4 py-10">
        <div class="mb-6 flex items-center justify-between">
            <span class="text-lg font-semibold">{{ config('app.name') }}</span>
            <nav class="flex gap-2 text-sm" aria-label="{{ __('identity.fields.locale') }}">
                @foreach (config('app.supported_locales') as $code)
                    <a href="{{ route('locale.switch', $code) }}"
                       @class(['rounded px-2 py-1', 'bg-amber-500 text-white' => app()->getLocale() === $code, 'hover:bg-stone-200' => app()->getLocale() !== $code])
                       hreflang="{{ $code }}">{{ strtoupper($code) }}</a>
                @endforeach
            </nav>
        </div>

        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-stone-200">
            @if (session('status'))
                <p class="mb-4 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</p>
            @endif
            @if ($errors->any())
                <ul class="mb-4 rounded bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif

            {{ $slot }}
        </div>
    </main>
</body>
</html>
