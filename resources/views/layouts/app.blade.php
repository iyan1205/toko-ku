<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ trim(strip_tags((string) ($header ?? ''))) !== '' ? strip_tags((string) $header) . ' · ' : '' }}{{ config('app.name') }}</title>
    <style>[x-cloak]{display:none !important}</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-100 text-slate-800">
@php
    $menu = \App\Support\Menu::untuk(auth()->user());
    // Kelompokkan menu supaya sidebar tidak jadi satu daftar panjang tanpa struktur.
    $grupMenu = [
        'Transaksi' => ['pos.index', 'sales.index', 'purchases.index', 'payables.index', 'receivables.index'],
        'Katalog' => ['products.index', 'categories.index', 'units.index', 'stock.index', 'stock-opnames.index', 'recipes.index'],
        'Relasi' => ['suppliers.index', 'customers.index'],
        'Lainnya' => ['reports.index', 'users.index', 'settings.edit'],
    ];
@endphp

<div x-data="{ sidebar: false }" class="min-h-screen">
    {{-- Latar gelap untuk drawer di layar kecil --}}
    <div x-show="sidebar" x-cloak x-transition.opacity @click="sidebar = false"
         class="fixed inset-0 z-30 bg-black/60 lg:hidden"></div>

    {{-- ============ Sidebar ============ --}}
    <aside :class="sidebar ? 'translate-x-0' : '-translate-x-full'"
           class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-white/95 text-slate-800 transition-transform duration-200 lg:translate-x-0 lg:-translate-x-0 print:hidden">

        {{-- Identitas toko --}}
        <div class="flex h-16 shrink-0 items-center gap-3 border-b border-slate-500 px-5">
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-amber-400 text-base font-bold text-slate-900"
                  aria-hidden="true">🏪</span>
            <span class="min-w-0">
                <span class="block truncate text-sm font-semibold leading-tight text-slate-900">{{ config('app.name') }}</span>
                <span class="block text-[11px] leading-tight text-slate-500">Point of Sale</span>
            </span>
        </div>

        <nav class="flex-1 space-y-4 overflow-y-auto px-3 py-4" aria-label="Menu utama">
            @foreach ($grupMenu as $namaGrup => $ruteGrup)
                @php($anggota = $menu->filter(fn ($m) => in_array($m['route'], $ruteGrup, true)))
                @continue($anggota->isEmpty())

                <div>
                    <p class="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ $namaGrup }}</p>
                    <div class="space-y-0.5">
                        @foreach ($anggota as $item)
                            @php($aktif = request()->routeIs($item['active'] ?? $item['route']))
                            <a href="{{ route($item['route']) }}"
                               @class([
                                   'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
                                   'bg-amber-100 font-semibold text-slate-900 shadow-sm' => $aktif,
                                   'text-slate-700 hover:bg-slate-200 hover:text-slate-900' => ! $aktif,
                               ])
                               @if ($aktif) aria-current="page" @endif>
                                <span class="w-5 shrink-0 text-center" aria-hidden="true">{{ $item['icon'] }}</span>
                                <span class="truncate">{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>

        {{-- Pengguna + keluar --}}
        <div class="shrink-0 border-t border-slate-200 p-3">
            <div class="flex items-center gap-3 rounded-lg px-2 py-2">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-white/30 text-sm font-semibold text-slate-900"
                      aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-medium text-slate-900">{{ auth()->user()->name }}</span>
                    <span class="block truncate text-[11px] text-slate-500">{{ auth()->user()->namaPeran() }}</span>
                </span>
            </div>
            <form method="POST" action="{{ route('logout') }}" class="mt-1">
                @csrf
                <button type="submit"
                        class="w-full rounded-lg px-3 py-2 text-left text-sm text-slate-700 transition-colors hover:bg-slate-200 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500">
                    Keluar
                </button>
            </form>
        </div>
    </aside>

    {{-- ============ Isi ============ --}}
    <div class="lg:pl-64">
        <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/95 px-4 backdrop-blur sm:px-6 print:hidden">
            <button type="button" @click="sidebar = true"
                    class="-ml-1 rounded-lg p-2 text-slate-600 transition-colors hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 lg:hidden"
                    aria-label="Buka menu">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <h1 class="truncate text-lg font-semibold text-slate-900">{{ $header ?? '' }}</h1>
        </header>

        <main class="p-4 sm:p-6">
            {{ $slot }}
        </main>
    </div>

    @include('partials.toast')
    @include('partials.confirm-delete')
</div>
</body>
</html>