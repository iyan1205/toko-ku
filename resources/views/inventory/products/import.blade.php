{{-- Impor produk dari Excel/CSV. Satu baris salah = seluruh impor dibatalkan. --}}
@php
    $daftar = session('import_errors', []);
@endphp

<x-app-layout>
    <x-slot name="header">Impor produk</x-slot>
    <x-slot name="description">Tambah atau perbarui banyak produk sekaligus dari berkas Excel atau CSV.</x-slot>
    <x-slot name="actions">
        <x-ui.button variant="secondary" :href="route('products.template')" icon="arrow-down-tray">Unduh templat</x-ui.button>
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="grid gap-6 lg:col-span-2">
            @if ($daftar)
                <x-ui.alert type="danger" title="Impor dibatalkan, tidak ada data yang disimpan">
                    <p>Perbaiki baris berikut di berkas Anda, lalu unggah ulang.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach (array_slice($daftar, 0, 50) as $pesan)
                            <li>{{ $pesan }}</li>
                        @endforeach
                    </ul>
                    @if (count($daftar) > 50)
                        <p class="mt-2">Dan {{ count($daftar) - 50 }} kesalahan lainnya.</p>
                    @endif
                </x-ui.alert>
            @endif

            <x-ui.card>
                <form method="POST" action="{{ route('products.import.store') }}" enctype="multipart/form-data" class="grid gap-4">
                    @csrf
                    <div>
                        <label for="file" class="block text-sm font-medium text-slate-700">Berkas Excel atau CSV <span class="text-danger-600">*</span></label>
                        <input id="file" name="file" type="file" required accept=".xlsx,.xls,.csv"
                               class="mt-1 block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200">
                        <p class="mt-1 text-xs text-slate-500">XLSX, XLS, atau CSV. Maksimal 5 MB dan {{ \App\Services\ProductImportService::MAKS_BARIS }} baris.</p>
                        @error('file') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                        <x-ui.button :href="route('products.index')" variant="secondary">Batal</x-ui.button>
                        <x-ui.button type="submit" icon="arrow-up-tray">Impor produk</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        </div>

        <x-ui.card title="Aturan impor">
            <ul class="list-disc space-y-2 pl-5 text-sm text-slate-700">
                <li>Baris pertama berisi judul kolom persis seperti templat. Kolom wajib: <strong>Nama Produk, Kategori, Satuan, Harga Beli, Harga Jual</strong>.</li>
                <li>SKU yang sudah ada akan <strong>memperbarui</strong> produk tersebut. SKU kosong atau baru akan <strong>membuat</strong> produk baru.</li>
                <li>Kategori dan satuan harus sudah terdaftar (huruf besar/kecil tidak dibedakan).</li>
                <li>Harga ditulis angka biasa, misalnya <code>12500</code>, tanpa "Rp" dan tanpa titik ribuan.</li>
                <li>Status diisi "Aktif" atau "Nonaktif"; kosong dianggap Aktif.</li>
                <li>Kolom <strong>Stok diabaikan</strong>. Stok hanya berubah lewat pembelian, transfer, penyesuaian, atau opname agar tercatat di kartu stok.</li>
                <li>Bila ada satu baris yang salah, seluruh impor dibatalkan.</li>
            </ul>
        </x-ui.card>
    </div>
</x-app-layout>
