<!DOCTYPE html>
@php($theme = auth()->user()?->theme?->value ?? 'system')
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme-preference="{{ $theme }}" @class(['dark' => $theme === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        {{-- Apply the system theme before first paint to avoid a light flash in dark mode. --}}
        <script>
            (function () {
                var pref = document.documentElement.dataset.themePreference;
                if (pref === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>
        <title inertia>{{ config('app.name', 'ORBIT') }}</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @vite(['resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head />
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
