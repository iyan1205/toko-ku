{{-- Jadwal pembayaran hutang: dokumen belum lunas dikelompokkan menurut jatuh temponya. --}}
@use('App\Support\Format')
@use('App\Support\Uang')
@php
    $receivable = false;
    $rp = fn (int $sen) => Format::rupiah(Uang::keDesimal($sen));
    $bisaBayar = auth()->user()->can('purchasing.payables.create');
@endphp

<x-app-layout>
    <x-slot name="header">Jadwal Pembayaran Hutang</x-slot>
    <x-slot name="description">Faktur pembelian, tagihan manual, dan hutang saldo awal yang belum lunas, diurutkan menurut jatuh tempo.</x-slot>

    @include('accounting.party-ledger.nav')

    @if (collect($groups)->every(fn ($g) => $g['items']->isEmpty()))
        <x-ui.card>
            <x-ui.empty-state icon="check-circle" title="Tidak ada hutang terbuka" description="Semua tagihan supplier sudah lunas." />
        </x-ui.card>
    @endif

    <div class="grid gap-6">
        @foreach ($groups as $group)
            @continue($group['items']->isEmpty())
            <x-ui.table :sticky="false">
                <x-slot:toolbar>
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-slate-900"><x-ui.badge :color="$group['color']" dot>{{ $group['label'] }}</x-ui.badge></h2>
                        <span class="text-sm font-semibold tabular-nums text-slate-900">{{ $rp($group['total']) }}</span>
                    </div>
                </x-slot:toolbar>
                <x-slot:head>
                    <x-ui.table.th>Jatuh tempo</x-ui.table.th>
                    <x-ui.table.th>Supplier</x-ui.table.th>
                    <x-ui.table.th>Dokumen</x-ui.table.th>
                    <x-ui.table.th align="right">Sisa</x-ui.table.th>
                    <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
                </x-slot:head>
                @foreach ($group['items'] as $item)
                    <tr>
                        <x-ui.table.td numeric>{{ $item['due_date'] ? Format::tanggal($item['due_date']) : '—' }}</x-ui.table.td>
                        <x-ui.table.td>{{ $item['party'] }}</x-ui.table.td>
                        <x-ui.table.td>
                            @if ($item['url'])
                                <a href="{{ $item['url'] }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $item['number'] }}</a>
                            @else
                                {{ $item['number'] }}
                            @endif
                            <p class="text-xs text-slate-500">{{ $item['label'] }} · {{ Format::tanggal($item['date']) }}</p>
                        </x-ui.table.td>
                        <x-ui.table.td align="right" numeric>{{ $rp($item['balance']) }}</x-ui.table.td>
                        <x-ui.table.td align="right">
                            @if ($bisaBayar)
                                <x-ui.button size="sm" variant="secondary" :href="route('payable-settlements.create', ['supplier' => $item['party_id'], 'dokumen' => $item['key']])">Bayar</x-ui.button>
                            @endif
                        </x-ui.table.td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endforeach
    </div>
</x-app-layout>
