<x-app-layout>
    <x-slot name="header">Laporan Penjualan</x-slot>

    <div class="space-y-6">
        {{-- Filter periode --}}
        <div class="kartu p-4 print:hidden">
            <form method="GET" action="{{ route('reports.index') }}" class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="dari" class="block text-sm font-medium text-slate-700">Dari tanggal</label>
                    <input type="date" id="dari" name="dari" value="{{ $laporan->dari()->format('Y-m-d') }}"
                           class="mt-1 rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
                </div>
                <div>
                    <label for="sampai" class="block text-sm font-medium text-slate-700">Sampai tanggal</label>
                    <input type="date" id="sampai" name="sampai" value="{{ $laporan->sampai()->format('Y-m-d') }}"
                           class="mt-1 rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
                </div>
                <button type="submit" class="tombol-utama">
                    Tampilkan
                </button>
                <button type="button" onclick="window.print()" class="tombol-kedua">
                    Cetak
                </button>
                <a href="{{ route('reports.sales.export', ['dari' => $laporan->dari()->format('Y-m-d'), 'sampai' => $laporan->sampai()->format('Y-m-d')]) }}"
                   class="tombol-kedua">
                    Ekspor Excel
                </a>
            </form>
            @error('sampai') <p class="mt-2 text-sm text-amber-600">{{ $message }}</p> @enderror
            <p class="mt-2 text-xs text-slate-500">
                Periode: {{ \App\Support\Format::tanggal($laporan->dari()) }} s/d {{ \App\Support\Format::tanggal($laporan->sampai()) }}.
                Penjualan yang dibatalkan tidak dihitung.
            </p>
        </div>

        {{-- Ringkasan --}}
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="kartu p-4">
                <p class="text-sm text-slate-500">Omzet</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">@rupiah($ringkasan['omzet'])</p>
                <p class="mt-1 text-xs text-slate-500">{{ $ringkasan['jumlah_transaksi'] }} transaksi</p>
            </div>
            <div class="kartu p-4">
                <p class="text-sm text-slate-500">Laba kotor</p>
                <p class="mt-1 text-2xl font-semibold {{ $ringkasan['laba'] >= 0 ? 'text-emerald-700' : 'text-amber-600' }}">@rupiah($ringkasan['laba'])</p>
                <p class="mt-1 text-xs text-slate-500">Margin {{ $ringkasan['margin'] }}% · HPP @rupiah($ringkasan['hpp'])</p>
            </div>
            <div class="kartu p-4">
                <p class="text-sm text-slate-500">Rata-rata per transaksi</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">@rupiah($ringkasan['rata_rata'])</p>
                <p class="mt-1 text-xs text-slate-500">{{ \App\Support\Format::jumlah($ringkasan['jumlah_item']) }} barang terjual</p>
            </div>
            <div class="kartu p-4">
                <p class="text-sm text-slate-500">Sisa piutang</p>
                <p class="mt-1 text-2xl font-semibold {{ $ringkasan['piutang'] > 0 ? 'text-amber-600' : 'text-slate-900' }}">@rupiah($ringkasan['piutang'])</p>
                <p class="mt-1 text-xs text-slate-500">Diskon: @rupiah($ringkasan['diskon_item'] + $ringkasan['diskon_transaksi'])</p>
            </div>
        </div>

        {{-- Harian --}}
        <div class="kartu">
            <h2 class="kartu-kepala kartu-judul">Rekap harian</h2>
            <div class="overflow-x-auto">
                <table class="tabel">
                    <thead>
                        <tr>
                            <th class="font-medium">Tanggal</th>
                            <th class="text-right font-medium">Transaksi</th>
                            <th class="text-right font-medium">Omzet</th>
                            <th class="text-right font-medium">HPP</th>
                            <th class="text-right font-medium">Laba kotor</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($harian as $h)
                            <tr>
                                <td >@tanggal($h['tanggal'])</td>
                                <td class="text-right">{{ $h['jumlah'] }}</td>
                                <td class="text-right">@rupiah($h['omzet'])</td>
                                <td class="text-right text-slate-600">@rupiah($h['hpp'])</td>
                                <td class="px-4 py-3 text-right font-medium text-amber-600">@rupiah($h['laba'])</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-10 text-center text-slate-500">Belum ada penjualan pada periode ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($harian->isNotEmpty())
                        <tfoot class="border-t border-slate-200 bg-slate-50 font-medium text-slate-900">
                            <tr>
                                <td >Total</td>
                                <td class="text-right">{{ $ringkasan['jumlah_transaksi'] }}</td>
                                <td class="text-right">@rupiah($ringkasan['omzet'])</td>
                                <td class="text-right">@rupiah($ringkasan['hpp'])</td>
                                <td class="text-right text-amber-600">@rupiah($ringkasan['laba'])</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            {{-- Produk terlaris --}}
            <div class="kartu">
                <h2 class="kartu-kepala kartu-judul">Produk terlaris (10 teratas)</h2>
                <div class="overflow-x-auto">
                    <table class="tabel">
                        <thead>
                            <tr>
                                <th class="font-medium">Produk</th>
                                <th class="text-right font-medium">Terjual</th>
                                <th class="text-right font-medium">Omzet</th>
                                <th class="text-right font-medium">Laba</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($perProduk as $p)
                                <tr>
                                    <td >
                                        <p class="font-medium text-slate-900">{{ $p['nama'] }}</p>
                                        <p class="text-xs text-slate-500">{{ $p['sku'] }}</p>
                                    </td>
                                    <td class="text-right">{{ \App\Support\Format::jumlah($p['jumlah']) }} <span class="text-slate-500">{{ $p['satuan'] }}</span></td>
                                    <td class="text-right">@rupiah($p['omzet'])</td>
                                    <td class="text-right text-amber-600">@rupiah($p['laba'])</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-4 py-10 text-center text-slate-500">Belum ada produk terjual.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="space-y-6">
                {{-- Per kasir --}}
                <div class="kartu">
                    <h2 class="kartu-kepala kartu-judul">Kinerja kasir</h2>
                    <div class="overflow-x-auto">
                        <table class="tabel">
                            <thead>
                                <tr>
                                    <th class="font-medium">Kasir</th>
                                    <th class="text-right font-medium">Transaksi</th>
                                    <th class="text-right font-medium">Omzet</th>
                                    <th class="text-right font-medium">Laba</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($perKasir as $k)
                                    <tr>
                                        <td >{{ $k['nama'] }}</td>
                                        <td class="text-right">{{ $k['jumlah'] }}</td>
                                        <td class="text-right">@rupiah($k['omzet'])</td>
                                        <td class="text-right text-amber-600">@rupiah($k['laba'])</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-4 py-10 text-center text-slate-500">Belum ada transaksi.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Per metode --}}
                <div class="kartu">
                    <h2 class="kartu-kepala kartu-judul">Metode pembayaran</h2>
                    <div class="overflow-x-auto">
                        <table class="tabel">
                            <thead>
                                <tr>
                                    <th class="font-medium">Metode</th>
                                    <th class="text-right font-medium">Transaksi</th>
                                    <th class="text-right font-medium">Omzet</th>
                                    <th class="text-right font-medium">Piutang</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($perMetode as $m)
                                    <tr>
                                        <td >{{ $m['metode']?->label() ?? 'Tidak dikenal' }}</td>
                                        <td class="text-right">{{ $m['jumlah'] }}</td>
                                        <td class="text-right">@rupiah($m['omzet'])</td>
                                        <td class="px-4 py-3 text-right {{ $m['piutang'] > 0 ? 'text-amber-600' : 'text-slate-600' }}">@rupiah($m['piutang'])</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-4 py-10 text-center text-slate-500">Belum ada transaksi.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>