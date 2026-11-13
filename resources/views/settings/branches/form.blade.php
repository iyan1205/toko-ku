{{-- Tambah / ubah cabang. --}}
@php
    $edit = $branch->exists;
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah cabang' : 'Tambah cabang' }}</x-slot>
    <x-slot name="description">{{ $edit ? $branch->name : 'Cabang atau outlet baru. Kosongkan alamat dan telepon bila sama dengan profil toko.' }}</x-slot>

    <form method="POST" action="{{ $edit ? route('settings.branches.update', $branch) : route('settings.branches.store') }}" class="grid gap-8">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <x-ui.form-section title="Data cabang" description="Kode singkat dipakai untuk penanda cabang, misal PST atau JBG.">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="code" label="Kode" :value="$branch->code" maxlength="10" required class="sm:col-span-1" style="text-transform: uppercase" />
                <x-ui.input name="name" label="Nama cabang" :value="$branch->name" maxlength="100" required class="sm:col-span-2" />
            </div>
            <x-ui.textarea name="address" label="Alamat" :value="$branch->address" rows="2" maxlength="255" />
            <x-ui.input name="phone" label="Telepon" type="tel" :value="$branch->phone" maxlength="25" />
        </x-ui.form-section>

        <x-ui.form-section title="Lokasi absen" description="Opsional. Bila diisi, karyawan cabang ini hanya bisa absen masuk dari ponsel di dalam radius dari titik ini.">
            <div class="grid gap-5 sm:grid-cols-3" x-data="{ lat: @js((string) ($branch->latitude ?? '')), lng: @js((string) ($branch->longitude ?? '')), cari: false }">
                <x-ui.input name="latitude" label="Garis lintang" type="number" step="0.0000001" x-model="lat" :value="$branch->latitude" placeholder="-6.2000000" />
                <x-ui.input name="longitude" label="Garis bujur" type="number" step="0.0000001" x-model="lng" :value="$branch->longitude" placeholder="106.8166667" />
                <x-ui.input name="attendance_radius_m" label="Radius (meter)" type="number" min="10" max="10000" :value="$branch->attendance_radius_m" placeholder="100" />
                <div class="sm:col-span-3">
                    <x-ui.button type="button" variant="secondary" size="sm" icon="map-pin" x-bind:disabled="cari"
                                 x-on:click="cari = true; navigator.geolocation?.getCurrentPosition(p => { lat = p.coords.latitude.toFixed(7); lng = p.coords.longitude.toFixed(7); cari = false }, () => cari = false, { enableHighAccuracy: true, timeout: 10000 })">Pakai lokasi saya sekarang</x-ui.button>
                    <p class="mt-1 text-xs text-slate-500">Buka halaman ini dari ponsel saat berada di toko, lalu klik tombol di atas.</p>
                </div>
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Status" description="Cabang nonaktif tidak bisa dipilih di topbar. Cabang utama dipakai bila pengguna belum memilih cabang.">
            @if ($branch->is_default)
                <x-ui.alert>Ini cabang utama: selalu aktif dan tidak bisa dihapus. Untuk menggantinya, jadikan cabang lain sebagai cabang utama.</x-ui.alert>
                <input type="hidden" name="is_active" value="1">
            @else
                <x-ui.checkbox name="is_active" label="Cabang aktif" :checked="$branch->is_active ?? true" unchecked-value="0" />
                <x-ui.checkbox name="is_default" label="Jadikan cabang utama" unchecked-value="0"
                               hint="Cabang utama sebelumnya menjadi cabang biasa." />
            @endif

            <x-slot:footer>
                <x-ui.button :href="route('settings.branches.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit">{{ $edit ? 'Simpan perubahan' : 'Simpan cabang' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
