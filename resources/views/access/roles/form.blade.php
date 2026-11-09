{{-- Tambah / ubah nama dan keterangan peran. Nama peran bawaan tidak bisa diganti. --}}
@php($edit = $role->exists)

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah peran '.$role->name : 'Tambah peran' }}</x-slot>
    <x-slot name="description">{{ $edit ? 'Hak akses peran ini diatur di halaman Hak Akses.' : 'Setelah disimpan, Anda langsung diarahkan ke pengaturan hak aksesnya.' }}</x-slot>

    <form method="POST" action="{{ $edit ? route('roles.update', $role) : route('roles.store') }}">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <x-ui.form-section title="Data peran" description="Nama tampil di daftar pengguna dan alur persetujuan cuti.">
            @if ($builtIn)
                <input type="hidden" name="name" value="{{ $role->name }}">
                <x-ui.input name="name_display" label="Nama peran" :value="$role->name" disabled required
                            hint="Nama peran bawaan tidak dapat diganti." />
            @else
                <x-ui.input name="name" label="Nama peran" :value="$role->name" maxlength="50" placeholder="Misal: Supervisor Shift" required autofocus />
            @endif
            <x-ui.textarea name="description" label="Keterangan" :value="$role->description" rows="2" maxlength="255"
                           hint="Jelaskan singkat tugas peran ini." />

            <x-slot:footer>
                <x-ui.button :href="route('roles.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit">{{ $edit ? 'Simpan perubahan' : 'Simpan & atur hak akses' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
