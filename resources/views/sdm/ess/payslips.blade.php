{{-- Slip gaji: diisi modul Penggajian (Fase 14). --}}
<x-app-layout>
    <x-slot name="header">Slip Gaji</x-slot>

    @include('sdm.ess.nav')

    <x-ui.card>
        <x-ui.empty-state icon="banknotes" title="Slip gaji belum tersedia"
                          description="Slip gaji muncul di sini setelah penggajian dijalankan lewat aplikasi (modul Penggajian sedang disiapkan)." />
    </x-ui.card>

    @include('sdm.ess.spacer')
</x-app-layout>
