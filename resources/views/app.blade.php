<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" style="font-size: {{ $textSize->percent() }}%">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#085019">

        <title inertia>{{ config('app.name', 'DJIHS Enrollment') }}</title>
        {{-- ?v= makes browsers drop an icon cached from another app on the same address. --}}
        <link rel="icon" href="/favicon.ico?v=2" sizes="48x48">
        <link rel="icon" href="/favicon-32x32.png?v=2" type="image/png" sizes="32x32">
        <link rel="icon" href="/favicon-96x96.png?v=2" type="image/png" sizes="96x96">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">
        <link rel="manifest" href="/site.webmanifest?v=2">

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-display bg-background-light text-slate-800 antialiased">
        @inertia
    </body>
</html>
