{{--
    Hitung stok fisik di satu gudang (opname baru atau ubah draft).
    Stok sistem = stok gudang saat halaman dibuka; dikirim ulang agar server bisa menolak bila stok
    bergeser sebelum disimpan. Produk dengan stok fisik kosong dianggap tidak dihitung.
--}}
@use('App\Support\Format')
@php
    $edit = $opname->exists;
    $bolehSelesai = auth()->user()->can('inventory.opnames.approve');
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah draft '.$opname->number : 'Stok opname baru' }}</x-slot>
    <x-slot name="description">Gudang {{ $gudang->name }}. Kosongkan stok fisik untuk produk yang tidak dihitung.</x-slot>

    <div class="grid gap-6">
        {{-- Filter hanya memilih produk yang ditampilkan; opname boleh per kategori atau per rak. --}}
        <x-ui.card>
            <form method="GET" action="{{ $edit ? route('stock-opnames.edit', $opname) : route('stock-opnames.create') }}" class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                @unless ($edit)
                    <x-ui.select name="gudang" label="Gudang" :options="$pilihanGudang->pluck('name', 'id')" :selected="$gudang->id" class="sm:w-56" />
                @endunless
                <x-ui.input name="cari" label="Cari produk" :value="request('cari')" placeholder="Nama, SKU, atau barcode" class="sm:w-64" />
                <x-ui.select name="kategori" label="Kategori" :options="$kategori->pluck('name', 'id')" :selected="request('kategori')" placeholder="Semua kategori" class="sm:w-56" />
                <x-ui.button type="submit" variant="secondary" icon="funnel">Tampilkan produk</x-ui.button>
            </form>
            @if ($edit)
                <p class="mt-3 text-xs text-slate-500">Produk yang sudah dihitung di draft ini selalu ditampilkan, walau tidak cocok dengan filter.</p>
            @endif
        </x-ui.card>

        @if ($terpotong)
            <x-ui.alert type="warning">Hanya {{ $batas }} produk pertama yang ditampilkan. Gunakan filter kategori atau pencarian untuk opname per bagian.</x-ui.alert>
        @endif

        <form method="POST" action="{{ $edit ? route('stock-opnames.update', $opname) : route('stock-opnames.store') }}" x-data="{ kirim: false }" x-on:submit="kirim = true">
            @csrf
            {{-- Diisi tombol yang diklik: draft = simpan saja, final = simpan lalu bukukan selisih. --}}
            <input type="hidden" name="aksi" value="draft" x-ref="aksi">
            @if ($edit)
                @method('PUT')
            @else
                <input type="hidden" name="warehouse_id" value="{{ $gudang->id }}">
            @endif

            <x-ui.table :sticky="true">
                <x-slot:toolbar>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-ui.input name="opname_date" label="Tanggal opname" type="date" :value="$opname->opname_date?->format('Y-m-d')" :max="now()->format('Y-m-d')" required />
                        <x-ui.input name="note" label="Catatan" :value="$opname->note" maxlength="255" placeholder="Contoh: opname rak minuman, akhir bulan" class="sm:col-span-2" />
                    </div>
                    @error('items') <p class="mt-3 text-sm text-danger-600">{{ $message }}</p> @enderror
                </x-slot:toolbar>

                <x-slot:head>
                    <x-ui.table.th>Produk</x-ui.table.th>
                    <x-ui.table.th align="right">Stok sistem</x-ui.table.th>
                    <x-ui.table.th class="w-36">Stok fisik</x-ui.table.th>
                    <x-ui.table.th align="right">Selisih</x-ui.table.th>
                    <x-ui.table.th>Alasan selisih</x-ui.table.th>
                </x-slot:head>

                @forelse ($produk as $p)
                    @php
                        $sistem = $stokGudang[$p->id] ?? 0.0;
                        $hitung = $hitungan[$p->id] ?? null;
                        $bergeser = $hitung && abs((float) $hitung->system_stock - $sistem) > 0.0005;
                        $fisikAwal = (string) old('items.'.$p->id.'.physical_stock', $hitung ? (float) $hitung->physical_stock : '');
                    @endphp
                    <tr x-data="{
                            sistem: {{ $sistem }},
                            fisik: {{ \Illuminate\Support\Js::from($fisikAwal) }},
                            get selisih() { return this.fisik === '' || this.fisik === null ? null : Math.round((Number(this.fisik) - this.sistem) * 1000) / 1000 }
                        }"
                        x-bind:class="selisih !== null && selisih !== 0 ? '!bg-warning-50/60' : ''">
                        <x-ui.table.td>
                            <p class="font-medium text-slate-900">{{ $p->name }}</p>
                            <p class="text-xs text-slate-500">{{ $p->sku }} · {{ $p->category?->name }}</p>
                            @if ($bergeser)
                                <p class="mt-1 text-xs font-medium text-warning-700">Stok berubah sejak dihitung ({{ Format::jumlah($hitung->system_stock) }} → {{ Format::jumlah($sistem) }}). Hitung ulang.</p>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td align="right" numeric>
                            {{ Format::jumlah($sistem) }} <span class="text-slate-500">{{ $p->unit?->name }}</span>
                            <input type="hidden" name="items[{{ $p->id }}][system_stock]" value="{{ $sistem }}">
                        </x-ui.table.td>
                        <x-ui.table.td>
                            <input type="number" min="0" step="0.001" x-model="fisik" name="items[{{ $p->id }}][physical_stock]" aria-label="Stok fisik {{ $p->name }}"
                                   class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            @error('items.'.$p->id.'.physical_stock') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                        </x-ui.table.td>
                        <x-ui.table.td align="right" numeric>
                            <span x-show="selisih === null" class="text-slate-400">—</span>
                            <span x-show="selisih === 0" x-cloak class="text-success-700">Cocok</span>
                            <span x-show="selisih !== null && selisih !== 0" x-cloak x-bind:class="selisih > 0 ? 'font-semibold text-success-700' : 'font-semibold text-danger-600'"
                                  x-text="(selisih > 0 ? '+' : '') + selisih.toLocaleString('id-ID')"></span>
                        </x-ui.table.td>
                        <x-ui.table.td>
                            <input type="text" maxlength="255" name="items[{{ $p->id }}][reason]" value="{{ old('items.'.$p->id.'.reason', $hitung?->reason) }}"
                                   placeholder="Wajib bila ada selisih" aria-label="Alasan selisih {{ $p->name }}"
                                   class="block w-full rounded-lg border-slate-300 text-sm shadow-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500">
                            @error('items.'.$p->id.'.reason') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                        </x-ui.table.td>
                    </tr>
                @empty
                    <x-ui.table.empty colspan="5" icon="cube" title="Tidak ada produk aktif yang cocok" description="Ubah filter produk." />
                @endforelse

                <x-slot:footer>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-slate-500">
                            Draft bisa dilanjutkan nanti. {{ $bolehSelesai ? 'Selesaikan untuk membukukan selisih ke stok.' : 'Opname diselesaikan oleh pengguna yang berwenang.' }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button :href="$edit ? route('stock-opnames.show', $opname) : route('stock-opnames.index')" variant="secondary">Batal</x-ui.button>
                            <x-ui.button type="submit" variant="secondary" icon="document" x-on:click="$refs.aksi.value = 'draft'" x-bind:disabled="kirim">Simpan draft</x-ui.button>
                            @if ($bolehSelesai)
                                <x-ui.button type="submit" icon="check" x-on:click="$refs.aksi.value = 'final'" x-bind:disabled="kirim">Simpan &amp; selesaikan</x-ui.button>
                            @endif
                        </div>
                    </div>
                </x-slot:footer>
            </x-ui.table>
        </form>
    </div>
</x-app-layout>
