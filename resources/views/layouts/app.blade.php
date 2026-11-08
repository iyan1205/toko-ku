{{--
    Layout aplikasi: sidebar (bisa diciutkan) + topbar + breadcrumb + area konten.
    Dipakai lewat <x-app-layout>. Slot:
        header      : judul halaman (wajib untuk halaman biasa)
        description : (opsional) teks di bawah judul; bawaan = deskripsi menu di config/menu.php
                      untuk halaman indeks menu
        actions     : (opsional) tombol di kanan judul
    Data navigasi ($menuTree, $menuCurrent, $lowStock, $storeName) dari NavigationComposer.
--}}
@php
    $pageTitle = trim(strip_tags((string) ($header ?? '')));
    $currentItem = $menuCurrent['item'] ?? null;
    $isMenuIndex = $currentItem['is_index'] ?? false;

    // Breadcrumb: menu induk > menu > halaman saat ini (bila bukan halaman indeks menu itu).
    $breadcrumb = [];
    if ($currentItem) {
        if ($menuCurrent['parent']) {
            $breadcrumb[] = ['label' => $menuCurrent['parent']['label']];
        }
        $breadcrumb[] = ['label' => $currentItem['label'], 'href' => $currentItem['url']];
        if (! $isMenuIndex && $pageTitle !== '' && $pageTitle !== $currentItem['label']) {
            $breadcrumb[] = ['label' => $pageTitle];
        }
    } elseif ($pageTitle !== '') {
        $breadcrumb[] = ['label' => $pageTitle];
    }

    $pageDescription = isset($description) && trim((string) $description) !== ''
        ? $description
        : ($isMenuIndex ? ($currentItem['description'] ?? null) : null);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle !== '' ? $pageTitle.' · ' : '' }}{{ config('app.name') }}</title>
    <style>[x-cloak]{display:none !important}</style>
    {{-- Pasang status sidebar ciut sebelum halaman tampil agar tidak berkedip. --}}
    <script>try { if (localStorage.getItem('tokoku.sidebar-ciut') === '1') document.documentElement.classList.add('sidebar-ciut'); } catch (e) {}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans text-slate-800 antialiased">
<div x-data="{
        sidebarOpen: false,
        ciut: document.documentElement.classList.contains('sidebar-ciut'),
        ciutkan(nilai) {
            this.ciut = nilai;
            document.documentElement.classList.toggle('sidebar-ciut', nilai);
            try { localStorage.setItem('tokoku.sidebar-ciut', nilai ? '1' : '0'); } catch (e) {}
        },
        sedangCiut() {
            return this.ciut && window.matchMedia('(min-width: 1024px)').matches;
        },
     }"
     x-on:keydown.escape.window="sidebarOpen = false"
     class="min-h-full">

    {{-- Latar gelap saat sidebar terbuka sebagai drawer (layar kecil) --}}
    <div x-show="sidebarOpen" x-cloak x-transition.opacity x-on:click="sidebarOpen = false"
         class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden" aria-hidden="true"></div>

    @include('layouts.partials.sidebar')

    <div class="layout-content flex min-h-full flex-col">
        @include('layouts.partials.topbar')

        <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-screen-2xl">
                @if ($pageTitle !== '' || isset($actions))
                    <x-ui.page-header :title="$header ?? ''" :description="$pageDescription" class="mb-6">
                        @isset($actions)
                            <x-slot:actions>{{ $actions }}</x-slot:actions>
                        @endisset
                    </x-ui.page-header>
                @endif

                {{ $slot }}
            </div>
        </main>
    </div>

    <x-ui.toast />
    <x-ui.confirm-dialog />
</div>
</body>
</html>
