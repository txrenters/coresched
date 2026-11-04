<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(0.979 0.007 255);
            }

            html.dark {
                background-color: oklch(0.196 0.03 258);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/site.webmanifest">

        {{--
            Server-rendered because SSR is off: link-preview scrapers do not
            run JavaScript, so tags set with Inertia's <Head> never reach them.
            Only the marketing pages are indexable (see NoIndexExceptMarketing).
            Each title matches the page's <Head title> plus app.ts's suffix, so
            the tab does not change once the app boots.
        --}}
        @php
            $seo = match (true) {
                request()->routeIs('home') => [
                    'title' => 'Scheduling software for service teams - '.config('app.name'),
                    'description' => config('app.name').' is scheduling software for service teams. Share one link, route every booking to the right person, and keep Google and Microsoft calendars in sync.',
                ],
                request()->routeIs('pricing') => [
                    'title' => 'Pricing - '.config('app.name'),
                    'description' => 'Compare '.config('app.name').' plans for teams, growing businesses and enterprises, and contact sales for a quote.',
                ],
                default => null,
            };
        @endphp
        @if ($seo)
            <meta name="description" content="{{ $seo['description'] }}">
            <link rel="canonical" href="{{ url()->current() }}">
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="{{ config('app.name') }}">
            <meta property="og:title" content="{{ $seo['title'] }}">
            <meta property="og:description" content="{{ $seo['description'] }}">
            <meta property="og:url" content="{{ url()->current() }}">
            <meta property="og:image" content="{{ asset('og-image.png') }}">
            <meta property="og:image:width" content="512">
            <meta property="og:image:height" content="512">
            <meta name="twitter:card" content="summary">
            @if (request()->routeIs('home'))
                <script type="application/ld+json">
                    {!! json_encode([
                        '@context' => 'https://schema.org',
                        '@type' => 'SoftwareApplication',
                        'name' => config('app.name'),
                        'applicationCategory' => 'BusinessApplication',
                        'operatingSystem' => 'Web',
                        'url' => route('home'),
                        'image' => asset('og-image.png'),
                        'description' => $seo['description'],
                    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}
                </script>
            @endif
        @endif

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ $seo['title'] ?? config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
