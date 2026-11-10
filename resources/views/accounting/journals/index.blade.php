{{-- Jurnal umum: manual dan otomatis (penjualan, pembelian, pembayaran, retur). --}}
@use('App\Support\Format')
@use('App\Exports\Accounting\JournalsExport')

<x-app-layout>
    <x-slot name="header">Jurnal Umum</x-slot>
    <x-slot name="description">Jurnal manual dan jurnal otomatis. Jurnal tidak bisa diubah; koreksi dengan jurnal pembalik.</x-slot>
    <x-slot name="actions">
        @can('accounting.journals.export')
            <x-ui.export-menu :href="route('journals.export')" />
        @endcan
        @can('accounting.journals.create')
            <x-ui.button :href="route('journals.create')" icon="plus">Jurnal manual</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :paginator="$journals">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nomor atau keterangan">
                <x-ui.select name="sumber" label="Sumber" hide-label :options="JournalsExport::SOURCES" :selected="request('sumber')" placeholder="Semua sumber" />
                <x-ui.select name="akun" label="Akun" hide-label :options="$accounts->mapWithKeys(fn ($a) => [$a->id => $a->code.' '.$a->name])" :selected="request('akun')" placeholder="Semua akun" />
                <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="request('dari')" />
                <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="request('sampai')" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="number">Nomor</x-ui.table.th>
            <x-ui.table.th sort="date">Tanggal</x-ui.table.th>
            <x-ui.table.th>Keterangan</x-ui.table.th>
            <x-ui.table.th>Sumber</x-ui.table.th>
            <x-ui.table.th align="right" sort="total">Total</x-ui.table.th>
        </x-slot:head>

        @forelse ($journals as $j)
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('journals.show', $j) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $j->number }}</a>
                </x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($j->journal_date) }}</x-ui.table.td>
                <x-ui.table.td>
                    <p class="text-slate-800">{{ $j->description }}</p>
                    @if ($j->user)
                        <p class="text-xs text-slate-500">{{ $j->user->name }}</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$j->isManual() ? 'primary' : 'neutral'">{{ $j->sourceLabel() }}</x-ui.badge></x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($j->total)</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="5" icon="document-text" title="Belum ada jurnal yang cocok"
                              description="Jurnal otomatis muncul setiap ada penjualan dan pembelian (bila menyala di Pengaturan → Akuntansi)." />
        @endforelse
    </x-ui.table>
</x-app-layout>
