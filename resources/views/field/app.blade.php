<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#b45309">
    <title>{{ __('geo.app.title') }} — {{ config('app.name') }}</title>
    <link rel="manifest" href="/field/manifest.json">
    <link rel="icon" href="/field/icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="{{ $style }}">
</head>
<body>
    {{-- ТЗ §33–34, ADR-013: the page is a shell kept by the service worker; everything else is in /field/app.js. --}}
    <div id="app" data-test="field-app"><p class="empty">{{ __('geo.app.loading') }}</p></div>
    <script type="application/json" id="boot">@json($boot)</script>
    <script src="{{ $script }}" defer></script>
</body>
</html>
