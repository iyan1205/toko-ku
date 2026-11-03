<x-app-layout>
    <x-slot name="header">Impor produk dari Excel</x-slot>

    <div class="max-w-3xl space-y-6">
        @if (session('import_errors'))
            @php($daftar = session('import_errors'))
            <div class="rounded-lg border border-brand-200 bg-brand-50 p-5" role="alert">
                <h2 class="font-semibold text-brand-900">Impor dibatalkan, tidak ada data yang disimpan</h2>
                <p class="mt-1 text-sm text-brand-800">Perbaiki baris berikut di berkas Anda, lalu unggah ulang.</p>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-brand-900">
                    @foreach (array_slice($daftar, 0, 50) as $pesan)
                        <li>{{ $pesan }}</li>
                    @endforeach
                </ul>
                @if (count($daftar) > 50)
                    <p class="mt-2 text-sm text-brand-800">Dan {{ count($daftar) - 50 }} kesalahan lainnya.</p>
                @endif
            </div>
        @endif

        <form method="POST" action="{{ route('products.import.store') }}" enctype="multipart/form-data"
              class="space-y-4 rounded-lg border border-stone-200 bg-white p-6">
            @csrf
            <div>
                <label for="file" class="block text-sm font-medium text-stone-700">Berkas Excel atau CSV</label>
                <input id="file" name="file" type="file" required accept=".xlsx,.xls,.csv"
                       class="mt-2 block w-full text-sm text-stone-600 file:mr-3 file:rounded-md file:border-0 file:bg-stone-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-stone-700 hover:file:bg-stone-200">
                <p class="mt-1 text-xs text-stone-500">XLSX, XLS, atau CSV. Maksimal 5 MB dan {{ \App\Services\ProductImportService::MAKS_BARIS }} baris.</p>
                @error('file') <p class="mt-1 text-sm text-brand-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex flex-wrap justify-between gap-2">
                <a href="{{ route('products.template') }}" class="tombol-kedua">Unduh templat</a>
                <div class="flex gap-2">
                    <a href="{{ route('products.index') }}" class="tombol-kedua">Batal</a>
                    <button type="submit"
                            class="tombol-utama">Impor produk</button>
                </div>
            </div>
        </form>

        <div class="kartu p-6 text-sm text-stone-700">
            <h2 class="font-semibold text-stone-900">Aturan impor</h2>
            <ul class="mt-3 list-disc space-y-2 pl-5">
                <li>Baris pertama berisi judul kolom persis seperti templat. Kolom wajib: <strong>Nama Produk, Kategori, Satuan, Harga Beli, Harga Jual</strong>.</li>
                <li>SKU yang sudah ada akan <strong>memperbarui</strong> produk tersebut. SKU kosong atau baru akan <strong>membuat</strong> produk baru (SKU kosong dibuat otomatis).</li>
                <li>Kategori dan satuan harus sudah terdaftar. Penulisan tidak membedakan huruf besar atau kecil.</li>
                <li>Harga ditulis angka biasa, misalnya <code>12500</code>, tanpa "Rp" dan tanpa titik pemisah ribuan.</li>
                <li>Status diisi "Aktif" atau "Nonaktif"; kosong dianggap Aktif.</li>
                <li>Kolom <strong>Stok diabaikan</strong>. Stok hanya berubah lewat Pembelian atau Stok Opname agar tercatat di riwayat.</li>
                <li>Bila ada satu baris yang salah, seluruh impor dibatalkan dan tidak ada data yang tersimpan.</li>
            </ul>
        </div>
    </div>
</x-app-layout>
