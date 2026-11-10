{{-- Akuntansi → Kunci Periode: status 12 bulan dalam satu tahun, tutup dan buka kembali. --}}
@use('App\Enums\FiscalPeriodStatus')
@use('App\Support\Format')
@php($bolehUbah = auth()->user()->can('accounting.periods.update'))

<x-app-layout>
    <x-slot name="header">Kunci Periode</x-slot>
    <x-slot name="actions">
        <div class="flex items-center gap-1">
            <x-ui.button variant="secondary" size="sm" icon="chevron-left" :href="route('fiscal-periods.index', ['tahun' => $tahun - 1])" aria-label="Tahun {{ $tahun - 1 }}" />
            <span class="min-w-[4rem] text-center text-sm font-semibold text-slate-900">{{ $tahun }}</span>
            <x-ui.button variant="secondary" size="sm" icon="chevron-right" :href="route('fiscal-periods.index', ['tahun' => $tahun + 1])" aria-label="Tahun {{ $tahun + 1 }}" />
        </div>
    </x-slot>

    <x-ui.alert type="info" class="mb-6">
        Bulan yang ditutup menolak transaksi baru atau perubahan bertanggal di bulan itu: penjualan, pembelian,
        penerimaan barang, pembayaran, retur, stok opname, transfer & penyesuaian stok, dan jurnal.
        Pembatalan dokumen lama tetap bisa; pembaliknya dibukukan di tanggal pembatalan.
    </x-ui.alert>

    <x-ui.table :sticky="false">
        <x-slot:toolbar>
            <p class="text-sm text-slate-500">{{ $jumlahDitutup }} dari 12 bulan di tahun {{ $tahun }} sudah ditutup.</p>
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th>Bulan</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th>Riwayat</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>
        @foreach ($bulan as $b)
            @php($kunci = "periode-{$b['year']}-{$b['month']}")
            <tr>
                <x-ui.table.td>
                    <span class="font-medium text-slate-900">{{ $b['label'] }}</span>
                    @if ($b['current'])
                        <span class="ml-1 text-xs text-slate-400">bulan ini</span>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td>
                    @if ($b['future'])
                        <span class="text-sm text-slate-400">Belum berjalan</span>
                    @else
                        <x-ui.badge :color="$b['status']->color()" dot>{{ $b['status']->label() }}</x-ui.badge>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td muted>
                    @if ($b['period']?->status === FiscalPeriodStatus::Closed)
                        Ditutup {{ Format::tanggal($b['period']->closed_at, true) }} oleh {{ $b['period']->closedBy?->name ?? '-' }}
                        @if ($b['period']->note)
                            <span class="block text-xs">{{ $b['period']->note }}</span>
                        @endif
                    @elseif ($b['period']?->reopened_at)
                        Dibuka kembali {{ Format::tanggal($b['period']->reopened_at, true) }} oleh {{ $b['period']->reopenedBy?->name ?? '-' }}
                        <span class="block text-xs">Alasan: {{ $b['period']->note }}</span>
                    @else
                        —
                    @endif
                </x-ui.table.td>
                <x-ui.table.td align="right">
                    @if ($bolehUbah && ! $b['future'])
                        @if ($b['status'] === FiscalPeriodStatus::Closed)
                            <x-ui.button variant="secondary" size="sm" icon="lock-open" x-data x-on:click="$dispatch('open-modal', 'buka-{{ $kunci }}')">Buka kembali</x-ui.button>
                            <x-ui.void-dialog :name="'buka-'.$kunci" :action="route('fiscal-periods.reopen', [$b['year'], $b['month']])"
                                              :title="'Buka kembali periode '.$b['label'].'?'" confirm="Ya, buka kembali" label="Alasan membuka kembali">
                                Transaksi bertanggal di bulan ini bisa dibuat dan diubah lagi. Tutup kembali setelah koreksi selesai.
                            </x-ui.void-dialog>
                        @else
                            <x-ui.button variant="secondary" size="sm" icon="lock-closed"
                                         x-data x-on:click="$dispatch('konfirmasi-hapus', {
                                             action: {{ \Illuminate\Support\Js::from(route('fiscal-periods.close', [$b['year'], $b['month']])) }},
                                             method: 'POST',
                                             judul: {{ \Illuminate\Support\Js::from('Tutup periode '.$b['label'].'?') }},
                                             pesan: {{ \Illuminate\Support\Js::from($b['current'] ? 'Bulan ini masih berjalan. Setelah ditutup, kasir tidak bisa berjualan sampai periode dibuka kembali.' : 'Transaksi bertanggal di bulan ini akan ditolak sampai periode dibuka kembali.') }},
                                             tombol: 'Ya, tutup',
                                         })">Tutup periode</x-ui.button>
                        @endif
                    @endif
                </x-ui.table.td>
            </tr>
        @endforeach
    </x-ui.table>
</x-app-layout>
