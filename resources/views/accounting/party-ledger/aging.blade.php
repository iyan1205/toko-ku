{{-- Umur piutang / hutang per pihak, berdasarkan tanggal faktur atau jatuh tempo. --}}
@use('App\Support\Format')
@use('App\Support\Uang')
@php
    $rp = fn (int $sen) => $sen === 0 ? '' : Format::rupiah(Uang::keDesimal($sen));
    $prefix = $receivable ? 'receivable-reports' : 'payable-reports';
    $pihakKey = $receivable ? 'pelanggan' : 'supplier';
    $th = 'px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500 whitespace-nowrap';
@endphp

<x-app-layout>
    <x-slot name="header">{{ $receivable ? 'Umur Piutang' : 'Umur Hutang' }}</x-slot>
    <x-slot name="description">Sisa tagihan yang belum lunas per {{ $receivable ? 'pelanggan' : 'supplier' }}, dikelompokkan menurut {{ $basis === 'faktur' ? 'umur sejak tanggal faktur' : 'keterlambatan dari jatuh tempo' }}. {{ $receivable ? 'Penjualan kasir, faktur penjualan manual, dan piutang saldo awal' : 'Faktur pembelian, tagihan manual, dan hutang saldo awal' }} ikut dihitung.</x-slot>

    @include('accounting.party-ledger.nav')

    <div class="mb-4 flex gap-2">
        <x-ui.button size="sm" :variant="$basis === 'faktur' ? 'primary' : 'secondary'" :href="route($prefix.'.aging', ['dasar' => 'faktur'])">Dari tanggal faktur</x-ui.button>
        <x-ui.button size="sm" :variant="$basis === 'jatuh_tempo' ? 'primary' : 'secondary'" :href="route($prefix.'.aging', ['dasar' => 'jatuh_tempo'])">Dari jatuh tempo</x-ui.button>
    </div>

    <x-ui.table :sticky="false">
        <x-slot:head>
            <x-ui.table.th>{{ $receivable ? 'Pelanggan' : 'Supplier' }}</x-ui.table.th>
            @foreach ($aging['labels'] as $label)
                <th class="{{ $th }}">{{ $label }}</th>
            @endforeach
            <th class="{{ $th }}">Total</th>
        </x-slot:head>
        @forelse ($aging['rows'] as $row)
            <tr>
                <x-ui.table.td>
                    <a href="{{ route($prefix.'.card', [$pihakKey => $row['party_id']]) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $row['party'] }}</a>
                    <p class="text-xs text-slate-500">{{ $row['count'] }} dokumen</p>
                </x-ui.table.td>
                @foreach ($row['buckets'] as $i => $nilai)
                    <x-ui.table.td align="right" numeric :class="$nilai > 0 && $i === count($row['buckets']) - 1 ? 'font-medium text-danger-700' : ''">{{ $rp($nilai) }}</x-ui.table.td>
                @endforeach
                <x-ui.table.td align="right" numeric class="font-medium text-slate-900">{{ $rp($row['total']) }}</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty :colspan="count($aging['labels']) + 2" icon="check-circle" :title="$receivable ? 'Tidak ada piutang terbuka' : 'Tidak ada hutang terbuka'" />
        @endforelse
        @if ($aging['rows'] !== [])
            <tr class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                <x-ui.table.td class="text-slate-900">Total</x-ui.table.td>
                @foreach ($aging['totals'] as $nilai)
                    <x-ui.table.td align="right" numeric class="text-slate-900">{{ $rp($nilai) }}</x-ui.table.td>
                @endforeach
                <x-ui.table.td align="right" numeric class="text-slate-900">{{ $rp($aging['total']) }}</x-ui.table.td>
            </tr>
        @endif
    </x-ui.table>
</x-app-layout>
