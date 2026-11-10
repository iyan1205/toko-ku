{{-- Tambah / ubah gudang. --}}
@php
    $edit = $warehouse->exists;
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah gudang' : 'Tambah gudang' }}</x-slot>
    <x-slot name="description">{{ $edit ? $warehouse->name : 'Gudang, toko, atau rak tempat stok disimpan.' }}</x-slot>

    <form method="POST" action="{{ $edit ? route('warehouses.update', $warehouse) : route('warehouses.store') }}" class="grid gap-8">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <x-ui.form-section title="Data gudang" description="Kode singkat tampil di kartu stok dan dokumen, misal GDG atau TOKO.">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="code" label="Kode" :value="$warehouse->code" maxlength="10" required class="sm:col-span-1" style="text-transform: uppercase" />
                <x-ui.input name="name" label="Nama gudang" :value="$warehouse->name" maxlength="100" required class="sm:col-span-2" />
            </div>
            <x-ui.textarea name="address" label="Alamat" :value="$warehouse->address" rows="2" maxlength="255" />
            <x-ui.select name="branch_id" label="Cabang" :options="$branches" :selected="$warehouse->branch_id" placeholder="Tidak ditautkan ke cabang"
                         hint="Opsional. Nanti dipakai untuk memilih gudang kasir per cabang." />
        </x-ui.form-section>

        <x-ui.form-section title="Status" description="Gudang nonaktif tidak bisa dipilih di transaksi baru. Gudang hanya bisa dinonaktifkan bila stoknya kosong.">
            @if ($warehouse->is_default)
                <x-ui.alert>Ini gudang utama: selalu aktif dan tidak bisa dihapus. Untuk menggantinya, jadikan gudang lain sebagai gudang utama.</x-ui.alert>
                <input type="hidden" name="is_active" value="1">
            @else
                @if ($stock > 0 && $warehouse->is_active)
                    <x-ui.alert type="warning">Gudang ini masih berisi stok untuk {{ $stock }} produk, jadi belum bisa dinonaktifkan.</x-ui.alert>
                @endif
                <x-ui.checkbox name="is_active" label="Gudang aktif" :checked="$warehouse->is_active ?? true" unchecked-value="0" />
                <x-ui.checkbox name="is_default" label="Jadikan gudang utama" unchecked-value="0"
                               hint="Kasir menjual dari gudang utama. Gudang utama sebelumnya menjadi gudang biasa." />
            @endif

            <x-slot:footer>
                <x-ui.button :href="route('warehouses.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit">{{ $edit ? 'Simpan perubahan' : 'Simpan gudang' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
