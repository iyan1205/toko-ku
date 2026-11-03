<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Masuk · {{ config('app.name') }}</title>
    <style>[x-cloak]{display:none !important}</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-stone-100 text-stone-800 antialiased">
{{-- Latar bermerek: merah gelap dengan sorotan kuning, senada sidebar. --}}
<div class="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-10">
    <div class="pointer-events-none absolute inset-0 bg-gradient-to-br from-brand-900 via-brand-800 to-brand-950" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-accent-400/20 blur-3xl" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -bottom-32 -left-24 h-80 w-80 rounded-full bg-accent-500/10 blur-3xl" aria-hidden="true"></div>

    <div class="relative w-full max-w-sm">
        <div class="mb-6 text-center">
            <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-accent-400 text-2xl shadow-lg"
                  aria-hidden="true">🏪</span>
            <p class="mt-3 text-xl font-bold tracking-tight text-white">{{ config('app.name') }}</p>
            <p class="mt-0.5 text-sm text-brand-200">Point of Sale</p>
        </div>

        <div class="rounded-card bg-white p-6 shadow-float">
            {{ $slot }}
        </div>

        <p class="mt-6 text-center text-xs text-brand-200/80">
            &copy; {{ now()->year }} {{ config('app.name') }}
        </p>
    </div>
</div>
@include('partials.toast')
</body>
</html>
