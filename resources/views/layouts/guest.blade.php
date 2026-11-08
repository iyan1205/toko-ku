{{-- Layout halaman tamu (login). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Masuk · {{ config('app.name') }}</title>
    <style>[x-cloak]{display:none !important}</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans text-slate-800 antialiased">
<div class="relative flex min-h-full items-center justify-center overflow-hidden px-4 py-10">
    {{-- Latar lembut: gradasi primary tipis di bagian atas. --}}
    <div class="pointer-events-none absolute inset-x-0 top-0 h-80 bg-gradient-to-b from-primary-100/70 to-transparent" aria-hidden="true"></div>

    <div class="relative w-full max-w-sm">
        <div class="mb-6 text-center">
            <span class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-primary-600 text-white shadow-sm">
                <x-ui.icon name="building-storefront" class="h-6 w-6" />
            </span>
            <p class="mt-3 text-xl font-semibold tracking-tight text-slate-900">{{ config('app.name') }}</p>
            <p class="mt-0.5 text-sm text-slate-500">Point of Sale</p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-card">
            {{ $slot }}
        </div>

        <p class="mt-6 text-center text-xs text-slate-400">
            &copy; {{ now()->year }} {{ config('app.name') }}
        </p>
    </div>
</div>

<x-ui.toast />
</body>
</html>
