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
<body class="bg-stone-100 text-stone-800 antialiased">
@php($menu = \App\Support\Menu::untuk(auth()->user()))

<div x-data="{ sidebar: false }" class="min-h-screen">
    {{-- Latar gelap untuk drawer di layar kecil --}}
    <div x-show="sidebar" x-cloak x-transition.opacity @click="sidebar = false"
         class="fixed inset-0 z-30 bg-stone-900/60 lg:hidden"></div>

    <aside :class="sidebar ? 'translate-x-0' : '-translate-x-full'"
           class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-stone-900 text-stone-300 transition-transform duration-200 lg:translate-x-0">
        <div class="flex h-16 items-center gap-2 border-b border-stone-800 px-5">
            <span class="text-xl">🏪</span>
            <span class="truncate text-base font-semibold text-white">{{ config('app.name') }}</span>
        </div>

        <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-4" aria-label="Menu utama">
            @foreach ($menu as $item)
                @php($aktif = request()->routeIs($item['active'] ?? $item['route']))
                <a href="{{ route($item['route']) }}"
                   @class([
                       'flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors',
                       'bg-teal-700 font-medium text-white' => $aktif,
                       'hover:bg-stone-800 hover:text-white' => ! $aktif,
                   ])
                   @if ($aktif) aria-current="page" @endif>
                    <span class="w-5 text-center" aria-hidden="true">{{ $item['icon'] }}</span>
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>

        <div class="border-t border-stone-800 p-4 text-sm">
            <p class="truncate font-medium text-white">{{ auth()->user()->name }}</p>
            <p class="truncate text-xs text-stone-400">{{ auth()->user()->namaPeran() }}</p>
            <form method="POST" action="{{ route('logout') }}" class="mt-3">
                @csrf
                <button type="submit"
                        class="w-full rounded-md border border-stone-700 px-3 py-1.5 text-left text-stone-300 hover:bg-stone-800 hover:text-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    Keluar
                </button>
            </form>
        </div>
    </aside>

    <div class="lg:pl-64">
        <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-stone-200 bg-white px-4 sm:px-6">
            <button type="button" @click="sidebar = true"
                    class="rounded-md p-2 text-stone-600 hover:bg-stone-100 focus:outline-none focus:ring-2 focus:ring-teal-500 lg:hidden"
                    aria-label="Buka menu">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <h1 class="truncate text-lg font-semibold text-stone-900">{{ $header ?? '' }}</h1>
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
