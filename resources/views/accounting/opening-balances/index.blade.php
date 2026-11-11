{{-- Daftar saldo awal per cabang. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Saldo Awal</x-slot>
    <x-slot name="description">Saldo akun, persediaan, piutang, dan hutang pada tanggal mulai pembukuan. Diposting menjadi satu jurnal; selisihnya masuk Ekuitas Saldo Awal.</x-slot>
    <x-slot name="actions">
        @can('accounting.opening-balances.create')
            <x-ui.button :href="route('opening-balances.create')" icon="plus">Saldo awal baru</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :paginator="$documents">
        <x-slot:head>
            <x-ui.table.th>Nomor</x-ui.table.th>
            <x-ui.table.th>Per tanggal</x-ui.table.th>
            <x-ui.table.th>Keterangan</x-ui.table.th>
            <x-ui.table.th>Cabang</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th align="right">Total</x-ui.table.th>
        </x-slot:head>
        @forelse ($documents as $d)
            <tr>
                <x-ui.table.td><a href="{{ route('opening-balances.show', $d) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $d->number }}</a></x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($d->balance_date) }}</x-ui.table.td>
                <x-ui.table.td>
                    <p class="text-slate-800">{{ $d->description ?? '—' }}</p>
                    <p class="text-xs text-slate-500">{{ $d->creator?->name }}</p>
                </x-ui.table.td>
                <x-ui.table.td muted>{{ $d->branch?->name }}</x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$d->documentStatus()->color()" dot>{{ $d->documentStatus()->label() }}</x-ui.badge></x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($d->total)</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="6" icon="flag" title="Belum ada saldo awal"
                              description="Masukkan saldo awal bila toko sudah berjalan sebelum memakai aplikasi ini: uang di kas dan bank, nilai stok, piutang, dan hutang." />
        @endforelse
    </x-ui.table>
</x-app-layout>
