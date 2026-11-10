{{--
    Data master sederhana (kategori, satuan): cari, urutkan, ekspor, tambah/ubah lewat dialog.
    Variabel: $judul, $tunggal, $prefix (nama route), $izin (awalan izin, misal inventory.categories).
--}}
@php
    $baris = $items->mapWithKeys(fn ($i) => [$i->id => ['name' => $i->name, 'description' => $i->description]]);
    $state = [
        'editId' => old('_edit_id'),
        'nama' => old('name', ''),
        'deskripsi' => old('description', ''),
        'baris' => $baris,
        'storeUrl' => route($prefix.'.store'),
        'updateUrl' => route($prefix.'.update', '__ID__'),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">{{ $judul }}</x-slot>
    <x-slot name="description">{{ $judul }} yang masih dipakai produk tidak bisa dihapus.</x-slot>
    <x-slot name="actions">
        @can($izin.'.export')
            <x-ui.export-menu :href="route($prefix.'.export')" />
        @endcan
        @can($izin.'.create')
            <x-ui.button icon="plus" x-data x-on:click="$dispatch('master-tambah')">Tambah {{ $tunggal }}</x-ui.button>
        @endcan
    </x-slot>

    <div x-data="{
            ...{{ \Illuminate\Support\Js::from($state) }},
            get action() { return this.editId ? this.updateUrl.replace('__ID__', this.editId) : this.storeUrl },
            tambah() { this.editId = null; this.nama = ''; this.deskripsi = ''; this.$dispatch('open-modal', 'master') },
            ubah(id) { this.editId = id; this.nama = this.baris[id].name; this.deskripsi = this.baris[id].description ?? ''; this.$dispatch('open-modal', 'master') }
         }"
         x-on:master-tambah.window="tambah()">
        <x-ui.table :paginator="$items">
            <x-slot:toolbar>
                <x-ui.filter-bar :placeholder="'Cari nama atau deskripsi '.$tunggal" />
            </x-slot:toolbar>

            <x-slot:head>
                <x-ui.table.th sort="name">Nama</x-ui.table.th>
                <x-ui.table.th>Deskripsi</x-ui.table.th>
                <x-ui.table.th align="right" sort="products">Jumlah produk</x-ui.table.th>
                <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
            </x-slot:head>

            @forelse ($items as $i)
                <tr>
                    <x-ui.table.td><span class="font-medium text-slate-900">{{ $i->name }}</span></x-ui.table.td>
                    <x-ui.table.td muted>{{ $i->description ?: '—' }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ $i->products_count }}</x-ui.table.td>
                    <x-ui.table.td align="right">
                        <div class="flex items-center justify-end gap-1">
                            @can($izin.'.update')
                                <x-ui.button variant="ghost" size="sm" icon="pencil-square" x-on:click="ubah({{ $i->id }})" aria-label="Ubah {{ $i->name }}" />
                            @endcan
                            @can($izin.'.delete')
                                <x-ui.button variant="ghost" size="sm" icon="trash" aria-label="Hapus {{ $i->name }}" class="text-danger-600 hover:text-danger-700"
                                    x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route($prefix.'.destroy', $i)) }}, nama: {{ \Illuminate\Support\Js::from($i->name) }} })" />
                            @endcan
                        </div>
                    </x-ui.table.td>
                </tr>
            @empty
                <x-ui.table.empty colspan="4" icon="tag" :title="'Belum ada '.$tunggal.' yang cocok'" :description="'Ubah kata kunci, atau tambah '.$tunggal.' baru.'" />
            @endforelse
        </x-ui.table>

        <x-ui.modal name="master" :show="$errors->any()">
            <x-slot:title><span x-text="editId ? 'Ubah {{ $tunggal }}' : 'Tambah {{ $tunggal }}'"></span></x-slot:title>
            <form id="form-master" method="POST" x-bind:action="action" class="grid gap-4">
                @csrf
                <template x-if="editId"><input type="hidden" name="_method" value="PUT"></template>
                <input type="hidden" name="_edit_id" x-bind:value="editId ?? ''">
                <x-ui.input name="name" :label="'Nama '.$tunggal" maxlength="100" required x-model="nama" />
                <x-ui.input name="description" label="Deskripsi" maxlength="255" x-model="deskripsi" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
                <x-ui.button type="submit" form="form-master">Simpan</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</x-app-layout>
