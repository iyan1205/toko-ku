{{-- Tambah / ubah cost center. --}}
@php($edit = $costCenter->exists)

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah cost center '.$costCenter->code : 'Tambah cost center' }}</x-slot>
    <x-slot name="description">{{ $edit ? $costCenter->name : 'Bagian usaha untuk memilah pendapatan dan beban di laporan.' }}</x-slot>

    <form method="POST" action="{{ $edit ? route('cost-centers.update', $costCenter) : route('cost-centers.store') }}" class="grid gap-8">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <x-ui.form-section title="Cost center" description="Departemen SDM opsional: nanti beban gaji karyawan departemen itu masuk ke cost center ini.">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="code" label="Kode" :value="$costCenter->code" maxlength="20" required placeholder="Misal TOKO" />
                <x-ui.input name="name" label="Nama" :value="$costCenter->name" maxlength="100" required class="sm:col-span-2" placeholder="Misal Toko Jabong" />
            </div>
            <x-ui.select name="department_id" label="Departemen SDM" :options="$departments" :selected="$costCenter->department_id" placeholder="Tidak dihubungkan" />
            <x-ui.input name="description" label="Deskripsi" :value="$costCenter->description" maxlength="255" />
            <x-ui.checkbox name="is_active" label="Aktif" :checked="$costCenter->is_active ?? true" unchecked-value="0"
                           hint="Cost center nonaktif tidak bisa dipilih di jurnal baru. Jurnal lama tidak berubah." />

            <x-slot:footer>
                <x-ui.button :href="route('cost-centers.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit">{{ $edit ? 'Simpan perubahan' : 'Simpan cost center' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
