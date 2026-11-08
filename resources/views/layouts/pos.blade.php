{{--
    Layout Kasir layar penuh: tanpa sidebar, bilah atas tipis, seluruh lebar untuk transaksi.
    Dipakai lewat <x-pos-layout>. Slot header = judul halaman (untuk <title>).
--}}
@php($user = auth()->user())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ trim(strip_tags((string) ($header ?? 'Kasir'))) }} · {{ config('app.name') }}</title>
    <style>[x-cloak]{display:none !important}</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans text-slate-800 antialiased">
<div class="flex min-h-full flex-col">
    <header class="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-3 border-b border-slate-200 bg-white px-3 sm:px-4 print:hidden">
        <a href="{{ route('dashboard') }}"
           class="inline-flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
           title="Keluar dari kasir ke dashboard">
            <x-ui.icon name="arrow-left" variant="m" class="h-5 w-5" />
            <span class="hidden sm:inline">Dashboard</span>
        </a>

        <div class="flex min-w-0 items-center gap-2.5 border-l border-slate-200 pl-3">
            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-primary-600 text-white">
                <x-ui.icon name="building-storefront" variant="m" class="h-4 w-4" />
            </span>
            <span class="min-w-0">
                <span class="block truncate text-sm font-semibold leading-tight text-slate-900">{{ $storeName }}</span>
                <span class="block text-xs leading-tight text-slate-500">{{ trim(strip_tags((string) ($header ?? 'Kasir'))) }}</span>
            </span>
        </div>

        <div class="ml-auto flex items-center gap-3">
            <span x-data="{ jam: '' }"
                  x-init="const f = new Intl.DateTimeFormat('id-ID', { weekday: 'short', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
                          jam = f.format(new Date()); setInterval(() => jam = f.format(new Date()), 15000)"
                  x-text="jam"
                  class="hidden text-sm tabular-nums text-slate-500 md:inline"></span>

            <span class="flex items-center gap-2">
                <span class="grid h-8 w-8 place-items-center rounded-full bg-primary-100 text-sm font-semibold text-primary-700" aria-hidden="true">
                    {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                </span>
                <span class="hidden text-left sm:block">
                    <span class="block max-w-[10rem] truncate text-sm font-medium leading-tight text-slate-900">{{ $user->name }}</span>
                    <span class="block text-xs leading-tight text-slate-500">{{ $user->namaPeran() }}</span>
                </span>
            </span>
        </div>
    </header>

    <main class="flex-1 p-3 sm:p-4">
        {{ $slot }}
    </main>

    <x-ui.toast />
    <x-ui.confirm-dialog />
</div>
</body>
</html>
