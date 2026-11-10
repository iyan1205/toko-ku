{{--
    Layar Kasir (layout pos). Kiri: cari/scan produk + grid kategori. Kanan: keranjang, diskon, pajak,
    total, dan tombol bayar. Logika ada di resources/js/pos/kasir.js (Alpine "kasir").
    Dioptimalkan untuk tablet 1024px (lg) dan desktop; scanner barcode = ketik + Enter di kotak cari.
--}}
@use('App\Models\HeldSale')
@php
    $user = auth()->user();
    $awal = [
        'urlCariProduk' => route('pos.products'),
        'urlCariPelanggan' => route('pos.customers'),
        'urlTahan' => route('pos.hold.store'),
        'urlDaftarTahan' => route('pos.hold.index'),
        'urlSimpanJual' => route('sales.store'),
        'urlPanggilTahanTemplate' => route('pos.hold.show', '__ID__'),
        'urlHapusTahanTemplate' => route('pos.hold.destroy', '__ID__'),
        'csrf' => csrf_token(),
        'stokMinus' => $stokMinus,
        // Metode pertama yang aktif di Pengaturan → Metode Pembayaran (selain Piutang).
        'metodeAwal' => ($methods[0] ?? \App\Enums\PaymentMethod::Cash)->value,
        'metode' => array_map(fn ($m) => ['value' => $m->value, 'label' => $m->label()], $methods),
        'piutangAktif' => $receivableEnabled,
        'pajak' => [
            'enabled' => $tax['enabled'] && $tax['rate'] > 0,
            'name' => $tax['name'],
            'rate' => $tax['rate'],
            'rateBp' => (int) round($tax['rate'] * 100),
            'inclusive' => $tax['inclusive'],
        ],
        'sesi' => $session ? ['id' => $session->id, 'number' => $session->number] : null,
        'sesiWajib' => $sessionRequired,
        'gudang' => $warehouse->name,
        'maksTahan' => HeldSale::MAKS_PER_KASIR,
        'bolehCetak' => $user->can('pos.transactions.print'),
    ];
    $kbd = 'rounded border border-slate-300 bg-white px-1.5 py-0.5 font-mono text-[11px] font-medium text-slate-600 shadow-sm';
    $panel = 'rounded-xl border border-slate-200 bg-white shadow-sm';
@endphp

<x-pos-layout>
    <x-slot name="header">Kasir</x-slot>

    <x-slot name="toolbar">
        @if ($session)
            <a href="{{ route('pos.sessions.show', $session) }}"
               class="inline-flex min-w-0 items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
               title="Lihat rekap atau tutup sesi">
                <span class="h-2 w-2 shrink-0 rounded-full bg-success-500" aria-hidden="true"></span>
                <span class="min-w-0">
                    <span class="block truncate font-medium leading-tight text-slate-900">Sesi {{ $session->number }}</span>
                    <span class="block truncate text-xs leading-tight text-slate-500">{{ $warehouse->name }} · sejak {{ $session->opened_at->format('H:i') }}</span>
                </span>
            </a>
        @else
            <span class="hidden min-w-0 text-sm sm:block">
                <span class="block truncate font-medium leading-tight text-slate-700">Tanpa sesi</span>
                <span class="block truncate text-xs leading-tight text-slate-500">{{ $warehouse->name }}</span>
            </span>
            @can('pos.sessions.create')
                <x-ui.button size="sm" variant="secondary" icon="play" x-data x-on:click="window.dispatchEvent(new CustomEvent('pos-buka-sesi'))">Buka sesi</x-ui.button>
            @endcan
        @endif
        @canany(['pos.transactions.view', 'pos.transactions.view-own'])
            <x-ui.button size="sm" variant="ghost" icon="clock" :href="route('sales.index')" aria-label="Riwayat transaksi" title="Riwayat transaksi" />
        @endcanany
    </x-slot>

    <div x-data="kasir(@js($awal))" x-on:keydown.window="shortcut($event)"
         class="grid gap-3 lg:h-full lg:grid-cols-12 lg:gap-4">

        {{-- ================= Kiri: cari, kategori, produk ================= --}}
        <section class="flex min-h-0 flex-col gap-3 lg:col-span-7 xl:col-span-8" aria-label="Produk">
            <div class="{{ $panel }} p-3">
                <div class="flex items-center gap-2">
                    <div class="relative flex-1">
                        <x-ui.icon name="magnifying-glass" variant="m" class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
                        <label for="cari-produk" class="sr-only">Cari produk atau scan barcode</label>
                        <input id="cari-produk" x-ref="cariProduk" type="search" x-model="q" autocomplete="off" enterkeyhint="search"
                               placeholder="Scan barcode, atau ketik nama/SKU produk"
                               x-on:input.debounce.250ms="cariProduk()" x-on:keydown.enter.prevent="enterProduk()"
                               class="block h-12 w-full rounded-lg border-slate-300 pl-10 pr-14 text-base shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <kbd class="{{ $kbd }} absolute right-3 top-1/2 -translate-y-1/2">F2</kbd>
                    </div>
                    <button type="button" x-on:click="bukaTahan()"
                            class="relative inline-flex h-12 shrink-0 items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                        <x-ui.icon name="pause-circle" variant="m" class="h-5 w-5 text-slate-500" />
                        <span class="hidden sm:inline">Ditahan</span>
                        <kbd class="{{ $kbd }} hidden xl:inline">F7</kbd>
                        <span x-show="daftarTahan.length > 0" x-cloak x-text="daftarTahan.length"
                              class="absolute -right-2 -top-2 grid h-5 min-w-5 place-items-center rounded-full bg-warning-400 px-1 text-xs font-bold text-slate-900"></span>
                    </button>
                </div>

                @if ($categories->isNotEmpty())
                    <div class="-mx-1 mt-3 flex gap-2 overflow-x-auto px-1 pb-1" role="group" aria-label="Kategori">
                        <button type="button" x-on:click="kategori = null; cariProduk(); fokusCari()"
                                :class="kategori === null ? 'border-primary-600 bg-primary-600 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'"
                                class="shrink-0 rounded-full border px-3 py-1.5 text-sm font-medium transition-colors">Semua</button>
                        @foreach ($categories as $c)
                            <button type="button" x-on:click="pilihKategori({{ $c->id }})"
                                    :class="kategori === {{ $c->id }} ? 'border-primary-600 bg-primary-600 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'"
                                    class="shrink-0 rounded-full border px-3 py-1.5 text-sm font-medium transition-colors">{{ $c->name }}</button>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="min-h-[16rem] flex-1 overflow-y-auto rounded-xl lg:min-h-0" aria-live="polite">
                {{-- Belum mencari & belum memilih kategori: grid kategori --}}
                <div x-show="q.trim() === '' && kategori === null" class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                    @forelse ($categories as $c)
                        <button type="button" x-on:click="pilihKategori({{ $c->id }})"
                                class="{{ $panel }} flex min-h-[5.5rem] flex-col justify-between p-4 text-left transition-colors hover:border-primary-300 hover:bg-primary-50/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                            <span class="text-sm font-semibold text-slate-900">{{ $c->name }}</span>
                            <span class="text-xs text-slate-500">{{ $c->products_count }} produk</span>
                        </button>
                    @empty
                        <p class="col-span-full py-10 text-center text-sm text-slate-500">Scan barcode atau ketik nama produk untuk mulai.</p>
                    @endforelse
                </div>

                {{-- Hasil pencarian / isi kategori --}}
                <div x-show="q.trim() !== '' || kategori !== null" x-cloak class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                    <template x-for="p in hasilProduk" :key="p.id">
                        <button type="button" x-on:click="tambah(p)" :disabled="!stokMinus && p.stock <= 0"
                                class="{{ $panel }} flex flex-col overflow-hidden text-left transition-colors hover:border-primary-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:cursor-not-allowed disabled:opacity-50">
                            <span class="relative block aspect-[4/3] w-full bg-slate-100">
                                <template x-if="p.photo"><img :src="p.photo" alt="" class="h-full w-full object-cover" loading="lazy"></template>
                                <template x-if="!p.photo"><span class="grid h-full w-full place-items-center text-2xl font-semibold text-slate-300" x-text="p.name.charAt(0).toUpperCase()"></span></template>
                                <span x-show="p.discount_percent > 0" class="absolute left-2 top-2 rounded-md bg-danger-600 px-1.5 py-0.5 text-xs font-semibold text-white" x-text="'-' + angka(p.discount_percent) + '%'"></span>
                            </span>
                            <span class="flex flex-1 flex-col p-3">
                                <span class="line-clamp-2 text-sm font-medium text-slate-900" x-text="p.name"></span>
                                <span class="mt-auto pt-2 text-sm font-semibold tabular-nums text-primary-700" x-text="rp(hargaSen(p))"></span>
                                <span class="text-xs tabular-nums" :class="p.stock <= 0 ? 'font-medium text-danger-600' : 'text-slate-500'"
                                      x-text="(p.stock <= 0 ? 'Habis' : 'Stok ' + angka(p.stock)) + ' ' + (p.unit ?? '')"></span>
                            </span>
                        </button>
                    </template>
                    <p x-show="memuatProduk && hasilProduk.length === 0" class="col-span-full py-10 text-center text-sm text-slate-500">Mencari…</p>
                    <p x-show="!memuatProduk && hasilProduk.length === 0" class="col-span-full py-10 text-center text-sm text-slate-500">
                        Produk tidak ditemukan. Periksa ejaan atau scan ulang barcode.
                    </p>
                </div>
            </div>

            <p class="hidden flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500 lg:flex" aria-label="Pintasan keyboard">
                <span><kbd class="{{ $kbd }}">F2</kbd> Cari</span>
                <span><kbd class="{{ $kbd }}">F3</kbd> Pelanggan</span>
                <span><kbd class="{{ $kbd }}">F4</kbd> Diskon</span>
                <span><kbd class="{{ $kbd }}">F7</kbd> Ditahan</span>
                <span><kbd class="{{ $kbd }}">F8</kbd> Bayar</span>
                <span><kbd class="{{ $kbd }}">F9</kbd> Tahan</span>
                <span><kbd class="{{ $kbd }}">Esc</kbd> Batal</span>
            </p>
        </section>

        {{-- ================= Kanan: keranjang & total ================= --}}
        <section class="{{ $panel }} flex min-h-0 flex-col lg:col-span-5 xl:col-span-4" aria-label="Keranjang">
            {{-- Pelanggan --}}
            <div class="relative border-b border-slate-200 p-3" x-on:click.outside="bukaPelanggan = false">
                <button type="button" x-on:click="bukaPelanggan ? bukaPelanggan = false : bukaCariPelanggan()"
                        class="flex w-full items-center gap-3 rounded-lg px-2 py-1.5 text-left hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-100 text-slate-500">
                        <x-ui.icon name="user" variant="m" class="h-5 w-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-xs text-slate-500">Pelanggan <kbd class="{{ $kbd }} ml-1">F3</kbd></span>
                        <span class="block truncate text-sm font-medium text-slate-900" x-text="pelanggan ? pelanggan.name : 'Umum'"></span>
                    </span>
                    <span x-show="pelanggan && pelanggan.receivable_balance > 0" x-cloak
                          class="shrink-0 rounded-md bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-800" x-text="'Piutang ' + rp(pelanggan ? pelanggan.receivable_balance * 100 : 0)"></span>
                    <x-ui.icon name="chevron-down" variant="m" class="h-4 w-4 shrink-0 text-slate-400" />
                </button>

                <div x-show="bukaPelanggan" x-cloak class="absolute inset-x-3 top-full z-20 mt-1 rounded-lg border border-slate-200 bg-white p-2 shadow-lg">
                    <label for="cari-pelanggan" class="sr-only">Cari pelanggan</label>
                    <input id="cari-pelanggan" x-ref="cariPelanggan" type="search" x-model="qPelanggan" autocomplete="off" placeholder="Cari nama, kode, atau nomor HP"
                           x-on:input.debounce.250ms="cariPelanggan()" x-on:keydown.enter.prevent="hasilPelanggan[0] && pilihPelanggan(hasilPelanggan[0])"
                           class="block w-full rounded-md border-slate-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                    <ul class="mt-1 max-h-60 overflow-y-auto">
                        <template x-for="c in hasilPelanggan" :key="c.id">
                            <li>
                                <button type="button" x-on:click="pilihPelanggan(c)" class="flex w-full items-center justify-between gap-2 rounded-md px-2 py-2 text-left text-sm hover:bg-primary-50">
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-slate-900" x-text="c.name"></span>
                                        <span class="block truncate text-xs text-slate-500" x-text="c.code + (c.phone ? ' · ' + c.phone : '')"></span>
                                    </span>
                                    <span x-show="c.receivable_balance > 0" class="shrink-0 text-xs text-warning-700" x-text="rp(c.receivable_balance * 100)"></span>
                                </button>
                            </li>
                        </template>
                        <li x-show="hasilPelanggan.length === 0" class="px-2 py-3 text-sm text-slate-500">Pelanggan tidak ditemukan.</li>
                    </ul>
                    @can('pos.customers.create')
                        <a href="{{ route('customers.create') }}" target="_blank" rel="noopener" class="mt-1 block rounded-md px-2 py-2 text-sm font-medium text-primary-700 hover:bg-primary-50">+ Pelanggan baru (tab baru)</a>
                    @endcan
                </div>
            </div>

            {{-- Isi keranjang --}}
            <div class="min-h-[12rem] flex-1 overflow-y-auto lg:min-h-0">
                <ul class="divide-y divide-slate-100">
                    <template x-for="(it, i) in items" :key="it.product_id">
                        <li class="px-3 py-2.5">
                            <div class="flex items-start gap-2">
                                <button type="button" x-on:click="bukaBaris = bukaBaris === i ? null : i" class="min-w-0 flex-1 text-left" :aria-expanded="bukaBaris === i">
                                    <span class="block truncate text-sm font-medium text-slate-900" x-text="it.name"></span>
                                    <span class="block text-xs tabular-nums text-slate-500">
                                        <span x-text="rp(it.unit_price_sen) + ' / ' + (it.unit ?? '')"></span>
                                        <span x-show="diskonBaris(it) > 0" class="text-danger-600" x-text="' · diskon ' + rp(diskonBaris(it))"></span>
                                    </span>
                                </button>
                                <div class="flex shrink-0 items-center">
                                    <button type="button" x-on:click="ubahJumlah(i, -1)" class="grid h-9 w-9 place-items-center rounded-l-lg border border-slate-300 text-slate-600 hover:bg-slate-100" :aria-label="'Kurangi ' + it.name">
                                        <x-ui.icon name="minus" variant="m" class="h-4 w-4" />
                                    </button>
                                    <input type="number" min="0.001" step="any" inputmode="decimal" x-model="it.quantity" x-on:change="batasiJumlah(i)"
                                           :aria-label="'Jumlah ' + it.name"
                                           class="h-9 w-14 border-x-0 border-slate-300 p-0 text-center text-sm tabular-nums focus:border-primary-500 focus:ring-primary-500">
                                    <button type="button" x-on:click="ubahJumlah(i, 1)" class="grid h-9 w-9 place-items-center rounded-r-lg border border-slate-300 text-slate-600 hover:bg-slate-100" :aria-label="'Tambah ' + it.name">
                                        <x-ui.icon name="plus" variant="m" class="h-4 w-4" />
                                    </button>
                                </div>
                                <p class="w-24 shrink-0 pt-2 text-right text-sm font-semibold tabular-nums text-slate-900" x-text="rp(totalBaris(it))"></p>
                            </div>
                            <div x-show="bukaBaris === i" x-cloak class="mt-2 flex flex-wrap items-end gap-2 rounded-lg bg-slate-50 p-2">
                                <label class="text-xs text-slate-600">
                                    Diskon baris (Rp)
                                    <input type="number" min="0" step="any" inputmode="decimal" x-model="it.discount"
                                           class="mt-1 block w-32 rounded-md border-slate-300 text-sm tabular-nums focus:border-primary-500 focus:ring-primary-500">
                                </label>
                                <button type="button" x-on:click="hapus(i)" class="ml-auto inline-flex items-center gap-1 rounded-md px-2 py-1.5 text-sm font-medium text-danger-600 hover:bg-danger-50">
                                    <x-ui.icon name="trash" variant="m" class="h-4 w-4" /> Hapus
                                </button>
                            </div>
                        </li>
                    </template>
                </ul>
                <div x-show="items.length === 0" class="flex h-full flex-col items-center justify-center px-6 py-10 text-center">
                    <x-ui.icon name="shopping-cart" class="h-10 w-10 text-slate-300" />
                    <p class="mt-2 text-sm font-medium text-slate-700">Keranjang kosong</p>
                    <p class="text-sm text-slate-500">Scan barcode atau pilih produk di sebelah kiri.</p>
                </div>
            </div>

            <p x-show="pesan" x-cloak x-text="pesan" role="status" class="mx-3 mb-2 rounded-lg bg-warning-50 px-3 py-2 text-sm text-warning-800"></p>

            {{-- Total --}}
            <div class="border-t border-slate-200 p-3">
                <dl class="grid gap-1 text-sm tabular-nums">
                    <div class="flex justify-between"><dt class="text-slate-500">Subtotal <span class="text-slate-400" x-text="'(' + angka(jumlahBarang) + ' barang)'"></span></dt><dd class="text-slate-900" x-text="rp(kotor)"></dd></div>
                    <div x-show="diskonItem > 0" x-cloak class="flex justify-between"><dt class="text-slate-500">Diskon item</dt><dd class="text-danger-600" x-text="'− ' + rp(diskonItem)"></dd></div>
                    <div class="flex items-center justify-between">
                        <dt>
                            <button type="button" x-on:click="bukaDiskon()" :disabled="items.length === 0" class="text-slate-500 underline decoration-dotted underline-offset-2 hover:text-primary-700 disabled:no-underline">
                                Diskon transaksi <kbd class="{{ $kbd }} ml-1">F4</kbd>
                            </button>
                        </dt>
                        <dd :class="diskonSen > 0 ? 'text-danger-600' : 'text-slate-400'" x-text="diskonSen > 0 ? '− ' + rp(diskonSen) : '—'"></dd>
                    </div>
                    <template x-if="pajak.enabled">
                        <div class="flex justify-between">
                            <dt class="text-slate-500" x-text="pajak.name + ' ' + angka(pajak.rate) + '%' + (pajak.inclusive ? ' (termasuk)' : '')"></dt>
                            <dd :class="pajak.inclusive ? 'text-slate-400' : 'text-slate-900'" x-text="(pajak.inclusive ? '' : '+ ') + rp(pajakSen)"></dd>
                        </div>
                    </template>
                </dl>
                <div class="mt-2 flex items-end justify-between border-t border-dashed border-slate-200 pt-2">
                    <span class="text-sm font-medium text-slate-600">Total</span>
                    <span class="text-3xl font-bold tabular-nums tracking-tight text-slate-900" x-text="rp(total)"></span>
                </div>

                <div class="mt-3 grid grid-cols-2 gap-2">
                    <button type="button" x-on:click="tahan()" :disabled="items.length === 0"
                            class="inline-flex h-11 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50">
                        <x-ui.icon name="pause" variant="m" class="h-4 w-4" /> Tahan <kbd class="{{ $kbd }}">F9</kbd>
                    </button>
                    <button type="button" x-on:click="konfirmasiBatal()" :disabled="items.length === 0"
                            class="inline-flex h-11 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white text-sm font-medium text-danger-600 hover:bg-danger-50 disabled:opacity-50">
                        <x-ui.icon name="x-mark" variant="m" class="h-4 w-4" /> Batal <kbd class="{{ $kbd }}">Esc</kbd>
                    </button>
                </div>
                <button type="button" x-on:click="bukaBayar()" :disabled="items.length === 0"
                        class="mt-2 inline-flex h-14 w-full items-center justify-center gap-3 rounded-lg bg-primary-600 text-lg font-semibold text-white shadow-sm hover:bg-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-50">
                    Bayar <span class="tabular-nums" x-text="rp(total)"></span>
                    <kbd class="rounded border border-primary-400 bg-primary-500 px-1.5 py-0.5 font-mono text-xs">F8</kbd>
                </button>
            </div>
        </section>

        @include('pos.partials.dialogs')
    </div>
</x-pos-layout>
