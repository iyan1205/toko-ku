{{-- Pengaturan SDM: aturan absensi bawaan yang dipakai bila shift tidak mengaturnya sendiri. --}}
@php
    $bolehUbah = auth()->user()->can('settings.sdm.update');
@endphp

<x-app-layout>
    <x-slot name="header">SDM</x-slot>
    <x-slot name="description">Hari kerja, jam kerja bawaan, toleransi absen, dan selfie.</x-slot>

    <form method="POST" action="{{ route('settings.sdm.update') }}" class="grid gap-8">
        @csrf
        @method('PUT')

        <fieldset @disabled(! $bolehUbah) class="grid gap-8">
            <x-ui.form-section title="Hari kerja" description="Dipakai untuk menghitung hari kerja, alpha, dan hari libur mingguan bila karyawan tidak punya jadwal pada hari itu.">
                <x-ui.select name="workweek" label="Hari kerja per minggu" :options="[6 => '6 hari (Senin–Sabtu, libur Minggu)', 5 => '5 hari (Senin–Jumat, libur Sabtu & Minggu)']"
                             :selected="$values['workweek']" required />
            </x-ui.form-section>

            <x-ui.form-section title="Jam kerja bawaan" description="Untuk hari kerja tanpa shift di jadwal. Kosongkan bila semua karyawan memakai jadwal shift.">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input name="default_in" label="Jam masuk" type="time" :value="$values['default_in']" />
                    <x-ui.input name="default_out" label="Jam pulang" type="time" :value="$values['default_out']" />
                </div>
            </x-ui.form-section>

            <x-ui.form-section title="Toleransi absen" description="Bawaan untuk semua shift. Shift yang mengisi toleransinya sendiri memakai angka di shift.">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input name="late_tolerance" label="Toleransi telat" type="number" min="0" max="240" suffix="menit" :value="$values['late_tolerance']" required
                                hint="Masuk lewat jam masuk + toleransi tercatat Terlambat. Menit telat dihitung dari jam masuk." />
                    <x-ui.input name="early_tolerance" label="Toleransi pulang cepat" type="number" min="0" max="240" suffix="menit" :value="$values['early_tolerance']" required />
                </div>
            </x-ui.form-section>

            <x-ui.form-section title="Absen dari ponsel" description="Radius lokasi diatur per cabang di Pengaturan → Cabang.">
                <x-ui.checkbox name="require_selfie" label="Wajib foto selfie saat absen masuk dan pulang" :checked="$values['require_selfie']" unchecked-value="0"
                               hint="Foto disimpan di server (bukan folder publik) dan hanya bisa dibuka oleh karyawan itu dan SDM." />

                @if ($bolehUbah)
                    <x-slot:footer>
                        <x-ui.button type="submit">Simpan pengaturan SDM</x-ui.button>
                    </x-slot:footer>
                @endif
            </x-ui.form-section>
        </fieldset>
    </form>
</x-app-layout>
