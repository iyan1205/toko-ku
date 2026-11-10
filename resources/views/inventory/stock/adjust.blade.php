{{--
    Penyesuaian stok manual di satu gudang: penyesuaian (tambah/kurangi), barang rusak, barang hilang.
    "Stok saat ini" dihitung di browser dari $stok (produk => [gudang => jumlah]) hanya sebagai info;
    server tetap menolak bila stok gudang tidak cukup.
--}}
@php
    $awal = [
        'produk' => (string) old('product_id', $dipilih ?? ''),
        'gudang' => (string) old('warehouse_id', $gudangDipilih ?? ''),
        'jenis' => old('type', 'adjustment'),
        'arah' => old('direction', 'in'),
        'stok' => $stok,
        'satuan' => $produk->mapWithKeys(fn ($p) => [$p->id => $p->unit?->name])->all(),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">Penyesuaian stok</x-slot>
    <x-slot name="description">Koreksi stok di satu gudang. Setiap penyesuaian tercatat di kartu stok beserta alasannya.</x-slot>

    <form method="POST" action="{{ route('stock.adjust.store') }}" class="grid gap-8"
          x-data="{ ...{{ \Illuminate\Support\Js::from($awal) }},
                    get stokKini() { return Number((this.stok[this.produk] || {})[this.gudang] || 0); },
                    angka(v) { return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 3 }).format(v); } }">
        @csrf

        <x-ui.form-section title="Produk dan gudang" description="Pilih gudang tempat stok dikoreksi.">
            <x-ui.select name="warehouse_id" label="Gudang" required :options="$gudang->pluck('name', 'id')" :selected="$gudangDipilih" x-model="gudang" />
            <x-ui.select name="product_id" label="Produk" required placeholder="Pilih produk" x-model="produk">
                @foreach ($produk as $p)
                    <option value="{{ $p->id }}" @selected((string) old('product_id', $dipilih) === (string) $p->id)>{{ $p->sku }} — {{ $p->name }}</option>
                @endforeach
            </x-ui.select>
            <p class="text-sm text-slate-600" x-show="produk" x-cloak>
                Stok saat ini di gudang ini:
                <strong class="tabular-nums text-slate-900" x-text="angka(stokKini) + ' ' + (satuan[produk] || '')"></strong>
            </p>
        </x-ui.form-section>

        <x-ui.form-section title="Penyesuaian" description="Barang rusak dan hilang selalu mengurangi stok.">
            <fieldset>
                <legend class="mb-2 text-sm font-medium text-slate-700">Jenis</legend>
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach ($jenis as $j)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 p-3 text-sm has-[:checked]:border-primary-500 has-[:checked]:bg-primary-50">
                            <input type="radio" name="type" value="{{ $j->value }}" x-model="jenis" class="border-slate-300 text-primary-600 focus:ring-primary-500">
                            {{ $j->label() }}
                        </label>
                    @endforeach
                </div>
                @error('type') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
            </fieldset>

            <fieldset x-show="jenis === 'adjustment'">
                <legend class="mb-2 text-sm font-medium text-slate-700">Arah</legend>
                <div class="flex gap-2">
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-4 py-2 text-sm has-[:checked]:border-primary-500 has-[:checked]:bg-primary-50">
                        <input type="radio" name="direction" value="in" x-model="arah" class="border-slate-300 text-primary-600 focus:ring-primary-500"> Tambah stok
                    </label>
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-4 py-2 text-sm has-[:checked]:border-primary-500 has-[:checked]:bg-primary-50">
                        <input type="radio" name="direction" value="out" x-model="arah" class="border-slate-300 text-primary-600 focus:ring-primary-500"> Kurangi stok
                    </label>
                </div>
                @error('direction') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
            </fieldset>

            <x-ui.input name="quantity" label="Jumlah" type="number" min="0.001" step="0.001" required
                        hint="Gunakan desimal untuk satuan seperti KG atau LITER." />
            <x-ui.input name="note" label="Alasan" maxlength="255" required
                        hint="Contoh: stok awal, botol pecah saat bongkar muat, selisih hitungan rak." />

            <x-slot:footer>
                <x-ui.button :href="route('stock.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit">Simpan penyesuaian</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
