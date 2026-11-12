{{-- Pengguna belum ditautkan ke data karyawan: Layanan Mandiri belum bisa dipakai. --}}
<x-app-layout>
    <x-slot name="header">Layanan Mandiri</x-slot>
    <x-ui.card>
        <x-ui.empty-state icon="identification" title="Akun Anda belum ditautkan ke data karyawan"
                          description="Minta bagian SDM membuka data karyawan Anda dan memilih akun login ini di kolom Akun login. Setelah itu profil, absensi, saldo cuti, dan pengajuan Anda tampil di sini." />
    </x-ui.card>
</x-app-layout>
