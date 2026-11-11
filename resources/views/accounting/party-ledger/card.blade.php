{{-- Kartu piutang / hutang satu pihak: saldo awal, mutasi (faktur, pembayaran, nota, retur), saldo berjalan, uang muka, dan dokumen terbuka. --}}
@use('App\Support\Format')
@use('App\Support\Uang')
@php
    $rp = fn (int $sen) => Format::rupiah(Uang::keDesimal($sen));
    $prefix = $receivable ? 'receivable-reports' : 'payable-reports';
    $pihakKey = $receivable ? 'pelanggan' : 'supplier';
@endphp

<x-app-layout>
    <x-slot name="header">{{ $receivable ? 'Kartu Piutang' : 'Kartu Hutang' }}</x-slot>
    <x-slot name="description">Riwayat tagihan dan pelunasan satu {{ $receivable ? 'pelanggan' : 'supplier' }} dengan saldo berjalan.</x-slot>
    <x-slot name="actions">
        @if ($party && auth()->user()->can($receivable ? 'pos.receivables.create' : 'purchasing.payables.create'))
            <x-ui.button icon="banknotes" :href="route($receivable ? 'receivable-settlements.create' : 'payable-settlements.create', [$pihakKey => $party->id])">{{ $receivable ? 'Terima pembayaran' : 'Bayar hutang' }}</x-ui.button>
        @endif
    </x-slot>

    @include('accounting.party-ledger.nav')

    <x-ui.card class="mb-6">
        <x-ui.filter-bar :search="false">
            <x-ui.select :name="$pihakKey" :label="$receivable ? 'Pelanggan' : 'Supplier'" hide-label class="sm:min-w-[16rem]" :options="$parties" :selected="$party?->id" :placeholder="$receivable ? 'Pilih pelanggan' : 'Pilih supplier'" />
            <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="$from" />
            <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="$to" />
        </x-ui.filter-bar>
    </x-ui.card>

    @if (! $card)
        <x-ui.card>
            <x-ui.empty-state icon="identification" :title="$receivable ? 'Pilih pelanggan' : 'Pilih supplier'" description="Dari Umur {{ $receivable ? 'Piutang' : 'Hutang' }}, klik nama pihak untuk langsung membuka kartunya." />
        </x-ui.card>
    @else
        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat-card label="Saldo awal" :value="$rp($card['opening'])" icon="flag" color="neutral" :hint="'Sebelum '.Format::tanggal($from)" />
            <x-ui.stat-card :label="$receivable ? 'Tagihan baru' : 'Hutang baru'" :value="$rp($card['increase'])" icon="arrow-trending-up" color="warning" />
            <x-ui.stat-card label="Pelunasan & potongan" :value="$rp($card['decrease'])" icon="arrow-trending-down" color="success" />
            <x-ui.stat-card label="Saldo akhir" :value="$rp($card['closing'])" icon="scale" :hint="$card['deposit'] > 0 ? 'Uang muka tersedia '.$rp($card['deposit']) : 'Per '.Format::tanggal($to)" />
        </div>

        <x-ui.table :sticky="false">
            <x-slot:toolbar><h2 class="text-sm font-semibold text-slate-900">{{ $party->name }} · {{ Format::tanggal($from) }} s.d. {{ Format::tanggal($to) }}</h2></x-slot:toolbar>
            <x-slot:head>
                <x-ui.table.th>Tanggal</x-ui.table.th>
                <x-ui.table.th>Dokumen</x-ui.table.th>
                <x-ui.table.th align="right">{{ $receivable ? 'Tagihan' : 'Hutang' }}</x-ui.table.th>
                <x-ui.table.th align="right">Pelunasan</x-ui.table.th>
                <x-ui.table.th align="right">Saldo</x-ui.table.th>
            </x-slot:head>
            <tr class="bg-slate-50">
                <x-ui.table.td numeric muted>{{ Format::tanggal($from) }}</x-ui.table.td>
                <x-ui.table.td colspan="3"><span class="font-medium text-slate-900">Saldo awal</span></x-ui.table.td>
                <x-ui.table.td align="right" numeric class="font-medium">{{ $rp($card['opening']) }}</x-ui.table.td>
            </tr>
            @forelse ($card['rows'] as $row)
                <tr>
                    <x-ui.table.td numeric muted>{{ Format::tanggal($row['date']) }}</x-ui.table.td>
                    <x-ui.table.td>
                        @if ($row['url'])
                            <a href="{{ $row['url'] }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $row['number'] }}</a>
                        @else
                            <span class="font-medium text-slate-900">{{ $row['number'] }}</span>
                        @endif
                        <p class="text-xs text-slate-500">{{ $row['label'] }}</p>
                    </x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ $row['increase'] ? $rp($row['increase']) : '' }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ $row['decrease'] ? $rp($row['decrease']) : '' }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric class="font-medium">{{ $rp($row['balance']) }}</x-ui.table.td>
                </tr>
            @empty
                <x-ui.table.empty colspan="5" icon="document-text" title="Tidak ada mutasi di periode ini" />
            @endforelse
        </x-ui.table>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <x-ui.table :sticky="false">
                <x-slot:toolbar><h2 class="text-sm font-semibold text-slate-900">Dokumen belum lunas</h2></x-slot:toolbar>
                <x-slot:head>
                    <x-ui.table.th>Dokumen</x-ui.table.th>
                    <x-ui.table.th>Jatuh tempo</x-ui.table.th>
                    <x-ui.table.th align="right">Sisa</x-ui.table.th>
                </x-slot:head>
                @forelse ($open as $item)
                    @php
                        $lewat = $item['due_date'] && $item['due_date'] < now()->toDateString();
                    @endphp
                    <tr>
                        <x-ui.table.td>
                            @if ($item['url'])
                                <a href="{{ $item['url'] }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $item['number'] }}</a>
                            @else
                                {{ $item['number'] }}
                            @endif
                            <p class="text-xs text-slate-500">{{ $item['label'] }} · {{ Format::tanggal($item['date']) }}</p>
                        </x-ui.table.td>
                        <x-ui.table.td numeric :class="$lewat ? 'font-medium text-danger-700' : 'text-slate-500'">{{ $item['due_date'] ? Format::tanggal($item['due_date']) : '—' }}</x-ui.table.td>
                        <x-ui.table.td align="right" numeric>{{ $rp($item['balance']) }}</x-ui.table.td>
                    </tr>
                @empty
                    <x-ui.table.empty colspan="3" icon="check-circle" title="Semua sudah lunas" />
                @endforelse
            </x-ui.table>

            <x-ui.table :sticky="false">
                <x-slot:toolbar>
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">Uang muka</h2>
                        <p class="text-sm text-slate-500">Saldo saat ini {{ $rp($card['deposit']) }}</p>
                    </div>
                </x-slot:toolbar>
                <x-slot:head>
                    <x-ui.table.th>Dokumen</x-ui.table.th>
                    <x-ui.table.th align="right">Masuk</x-ui.table.th>
                    <x-ui.table.th align="right">Dipakai</x-ui.table.th>
                </x-slot:head>
                @forelse ($card['deposits'] as $d)
                    <tr>
                        <x-ui.table.td>
                            @if ($d['url'])
                                <a href="{{ $d['url'] }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $d['number'] }}</a>
                            @else
                                {{ $d['number'] }}
                            @endif
                            <p class="text-xs text-slate-500">{{ Format::tanggal($d['date']) }}</p>
                        </x-ui.table.td>
                        <x-ui.table.td align="right" numeric>{{ $d['added'] ? $rp($d['added']) : '' }}</x-ui.table.td>
                        <x-ui.table.td align="right" numeric>{{ $d['applied'] ? $rp($d['applied']) : '' }}</x-ui.table.td>
                    </tr>
                @empty
                    <x-ui.table.empty colspan="3" icon="wallet" title="Belum ada uang muka" />
                @endforelse
            </x-ui.table>
        </div>
    @endif
</x-app-layout>
