{{-- SDM → Struktur Organisasi: bagan dari atasan langsung karyawan aktif. --}}
@php
    $bolehDetail = auth()->user()->can('sdm.employees.view');
@endphp

<x-app-layout>
    <x-slot name="header">Struktur Organisasi</x-slot>

    <x-ui.card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            @if ($branches->count() > 1)
                <x-ui.select name="cabang" label="Cabang" :options="$branches->pluck('name', 'id')" :selected="$filters['cabang'] ?? null" placeholder="Semua cabang" />
            @endif
            <x-ui.select name="departemen" label="Departemen" :options="$departments->pluck('name', 'id')" :selected="$filters['departemen'] ?? null" placeholder="Semua departemen" />
            <x-ui.button type="submit" variant="secondary" icon="funnel">Terapkan</x-ui.button>
        </form>
    </x-ui.card>

    <div x-data="{ cari: '', cocok(nama) { return this.cari.trim() !== '' && nama.includes(this.cari.trim().toLowerCase()); } }" class="rounded-xl border border-slate-200 bg-white shadow-card">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
            <p class="text-sm text-slate-500">{{ $total }} karyawan aktif{{ $withoutSupervisor > 1 ? ', '.$withoutSupervisor.' tanpa atasan (tampil sebagai puncak)' : '' }}. Atasan diatur di data karyawan atau lewat mutasi.</p>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.input name="cari" label="Cari karyawan" hide-label icon="magnifying-glass" placeholder="Cari nama atau jabatan" x-model="cari" class="w-56" />
                <x-ui.button type="button" variant="secondary" size="sm" icon="arrows-pointing-in" x-on:click="$dispatch('org-ciut', false)">Ciutkan</x-ui.button>
                <x-ui.button type="button" variant="secondary" size="sm" icon="arrows-pointing-out" x-on:click="$dispatch('org-ciut', true)">Buka semua</x-ui.button>
            </div>
        </div>
        @if ($tree === [])
            <x-ui.empty-state icon="user-group" title="Belum ada karyawan aktif" description="Tambah karyawan dan isi atasan langsungnya untuk membentuk bagan." class="py-12" />
        @else
            <div class="overflow-x-auto p-6">
                <div class="org-tree inline-block min-w-full">
                    <ul>
                        @foreach ($tree as $node)
                            @include('sdm.org-chart.node', ['node' => $node])
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
