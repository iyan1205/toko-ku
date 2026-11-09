{{--
    Dashboard. Setiap bagian hanya ada bila pengguna boleh melihatnya (null dari DashboardService).
    Pengguna tanpa satu pun bagian melihat pintasan modul sesuai hak aksesnya.
--}}
@use('App\Support\Format')
@php
    $jam = (int) now()->format('G');
    $sapaan = $jam < 11 ? 'Selamat pagi' : ($jam < 15 ? 'Selamat siang' : ($jam < 18 ? 'Selamat sore' : 'Selamat malam'));
    $adaIsi = $sales !== null || $recentSales !== null || $lowStock !== null || $payables !== null;
    $milikSendiri = $salesScope === 'own';

    // Perubahan omzet hari ini dibanding kemarin (null bila kemarin nol: persentase tidak bermakna).
    $perubahan = null;
    if ($sales !== null && $sales['yesterday_total'] > 0) {
        $perubahan = ($sales['today_total'] - $sales['yesterday_total']) / $sales['yesterday_total'] * 100;
    }

    $kelompok = $menu->groupBy(fn ($item) => $item['parent'] ?? 'Lainnya');
@endphp

<x-app-layout>
    <x-slot name="header">{{ $sapaan }}, {{ auth()->user()->name }}</x-slot>
    <x-slot name="description">
        {{ auth()->user()->namaPeran() }} · {{ now()->locale('id')->translatedFormat('l, d M Y') }}
    </x-slot>
    @can('pos.transactions.create')
        <x-slot name="actions">
            <x-ui.button :href="route('pos.index')" icon="shopping-cart">Transaksi baru</x-ui.button>
        </x-slot>
    @endcan

    @if (! $adaIsi)
        @if ($menu->isEmpty())
            <x-ui.card :padding="false">
                <x-ui.empty-state icon="squares-2x2" title="Belum ada modul untuk peran Anda"
                                  description="Modul akan muncul di sini setelah administrator memberi hak akses." />
            </x-ui.card>
        @else
            <div class="grid gap-8">
                @foreach ($kelompok as $namaKelompok => $items)
                    <section>
                        <h2 class="text-sm font-semibold text-slate-900">{{ $namaKelompok }}</h2>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            @foreach ($items as $item)
                                <a href="{{ $item['url'] }}"
                                   class="group flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-card transition-colors hover:border-slate-300 hover:bg-slate-50/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary-50 text-primary-600">
                                        <x-ui.icon :name="$item['icon'] ?? 'squares-2x2'" class="h-5 w-5" />
                                    </span>
                                    <span class="text-sm font-medium text-slate-700 group-hover:text-slate-900">{{ $item['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @endif
    @else
        <div class="grid gap-6">
            {{-- ---------------- KPI ---------------- --}}
            <section aria-label="Ringkasan hari ini" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @if ($sales !== null)
                    <x-ui.stat-card :label="$milikSendiri ? 'Penjualan saya hari ini' : 'Penjualan hari ini'"
                                    :value="Format::rupiah($sales['today_total'])" icon="banknotes"
                                    :trend="$perubahan === null ? null : number_format(abs($perubahan), 0, ',', '.').'%'"
                                    :trend-direction="$perubahan === null ? null : ($perubahan >= 0 ? 'up' : 'down')"
                                    :hint="$perubahan === null ? 'Kemarin '.Format::rupiah($sales['yesterday_total']) : 'dibanding kemarin'" />

                    <x-ui.stat-card :label="$milikSendiri ? 'Transaksi saya hari ini' : 'Transaksi hari ini'"
                                    :value="number_format($sales['today_count'], 0, ',', '.')" icon="receipt-percent" color="info"
                                    :hint="$sales['today_count'] > 0 ? 'Rata-rata '.Format::rupiah($sales['average']).' per transaksi' : 'Belum ada transaksi hari ini'"
                                    :href="auth()->user()->canAny(['pos.transactions.view', 'pos.transactions.view-own']) ? route('sales.index', ['dari' => today()->toDateString(), 'sampai' => today()->toDateString()]) : null" />
                @endif

                @if ($lowStock !== null)
                    <x-ui.stat-card label="Stok menipis" :value="number_format($lowStock['count'], 0, ',', '.').' produk'"
                                    icon="exclamation-triangle" :color="$lowStock['count'] > 0 ? 'warning' : 'success'"
                                    :hint="$lowStock['count'] > 0 ? 'Stok di bawah atau sama dengan minimum' : 'Semua stok di atas minimum'"
                                    :href="route('stock.index', ['stok' => 'menipis'])" />
                @endif

                @if ($payables !== null)
                    <x-ui.stat-card label="Hutang jatuh tempo" :value="Format::rupiah($payables['overdue_total'])"
                                    icon="calendar-days" :color="$payables['overdue_count'] > 0 ? 'danger' : 'neutral'"
                                    :hint="($payables['overdue_count'] > 0 ? $payables['overdue_count'].' faktur · ' : '')
                                        .($payables['upcoming_count'] > 0
                                            ? Format::rupiah($payables['upcoming_total']).' jatuh tempo '.\App\Services\Dashboard\DashboardService::PAYABLE_WARNING_DAYS.' hari lagi'
                                            : 'Tidak ada yang jatuh tempo '.\App\Services\Dashboard\DashboardService::PAYABLE_WARNING_DAYS.' hari ke depan')"
                                    :href="auth()->user()->can('purchasing.invoices.view') ? route('purchases.index') : null" />
                @endif
            </section>

            {{-- ---------------- Grafik & produk terlaris (ringkasan toko) ---------------- --}}
            @if ($chart !== null)
                <div class="grid gap-6 xl:grid-cols-3" x-data="grafikPenjualan(@js($chart))">
                    <x-ui.card class="xl:col-span-2">
                        <x-slot:actions>
                            <div class="inline-flex rounded-lg border border-slate-200 p-0.5 text-xs font-medium" role="group" aria-label="Rentang grafik">
                                @foreach ([7, 30] as $hari)
                                    <button type="button" x-on:click="periode = {{ $hari }}" x-bind:aria-pressed="periode === {{ $hari }}"
                                            x-bind:class="periode === {{ $hari }} ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
                                            class="rounded-md px-2.5 py-1 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                                        {{ $hari }} hari
                                    </button>
                                @endforeach
                            </div>
                        </x-slot:actions>

                        <div class="-mt-1 mb-4">
                            <h2 class="text-sm font-semibold text-slate-900">Omzet harian</h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                <span x-text="periode + ' hari terakhir'">7 hari terakhir</span> ·
                                <span class="font-medium tabular-nums text-slate-900" x-text="rupiah(totalPeriode)"></span>
                            </p>
                        </div>

                        <div class="relative h-64">
                            <canvas x-ref="kanvas" role="img" x-bind:aria-label="'Grafik omzet harian ' + periode + ' hari terakhir'"></canvas>
                            <div x-show="kosong || gagal" x-cloak class="absolute inset-0 grid place-items-center bg-white/80">
                                <p class="text-sm text-slate-500" x-text="gagal ? 'Grafik gagal dimuat. Muat ulang halaman.' : 'Belum ada penjualan dalam ' + periode + ' hari terakhir.'"></p>
                            </div>
                        </div>

                        {{-- Data grafik sebagai tabel untuk pembaca layar. --}}
                        <table class="sr-only">
                            <caption>Omzet dan jumlah transaksi per hari</caption>
                            <thead><tr><th scope="col">Tanggal</th><th scope="col">Omzet</th><th scope="col">Transaksi</th></tr></thead>
                            <tbody>
                                <template x-for="hari in terpilih" :key="hari.date">
                                    <tr><td x-text="hari.title"></td><td x-text="rupiah(hari.total)"></td><td x-text="hari.count"></td></tr>
                                </template>
                            </tbody>
                        </table>
                    </x-ui.card>

                    <x-ui.card :padding="false">
                        <div class="border-b border-slate-200 px-5 py-4">
                            <h2 class="text-sm font-semibold text-slate-900">Produk terlaris</h2>
                            <p class="mt-0.5 text-sm text-slate-500"><span x-text="periode">7</span> hari terakhir, menurut omzet</p>
                        </div>
                        @foreach ($topProducts as $hari => $produk)
                            <div x-show="periode === {{ $hari }}" @if ($hari !== 7) x-cloak @endif>
                                @forelse ($produk as $i => $p)
                                    <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-b-0">
                                        <span class="w-5 shrink-0 text-right text-sm tabular-nums text-slate-400">{{ $i + 1 }}</span>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium text-slate-900">{{ $p['nama'] }}</p>
                                            <p class="text-xs text-slate-500">{{ Format::jumlah($p['jumlah']) }} {{ $p['satuan'] }} terjual</p>
                                        </div>
                                        <span class="shrink-0 text-sm font-medium tabular-nums text-slate-900">{{ Format::rupiah($p['omzet']) }}</span>
                                    </div>
                                @empty
                                    <x-ui.empty-state icon="trophy" title="Belum ada penjualan" description="Produk terlaris muncul setelah ada transaksi." />
                                @endforelse
                            </div>
                        @endforeach
                    </x-ui.card>
                </div>
            @endif

            {{-- ---------------- Transaksi terakhir & stok menipis ---------------- --}}
            @if ($recentSales !== null || $lowStock !== null)
                <div class="grid gap-6 xl:grid-cols-3">
                    @if ($recentSales !== null)
                        <x-ui.card :padding="false" :class="$lowStock !== null ? 'xl:col-span-2' : 'xl:col-span-3'"
                                   :title="$recentScope === 'own' ? 'Transaksi terakhir saya' : 'Transaksi terakhir'">
                            <x-slot:actions>
                                <a href="{{ route('sales.index') }}" class="text-sm font-medium text-primary-600 hover:text-primary-700">Lihat semua</a>
                            </x-slot:actions>

                            @if ($recentSales->isEmpty())
                                <x-ui.empty-state icon="receipt-percent" title="Belum ada transaksi" />
                            @else
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                                        <thead>
                                            <tr class="text-left text-xs font-medium text-slate-500">
                                                <th scope="col" class="px-5 py-2.5 font-medium">Nomor</th>
                                                <th scope="col" class="px-3 py-2.5 font-medium">Waktu</th>
                                                <th scope="col" class="px-3 py-2.5 font-medium">Pelanggan</th>
                                                @if ($recentScope === 'all')
                                                    <th scope="col" class="px-3 py-2.5 font-medium">Kasir</th>
                                                @endif
                                                <th scope="col" class="px-5 py-2.5 text-right font-medium">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            @foreach ($recentSales as $s)
                                                <tr class="hover:bg-slate-50/70">
                                                    <td class="whitespace-nowrap px-5 py-3">
                                                        <a href="{{ route('sales.show', $s) }}" class="font-medium text-slate-900 hover:text-primary-600">{{ $s->number }}</a>
                                                        <span class="mt-0.5 block text-xs text-slate-500">{{ $s->payment_method->label() }}</span>
                                                    </td>
                                                    <td class="whitespace-nowrap px-3 py-3 text-slate-600">
                                                        {{ $s->sold_at->isToday() ? $s->sold_at->format('H:i') : $s->sold_at->locale('id')->translatedFormat('d M, H:i') }}
                                                    </td>
                                                    <td class="max-w-[10rem] truncate px-3 py-3 text-slate-600">{{ $s->customer?->name ?? 'Umum' }}</td>
                                                    @if ($recentScope === 'all')
                                                        <td class="max-w-[8rem] truncate px-3 py-3 text-slate-600">{{ $s->user?->name }}</td>
                                                    @endif
                                                    <td class="whitespace-nowrap px-5 py-3 text-right">
                                                        <span @class(['font-medium tabular-nums', 'text-slate-400 line-through' => $s->dibatalkan(), 'text-slate-900' => ! $s->dibatalkan()])>{{ Format::rupiah($s->grand_total) }}</span>
                                                        @if ($s->dibatalkan())
                                                            <span class="mt-0.5 block"><x-ui.badge color="danger">Dibatalkan</x-ui.badge></span>
                                                        @elseif ((float) $s->balance > 0)
                                                            <span class="mt-0.5 block"><x-ui.badge color="warning">Piutang</x-ui.badge></span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </x-ui.card>
                    @endif

                    @if ($lowStock !== null)
                        <x-ui.card :padding="false" title="Perlu diisi ulang" :class="$recentSales === null ? 'xl:col-span-3' : ''">
                            @if ($lowStock['count'] > 0)
                                <x-slot:actions>
                                    <a href="{{ route('stock.index', ['stok' => 'menipis']) }}" class="text-sm font-medium text-primary-600 hover:text-primary-700">Semua ({{ $lowStock['count'] }})</a>
                                </x-slot:actions>
                            @endif

                            @forelse ($lowStock['items'] as $p)
                                <a href="{{ route('stock.index', ['cari' => $p->name]) }}"
                                   class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3 last:border-b-0 hover:bg-slate-50/70">
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-medium text-slate-900">{{ $p->name }}</span>
                                        <span class="block text-xs text-slate-500">Sisa {{ Format::jumlah($p->stock) }} {{ $p->unit?->name }} · minimum {{ Format::jumlah($p->min_stock) }}</span>
                                    </span>
                                    @if ((float) $p->stock <= 0)
                                        <x-ui.badge color="danger">Habis</x-ui.badge>
                                    @else
                                        <x-ui.badge color="warning">Menipis</x-ui.badge>
                                    @endif
                                </a>
                            @empty
                                <x-ui.empty-state icon="check-circle" title="Stok aman" description="Tidak ada produk di bawah stok minimum." />
                            @endforelse
                        </x-ui.card>
                    @endif
                </div>
            @endif
        </div>
    @endif
</x-app-layout>
