<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Image Studio') }}</title>

        {{--
            Space Grotesk for the interface, IBM Plex Mono for money figures.
            Plex Mono is loaded for its tabular digits: the reconciliation table
            compares nine-decimal amounts column by column, so the digits have to
            align vertically to be readable at all.
        --}}
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link
            href="https://fonts.bunny.net/css?family=space-grotesk:400,500,600,700|ibm-plex-mono:400,500,600&display=swap"
            rel="stylesheet" />

        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="min-h-full bg-ink font-sans text-body antialiased">
        @inertia
    </body>
</html>
