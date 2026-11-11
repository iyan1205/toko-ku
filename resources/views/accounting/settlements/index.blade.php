{{-- Pelunasan piutang (penerimaan, nota kredit) atau pembayaran hutang (pembayaran, nota debit). --}}
@use('App\Support\Format')
@use('App\Enums\SettlementType')
@php
    $prefix = $receivable ? 'receivable-settlements' : 'payable-settlements';
    $izin = $receivable ? 'pos.receivables.' : 'purchasing.payables.';
    [$utama, $nota] = SettlementType::forSide($receivable);
@endphp

<x-app-layout>
    <x-slot name="header">{{ $receivable ? 'Pelunasan Piutang' : 'Pembayaran Hutang' }}</x-slot>
    <x-slot name="description">{{ $receivable
        ? 'Satu penerimaan untuk banyak faktur sekaligus, sebagian atau penuh. Kelebihan bayar menjadi uang muka pelanggan. Nota kredit mengurangi tagihan tanpa uang.'
        : 'Satu pembayaran untuk banyak faktur sekaligus, sebagian atau penuh. Kelebihan bayar menjadi uang muka ke supplier. Nota debit mengurangi hutang tanpa uang.' }}</x-slot>
    <x-slot name="actions">
        @can($izin.'create')
            <x-ui.button variant="secondary" icon="receipt-percent" :href="route($prefix.'.create', ['jenis' => $nota->value])">{{ $nota->label() }}</x-ui.button>
            <x-ui.button icon="plus" :href="route($prefix.'.create')">{{ $receivable ? 'Terima pembayaran' : 'Bayar hutang' }}</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :paginator="$settlements">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nomor, referensi, atau nomor giro">
                <x-ui.select name="jenis" label="Jenis" hide-label :options="collect(SettlementType::forSide($receivable))->mapWithKeys(fn ($t) => [$t->value => $t->label()])" :selected="request('jenis')" placeholder="Semua jenis" />
                <x-ui.select name="pihak" :label="$receivable ? 'Pelanggan' : 'Supplier'" hide-label :options="$parties" :selected="request('pihak')" :placeholder="$receivable ? 'Semua pelanggan' : 'Semua supplier'" />
                <x-ui.select name="status" label="Status" hide-label :options="['posted' => 'Diposting', 'cancelled' => 'Dibatalkan']" :selected="request('status')" placeholder="Semua status" />
                <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="request('dari')" />
                <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="request('sampai')" />
            </x-ui.filter-bar>
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th sort="number">Nomor</x-ui.table.th>
            <x-ui.table.th sort="date">Tanggal</x-ui.table.th>
            <x-ui.table.th>{{ $receivable ? 'Pelanggan' : 'Supplier' }}</x-ui.table.th>
            <x-ui.table.th>Cara bayar</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th align="right" sort="amount">Jumlah</x-ui.table.th>
        </x-slot:head>
        @forelse ($settlements as $s)
            <tr>
                <x-ui.table.td>
                    <a href="{{ route($prefix.'.show', $s) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $s->number }}</a>
                    <p class="text-xs text-slate-500">{{ $s->type->label() }} · {{ $s->allocations_count }} faktur</p>
                </x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($s->settlement_date) }}</x-ui.table.td>
                <x-ui.table.td>{{ $s->party()?->name }}</x-ui.table.td>
                <x-ui.table.td muted>
                    @if ($s->isGiro())
                        Giro {{ $s->giro_number }} <x-ui.badge :color="$s->giro_status?->color() ?? 'neutral'" class="ml-1">{{ $s->giro_status?->label() }}</x-ui.badge>
                    @elseif ($s->type->isCash())
                        {{ $s->cashBankAccount?->name ?? '—' }}
                    @else
                        Tanpa uang
                    @endif
                </x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$s->documentStatus()->color()" dot>{{ $s->documentStatus()->label() }}</x-ui.badge></x-ui.table.td>
                <x-ui.table.td align="right" numeric>
                    @rupiah($s->amount)
                    @if ((float) $s->deposit_added > 0)
                        <span class="block text-xs text-slate-500">uang muka @rupiah($s->deposit_added)</span>
                    @endif
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="6" icon="banknotes" :title="$receivable ? 'Belum ada pelunasan piutang' : 'Belum ada pembayaran hutang'"
                              description="Pelunasan satu faktur tetap bisa dari halaman fakturnya. Di sini untuk banyak faktur sekaligus, uang muka, giro, dan nota." />
        @endforelse
    </x-ui.table>
</x-app-layout>
