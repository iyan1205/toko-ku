{{-- Akuntansi → Laporan Keuangan: pilihan laporan. --}}
@php
    $laporan = [
        ['route' => 'financial-reports.income', 'icon' => 'chart-bar', 'title' => 'Laba Rugi', 'text' => 'Pendapatan, HPP, beban, dan laba bersih. Bisa per bulan, dibandingkan dengan periode sebelumnya atau tahun lalu, dan per cabang atau cost center.'],
        ['route' => 'financial-reports.balance', 'icon' => 'scale', 'title' => 'Neraca', 'text' => 'Aset, liabilitas, dan ekuitas per tanggal, dengan pembanding akhir tahun lalu.'],
        ['route' => 'financial-reports.cash-flow', 'icon' => 'banknotes', 'title' => 'Arus Kas', 'text' => 'Metode tidak langsung: laba bersih disesuaikan dengan perubahan akun neraca, dikelompokkan operasi, investasi, dan pendanaan.'],
        ['route' => 'financial-reports.equity', 'icon' => 'building-library', 'title' => 'Perubahan Ekuitas', 'text' => 'Saldo awal, penambahan, pengurangan, dan saldo akhir setiap akun ekuitas.'],
    ];
@endphp

<x-app-layout>
    <x-slot name="header">Laporan Keuangan</x-slot>
    <x-slot name="description">Dihitung dari jurnal yang sudah diposting. Klik angka akun untuk membuka buku besarnya.</x-slot>

    <div class="grid gap-4 sm:grid-cols-2">
        @foreach ($laporan as $l)
            <a href="{{ route($l['route']) }}" class="group rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-primary-300 hover:shadow">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary-50 text-primary-600"><x-ui.icon :name="$l['icon']" class="h-5 w-5" /></span>
                    <h2 class="text-base font-semibold text-slate-900 group-hover:text-primary-700">{{ $l['title'] }}</h2>
                </div>
                <p class="mt-3 text-sm text-slate-600">{{ $l['text'] }}</p>
            </a>
        @endforeach
    </div>
</x-app-layout>
