{{-- Transaksi kas & bank: penerimaan, pengeluaran, dan transfer antar rekening. --}}
@use('App\Support\Format')
@use('App\Enums\CashTransactionType')
@use('App\Enums\DocumentStatus')

<x-app-layout>
    <x-slot name="header">Transaksi Kas & Bank</x-slot>
    <x-slot name="description">Uang masuk dan keluar di luar penjualan dan pembelian (misal bayar listrik, setoran modal), serta transfer antar rekening. Setiap transaksi yang diposting menjadi jurnal.</x-slot>
    <x-slot name="actions">
        @can('accounting.cash-bank.export')
            <x-ui.export-menu :href="route('cash-transactions.export')" />
        @endcan
        @can('accounting.cash-bank.create')
            <x-ui.button variant="secondary" icon="arrow-down-left" :href="route('cash-transactions.create', 'masuk')">Penerimaan</x-ui.button>
            <x-ui.button variant="secondary" icon="arrow-up-right" :href="route('cash-transactions.create', 'keluar')">Pengeluaran</x-ui.button>
            <x-ui.button icon="arrows-right-left" :href="route('cash-transactions.create', 'transfer')">Transfer</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :paginator="$transactions">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nomor, keterangan, atau pihak">
                <x-ui.select name="jenis" label="Jenis" hide-label :options="collect(CashTransactionType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])" :selected="request('jenis')" placeholder="Semua jenis" />
                <x-ui.select name="rekening" label="Rekening" hide-label :options="$rekening->pluck('name', 'id')" :selected="request('rekening')" placeholder="Semua rekening" />
                <x-ui.select name="status" label="Status" hide-label :options="collect(DocumentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])" :selected="request('status')" placeholder="Semua status" />
                <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="request('dari')" />
                <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="request('sampai')" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="number">Nomor</x-ui.table.th>
            <x-ui.table.th sort="date">Tanggal</x-ui.table.th>
            <x-ui.table.th>Jenis</x-ui.table.th>
            <x-ui.table.th>Rekening</x-ui.table.th>
            <x-ui.table.th>Keterangan</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th align="right" sort="amount">Jumlah</x-ui.table.th>
        </x-slot:head>

        @forelse ($transactions as $t)
            <tr>
                <x-ui.table.td><a href="{{ route('cash-transactions.show', $t) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $t->number }}</a></x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($t->transaction_date) }}</x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$t->type->color()">{{ $t->type->shortLabel() }}</x-ui.badge></x-ui.table.td>
                <x-ui.table.td muted>{{ $t->cashBankAccount?->name }}{{ $t->targetCashBankAccount ? ' → '.$t->targetCashBankAccount->name : '' }}</x-ui.table.td>
                <x-ui.table.td>
                    <p class="text-slate-800">{{ $t->description }}</p>
                    @if ($t->counterparty)
                        <p class="text-xs text-slate-500">{{ $t->counterparty }}</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$t->documentStatus()->color()" dot>{{ $t->documentStatus()->label() }}</x-ui.badge></x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($t->amount)</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="7" icon="banknotes" title="Belum ada transaksi kas" description="Catat uang masuk/keluar di luar penjualan dan pembelian, atau pindahkan uang antar rekening." />
        @endforelse
    </x-ui.table>
</x-app-layout>
