@php
    $baris = $items->mapWithKeys(fn ($i) => [$i->id => ['name' => $i->name, 'description' => $i->description]]);
@endphp

<x-app-layout>
    <x-slot name="header">{{ $judul }}</x-slot>

    <div x-data="{
            buka: @js($errors->any()),
            editId: @js(old('_edit_id')),
            nama: @js(old('name', '')),
            deskripsi: @js(old('description', '')),
            baris: @js($baris),
            storeUrl: @js(route($prefix . '.store')),
            updateUrl: @js(route($prefix . '.update', '__ID__')),
            get action() { return this.editId ? this.updateUrl.replace('__ID__', this.editId) : this.storeUrl },
            tambah() { this.editId = null; this.nama = ''; this.deskripsi = ''; this.buka = true },
            ubah(id) { this.editId = id; this.nama = this.baris[id].name; this.deskripsi = this.baris[id].description ?? ''; this.buka = true }
         }"
         x-on:keydown.escape.window="buka = false"
         class="kartu">

        <div class="flex flex-col gap-3 border-b border-stone-200 p-4 sm:flex-row sm:items-center sm:justify-between">
            <form method="GET" action="{{ route($prefix . '.index') }}" class="flex flex-1 gap-2">
                <input type="search" name="cari" value="{{ request('cari') }}" placeholder="Cari {{ $tunggal }}"
                       class="w-full rounded-md border-stone-300 text-sm focus:border-brand-600 focus:ring-brand-600 sm:max-w-xs">
                <button type="submit" class="tombol-kedua">Cari</button>
            </form>
            <button type="button" @click="tambah()"
                    class="tombol-utama">
                Tambah {{ $tunggal }}
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="tabel">
                <thead>
                    <tr>
                        <th class="font-medium">Nama</th>
                        <th class="font-medium">Deskripsi</th>
                        <th class="text-right font-medium">Jumlah produk</th>
                        <th class="text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $i)
                        <tr>
                            <td class="font-medium text-stone-900">{{ $i->name }}</td>
                            <td class="text-stone-600">{{ $i->description ?: '-' }}</td>
                            <td class="text-right text-stone-600">{{ $i->products_count }}</td>
                            <td >
                                <div class="flex items-center justify-end gap-3">
                                    <button type="button" class="text-brand-700 hover:underline" @click="ubah({{ $i->id }})">Ubah</button>
                                    <button type="button" class="text-brand-600 hover:underline"
                                            @click="$dispatch('konfirmasi-hapus', { action: @js(route($prefix . '.destroy', $i)), nama: @js($i->name) })">
                                        Hapus
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-10 text-center text-stone-500">
                                Belum ada {{ $tunggal }}. Klik "Tambah {{ $tunggal }}" untuk membuat yang pertama.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($items->hasPages())
            <div class="border-t border-stone-200 p-4">{{ $items->links() }}</div>
        @endif

        {{-- Modal tambah/ubah --}}
        <div x-show="buka" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/60 p-4"
             role="dialog" aria-modal="true" aria-labelledby="judul-modal">
            <div @click.outside="buka = false" class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                <h2 id="judul-modal" class="text-lg font-semibold text-stone-900"
                    x-text="editId ? 'Ubah {{ $tunggal }}' : 'Tambah {{ $tunggal }}'"></h2>

                <form method="POST" :action="action" class="mt-4 space-y-4">
                    @csrf
                    <template x-if="editId"><input type="hidden" name="_method" value="PUT"></template>
                    <input type="hidden" name="_edit_id" :value="editId ?? ''">

                    <div>
                        <label for="modal-name" class="block text-sm font-medium text-stone-700">Nama {{ $tunggal }}</label>
                        <input id="modal-name" name="name" type="text" x-model="nama" required maxlength="100"
                               class="mt-1 block w-full rounded-md border-stone-300 text-sm shadow-sm focus:border-brand-600 focus:ring-brand-600">
                        @error('name') <p class="mt-1 text-sm text-brand-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="modal-description" class="block text-sm font-medium text-stone-700">
                            Deskripsi <span class="font-normal text-stone-400">(opsional)</span>
                        </label>
                        <input id="modal-description" name="description" type="text" x-model="deskripsi" maxlength="255"
                               class="mt-1 block w-full rounded-md border-stone-300 text-sm shadow-sm focus:border-brand-600 focus:ring-brand-600">
                        @error('description') <p class="mt-1 text-sm text-brand-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="buka = false"
                                class="tombol-kedua">Batal</button>
                        <button type="submit"
                                class="tombol-utama">
                            Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
