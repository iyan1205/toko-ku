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
