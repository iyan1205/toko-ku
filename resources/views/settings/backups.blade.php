{{-- Cadangan database: daftar, buat sekarang, unduh. Berkas disimpan di storage/app/private/backups. --}}
@php
    $ukuran = function (int $byte): string {
        return $byte >= 1048576
            ? number_format($byte / 1048576, 1, ',', '.').' MB'
            : number_format(max($byte, 1) / 1024, 0, ',', '.').' KB';
    };
@endphp

<x-app-layout>
    <x-slot name="header">Backup</x-slot>
    <x-slot name="description">Cadangan seluruh database. Dibuat otomatis setiap hari pukul 01.30; {{ $keep }} cadangan terbaru disimpan.</x-slot>
    @if ($supported && auth()->user()->can('settings.backup.create'))
        <x-slot name="actions">
            <form method="POST" action="{{ route('settings.backups.store') }}" x-data="{ proses: false }" x-on:submit="proses = true">
                @csrf
                <x-ui.button type="submit" icon="circle-stack" x-bind:disabled="proses">
                    <span x-text="proses ? 'Membuat backup…' : 'Buat backup sekarang'">Buat backup sekarang</span>
                </x-ui.button>
            </form>
        </x-slot>
    @endif

    <div class="grid gap-6">
        @unless ($supported)
            <x-ui.alert type="warning">Backup dari aplikasi hanya tersedia untuk database MySQL/MariaDB.</x-ui.alert>
        @endunless

        <x-ui.alert type="warning" title="Simpan berkas backup di tempat aman">
            Berkas backup berisi seluruh data toko, termasuk akun pengguna. Unduh secara berkala dan simpan di luar server
            (misal penyimpanan awan pribadi), karena backup di server ikut hilang bila servernya rusak.
        </x-ui.alert>

        <x-ui.table :sticky="false">
            <x-slot:head>
                <x-ui.table.th>Berkas</x-ui.table.th>
                <x-ui.table.th>Dibuat</x-ui.table.th>
                <x-ui.table.th align="right">Ukuran</x-ui.table.th>
                <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
            </x-slot:head>

            @forelse ($backups as $backup)
                <tr>
                    <x-ui.table.td><span class="font-mono text-xs text-slate-700">{{ $backup['name'] }}</span></x-ui.table.td>
                    <x-ui.table.td>@tanggal($backup['created_at'], true)</x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ $ukuran($backup['size']) }}</x-ui.table.td>
                    <x-ui.table.td align="right">
                        <x-ui.button variant="ghost" size="sm" icon="arrow-down-tray" :href="route('settings.backups.download', $backup['name'])">Unduh</x-ui.button>
                    </x-ui.table.td>
                </tr>
            @empty
                <x-ui.table.empty colspan="4" title="Belum ada backup"
                                  description="Backup otomatis berjalan bila scheduler aktif di server. Anda juga bisa membuatnya sekarang." />
            @endforelse
        </x-ui.table>
    </div>
</x-app-layout>
