{{--
    Dialog layar Kasir, dikendalikan state "modal" di resources/js/pos/kasir.js
    (bayar, selesai, diskon, tahan, batal, sesi). Esc menutup dialog; Enter/F8 menyelesaikan pembayaran.
    Dipakai dari pos/index.blade.php di dalam x-data="kasir(...)".
--}}
@php
    $dialog = 'relative w-full rounded-xl bg-white shadow-xl';
    $kbd = 'rounded border border-slate-300 bg-white px-1.5 py-0.5 font-mono text-[11px] font-medium text-slate-600';
    $tombolKedua = 'inline-flex h-11 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500';
    $tombolUtama = 'inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white shadow-sm hover:bg-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-50';
    $isian = 'block w-full rounded-lg border-slate-300 shadow-sm focus:border-primary-500 focus:ring-primary-500';
    $galatSesi = $errors->hasAny(['warehouse_id', 'opening_cash', 'opening_note']);
@endphp

<div x-show="modal !== null" x-cloak class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true"
     x-init="@if ($galatSesi) $nextTick(() => bukaModal('sesi')) @elseif ($sessionRequired && ! $session) $nextTick(() => bukaModal('sesi')) @endif">
    <div class="fixed inset-0 bg-slate-900/50" x-on:click="modal === 'selesai' ? transaksiBaru() : (modal !== 'sesi' || !sesiWajib || sesi) && tutupModal()" aria-hidden="true"></div>

    <div class="flex min-h-full items-end justify-center p-4 sm:items-center">

        {{-- ---------- Bayar ---------- --}}
        <div x-show="modal === 'bayar'" class="{{ $dialog }} sm:max-w-xl" aria-labelledby="judul-bayar">
            <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-4">
                <div>
                    <h2 id="judul-bayar" class="text-base font-semibold text-slate-900">Pembayaran</h2>
                    <p class="text-sm text-slate-500" x-text="(pelanggan ? pelanggan.name : 'Umum') + ' · ' + items.length + ' produk'"></p>
                </div>
                <div class="text-right">
                    <p class="text-xs text-slate-500">Total</p>
                    <p class="text-3xl font-bold tabular-nums tracking-tight text-slate-900" x-text="rp(total)"></p>
                </div>
            </div>

            <div class="grid gap-4 px-6 py-5">
                <template x-for="(b, i) in bayar" :key="i">
                    <div class="flex items-end gap-2">
                        <label class="w-36 shrink-0 text-xs font-medium text-slate-600">
                            Metode
                            <select x-model="b.method" x-on:change="gantiMetode(i)" class="{{ $isian }} mt-1 h-12 text-sm">
                                @foreach ($methods as $m)
                                    <option value="{{ $m->value }}">{{ $m->label() }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="min-w-0 flex-1 text-xs font-medium text-slate-600">
                            <span x-text="b.method === 'cash' ? 'Uang diterima (Rp)' : 'Jumlah (Rp)'"></span>
                            <input type="number" min="0" step="any" inputmode="decimal" x-model="b.amount" :id="'bayar-' + i"
                                   placeholder="0" class="{{ $isian }} mt-1 h-12 text-right text-xl font-semibold tabular-nums">
                        </label>
                        <button type="button" x-show="bayar.length > 1" x-on:click="hapusMetode(i)"
                                class="grid h-12 w-10 shrink-0 place-items-center rounded-lg text-slate-400 hover:bg-danger-50 hover:text-danger-600" aria-label="Hapus metode ini">
                            <x-ui.icon name="x-mark" variant="m" class="h-5 w-5" />
                        </button>
                    </div>
                </template>

                <div class="flex flex-wrap gap-2" x-show="bayar.some(b => b.method === 'cash') || bayar.length === 0">
                    <template x-for="v in uangCepat()" :key="v">
                        <button type="button" x-on:click="isiTunai(v)"
                                class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium tabular-nums text-slate-700 hover:bg-slate-50"
                                x-text="v === total ? 'Uang pas' : rp(v)"></button>
                    </template>
                </div>

                <button type="button" x-show="metodeTersisa.length > 0" x-on:click="tambahMetode()"
                        class="inline-flex items-center gap-1.5 self-start text-sm font-medium text-primary-700 hover:underline">
                    <x-ui.icon name="plus" variant="m" class="h-4 w-4" /> Bayar dengan metode lain (split)
                </button>

                <dl class="grid gap-1 rounded-lg bg-slate-50 p-4 text-sm tabular-nums">
                    <div class="flex justify-between"><dt class="text-slate-500">Diterima</dt><dd class="text-slate-900" x-text="rp(ringkasBayar.diterima)"></dd></div>
                    <div x-show="ringkasBayar.sisa > 0" class="flex justify-between text-base font-semibold">
                        <dt :class="piutang ? 'text-warning-700' : 'text-danger-600'" x-text="piutang ? 'Jadi piutang' : 'Kurang'"></dt>
                        <dd :class="piutang ? 'text-warning-700' : 'text-danger-600'" x-text="rp(ringkasBayar.sisa)"></dd>
                    </div>
                    <div x-show="ringkasBayar.sisa <= 0" class="flex items-end justify-between">
                        <dt class="text-base font-semibold text-slate-700">Kembalian</dt>
                        <dd class="text-3xl font-bold text-success-700" x-text="rp(ringkasBayar.kembalian)"></dd>
                    </div>
                    <p x-show="ringkasBayar.lebihNonTunai" class="text-sm text-danger-600">Pembayaran non-tunai melebihi total. Kembalian hanya bisa dari uang tunai.</p>
                </dl>

                <template x-if="piutangAktif">
                    <label class="flex items-start gap-3 rounded-lg border p-3" :class="bolehPiutang ? 'border-slate-200' : 'border-slate-100 bg-slate-50'">
                        <input type="checkbox" x-model="piutang" :disabled="!bolehPiutang" class="mt-0.5 rounded border-slate-300 text-primary-600 focus:ring-primary-500">
                        <span class="text-sm">
                            <span class="block font-medium text-slate-900">Sisa jadi piutang pelanggan</span>
                            <span class="block text-slate-500" x-text="bolehPiutang ? 'Dicatat sebagai piutang ' + pelanggan.name + '. Jumlah di atas menjadi bayar di muka (boleh 0).' : 'Pilih pelanggan terdaftar (bukan Umum) dulu dengan F3.'"></span>
                        </span>
                    </label>
                </template>

                <label class="text-xs font-medium text-slate-600">
                    Catatan (opsional)
                    <input type="text" x-model="catatan" maxlength="500" class="{{ $isian }} mt-1 text-sm" placeholder="Misal: pesanan meja 4">
                </label>

                <div x-show="galat.length > 0" class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700" role="alert">
                    <template x-for="g in galat" :key="g"><p x-text="g"></p></template>
                </div>
            </div>

            <div class="flex items-center justify-between gap-2 border-t border-slate-200 px-6 py-4">
                <button type="button" x-on:click="tutupModal()" class="{{ $tombolKedua }}">Kembali <kbd class="{{ $kbd }}">Esc</kbd></button>
                <button type="button" x-on:click="selesaikan()" :disabled="!bolehSelesai" class="{{ $tombolUtama }} h-12 px-6 text-base">
                    <span x-text="memproses ? 'Menyimpan…' : 'Selesaikan'"></span>
                    <kbd class="rounded border border-primary-400 bg-primary-500 px-1.5 py-0.5 font-mono text-xs">Enter</kbd>
                </button>
            </div>
        </div>

        {{-- ---------- Selesai ---------- --}}
        <div x-show="modal === 'selesai'" class="{{ $dialog }} p-6 text-center sm:max-w-md" aria-labelledby="judul-selesai">
            <span class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-success-100 text-success-700">
                <x-ui.icon name="check" variant="m" class="h-7 w-7" />
            </span>
            <h2 id="judul-selesai" class="mt-3 text-base font-semibold text-slate-900">Transaksi tersimpan</h2>
            <p class="text-sm text-slate-500" x-text="hasil ? hasil.number : ''"></p>
            <template x-if="hasil && hasil.change > 0">
                <div class="mt-4">
                    <p class="text-sm text-slate-500">Kembalian</p>
                    <p class="text-4xl font-bold tabular-nums text-success-700" x-text="rp(hasil.change * 100)"></p>
                </div>
            </template>
            <template x-if="hasil && hasil.balance > 0">
                <p class="mt-3 rounded-lg bg-warning-50 px-3 py-2 text-sm text-warning-800" x-text="'Sisa piutang ' + rp(hasil.balance * 100)"></p>
            </template>
            <div class="mt-6 grid gap-2">
                <template x-if="hasil && hasil.receipt_url">
                    <button type="button" id="tombol-cetak" x-on:click="cetakStruk()" class="{{ $tombolUtama }}">
                        <x-ui.icon name="printer" variant="m" class="h-5 w-5" /> Cetak struk
                    </button>
                </template>
                <button type="button" id="tombol-baru" x-on:click="transaksiBaru()" class="{{ $tombolKedua }}">
                    Transaksi baru <kbd class="{{ $kbd }}">Esc</kbd>
                </button>
                <a :href="hasil ? hasil.show_url : '#'" class="text-sm font-medium text-primary-700 hover:underline">Lihat detail transaksi</a>
            </div>
        </div>

        {{-- ---------- Diskon transaksi ---------- --}}
        <div x-show="modal === 'diskon'" class="{{ $dialog }} sm:max-w-sm" aria-labelledby="judul-diskon">
            <div class="px-6 pt-5">
                <h2 id="judul-diskon" class="text-base font-semibold text-slate-900">Diskon transaksi</h2>
                <p class="text-sm text-slate-500" x-text="'Dari total setelah diskon item ' + rp(bersih)"></p>
            </div>
            <div class="grid gap-3 px-6 py-5">
                <div class="inline-flex rounded-lg border border-slate-300 p-0.5 text-sm" role="group">
                    <button type="button" x-on:click="diskonMode = 'rp'" :class="diskonMode === 'rp' ? 'bg-slate-900 text-white' : 'text-slate-600'" class="flex-1 rounded-md px-3 py-1.5 font-medium">Rupiah</button>
                    <button type="button" x-on:click="diskonMode = 'persen'" :class="diskonMode === 'persen' ? 'bg-slate-900 text-white' : 'text-slate-600'" class="flex-1 rounded-md px-3 py-1.5 font-medium">Persen</button>
                </div>
                <input type="number" min="0" step="any" inputmode="decimal" x-model="diskonIsian"
                       x-effect="modal === 'diskon' && $nextTick(() => $el.select())"
                       :placeholder="diskonMode === 'rp' ? 'Rp' : '%'" class="{{ $isian }} h-12 text-right text-xl font-semibold tabular-nums">
                <p class="text-sm text-slate-600">Diskon: <strong class="tabular-nums" x-text="rp(diskonPratinjau)"></strong></p>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 px-6 py-4">
                <button type="button" x-on:click="diskonIsian = ''; simpanDiskon()" class="{{ $tombolKedua }}">Hapus diskon</button>
                <button type="button" x-on:click="simpanDiskon()" class="{{ $tombolUtama }}">Terapkan</button>
            </div>
        </div>

        {{-- ---------- Transaksi ditahan ---------- --}}
        <div x-show="modal === 'tahan'" class="{{ $dialog }} sm:max-w-lg" aria-labelledby="judul-tahan">
            <div class="px-6 pt-5">
                <h2 id="judul-tahan" class="text-base font-semibold text-slate-900">Transaksi ditahan</h2>
                <p class="text-sm text-slate-500" x-text="slotTahan + ' dari ' + maksTahan + ' slot tersisa'"></p>
            </div>
            <div class="max-h-80 divide-y divide-slate-100 overflow-y-auto px-6 py-3">
                <template x-for="t in daftarTahan" :key="t.id">
                    <div class="flex items-center justify-between gap-2 py-2.5">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-900" x-text="t.label"></p>
                            <p class="text-xs text-slate-500" x-text="t.customer + ' · ' + t.items + ' produk · ' + t.created_at"></p>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <button type="button" x-on:click="panggilTahan(t.id)" class="{{ $tombolUtama }} h-9 px-3">Panggil</button>
                            <button type="button" x-on:click="hapusTahan(t.id)" class="{{ $tombolKedua }} h-9 px-3 text-danger-600">Hapus</button>
                        </div>
                    </div>
                </template>
                <p x-show="daftarTahan.length === 0" class="py-8 text-center text-sm text-slate-500">Belum ada transaksi yang ditahan.</p>
            </div>
            <p x-show="items.length > 0" class="px-6 pb-2 text-xs text-warning-700">Memanggil transaksi akan mengganti isi keranjang sekarang. Tahan dulu (F9) bila masih dipakai.</p>
            <div class="flex justify-end border-t border-slate-200 px-6 py-4">
                <button type="button" x-on:click="tutupModal()" class="{{ $tombolKedua }}">Tutup <kbd class="{{ $kbd }}">Esc</kbd></button>
            </div>
        </div>

        {{-- ---------- Konfirmasi batal ---------- --}}
        <div x-show="modal === 'batal'" class="{{ $dialog }} p-6 sm:max-w-sm" aria-labelledby="judul-batal">
            <h2 id="judul-batal" class="text-base font-semibold text-slate-900">Kosongkan keranjang?</h2>
            <p class="mt-1 text-sm text-slate-600" x-text="items.length + ' produk senilai ' + rp(total) + ' akan dihapus dari keranjang. Belum ada yang tersimpan.'"></p>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" x-on:click="tutupModal()" class="{{ $tombolKedua }}">Kembali <kbd class="{{ $kbd }}">Esc</kbd></button>
                <button type="button" x-on:click="batalkan()" class="inline-flex h-11 items-center gap-2 rounded-lg bg-danger-600 px-4 text-sm font-semibold text-white hover:bg-danger-700">
                    Ya, kosongkan <kbd class="rounded border border-danger-400 bg-danger-500 px-1.5 py-0.5 font-mono text-xs">Enter</kbd>
                </button>
            </div>
        </div>

        {{-- ---------- Buka sesi ---------- --}}
        <div x-show="modal === 'sesi'" class="{{ $dialog }} sm:max-w-md" aria-labelledby="judul-sesi">
            <div class="px-6 pt-5">
                <h2 id="judul-sesi" class="text-base font-semibold text-slate-900">Buka sesi kasir</h2>
                <p class="text-sm text-slate-500">
                    @if ($sessionRequired)
                        Sesi kasir wajib dibuka sebelum menjual.
                    @endif
                    Hitung uang di laci dulu, lalu isi sebagai modal awal.
                </p>
            </div>
            @can('pos.sessions.create')
                <form method="POST" action="{{ route('pos.sessions.store') }}" class="grid gap-4 px-6 py-5">
                    @csrf
                    <input type="hidden" name="_from" value="pos">
                    <x-ui.select name="warehouse_id" label="Gudang penjualan" required :options="$warehouses->pluck('name', 'id')" :selected="old('warehouse_id', $warehouse->id)"
                                 hint="Stok yang dijual selama sesi ini keluar dari gudang ini." />
                    <x-ui.input name="opening_cash" label="Modal awal" type="number" prefix="Rp" min="0" step="any" inputmode="decimal" :value="old('opening_cash')" placeholder="0" />
                    <x-ui.input name="opening_note" label="Catatan" maxlength="255" :value="old('opening_note')" placeholder="Opsional" />
                    <div class="flex justify-end gap-2">
                        <button type="button" x-show="!sesiWajib" x-on:click="tutupModal()" class="{{ $tombolKedua }}">Nanti saja</button>
                        <a href="{{ route('dashboard') }}" x-show="sesiWajib" class="{{ $tombolKedua }}">Ke dashboard</a>
                        <button type="submit" class="{{ $tombolUtama }}">Buka sesi</button>
                    </div>
                </form>
            @else
                <div class="px-6 py-5">
                    <p class="text-sm text-slate-600">Anda belum punya izin membuka sesi kasir. Minta atasan memberi izin "Sesi/shift kasir: Tambah".</p>
                    <div class="mt-4 flex justify-end">
                        <a href="{{ route('dashboard') }}" class="{{ $tombolKedua }}">Ke dashboard</a>
                    </div>
                </div>
            @endcan
        </div>
    </div>
</div>
