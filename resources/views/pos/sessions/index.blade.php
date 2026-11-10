{{-- Sesi kasir: buka sesi, daftar sesi (kasir: miliknya sendiri; atasan: semua), selisih setoran, ekspor. --}}
@use('App\Support\Format')
@use('App\Exports\Pos\CashSessionsExport')

<x-app-layout>
    <x-slot name="header">Sesi Kasir</x-slot>
    <x-slot name="description">{{ $viewAll ? 'Sesi semua kasir: modal awal, penjualan, dan selisih uang di laci.' : 'Sesi kasir Anda: modal awal, penjualan, dan selisih uang di laci.' }}</x-slot>
    <x-slot name="actions">
        @can('pos.sessions.export')
            <x-ui.export-menu :href="route('pos.sessions.export')" />
        @endcan
        @if ($current)
            <x-ui.button :href="route('pos.sessions.show', $current)" icon="stop-circle">Tutup sesi {{ $current->number }}</x-ui.button>
        @else
            @can('pos.sessions.create')
                <x-ui.button icon="play" x-data x-on:click="$dispatch('open-modal', 'buka-sesi')">Buka sesi</x-ui.button>
            @endcan
        @endif
    </x-slot>

    @if ($current)
        <x-ui.alert type="success" class="mb-6" title="Sesi Anda sedang terbuka">
            {{ $current->number }} di {{ $current->warehouse?->name }} sejak {{ Format::tanggal($current->opened_at, true) }}.
            Tutup sesi di akhir shift dengan menghitung uang di laci.
        </x-ui.alert>
    @endif

    <x-ui.table :paginator="$sessions">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nomor sesi{{ $viewAll ? ' atau nama kasir' : '' }}">
                @if ($viewAll)
                    <x-ui.select name="kasir" label="Kasir" hide-label :options="$cashiers->pluck('name', 'id')" :selected="request('kasir')" placeholder="Semua kasir" />
                @endif
                <x-ui.select name="gudang" label="Gudang" hide-label :options="$warehouses->pluck('name', 'id')" :selected="request('gudang')" placeholder="Semua gudang" />
                <x-ui.select name="status" label="Status" hide-label :options="CashSessionsExport::STATUSES" :selected="request('status')" placeholder="Semua status" />
                <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="request('dari')" />
                <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="request('sampai')" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="number">Sesi</x-ui.table.th>
            @if ($viewAll)
                <x-ui.table.th sort="cashier">Kasir</x-ui.table.th>
            @endif
            <x-ui.table.th sort="opened">Waktu</x-ui.table.th>
            <x-ui.table.th align="right">Modal awal</x-ui.table.th>
            <x-ui.table.th align="right">Penjualan</x-ui.table.th>
            <x-ui.table.th align="right" sort="difference">Selisih</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
        </x-slot:head>

        @forelse ($sessions as $s)
            @php($selisih = $s->cash_difference === null ? null : (float) $s->cash_difference)
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('pos.sessions.show', $s) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $s->number }}</a>
                    <p class="text-xs text-slate-500">{{ $s->warehouse?->name }}</p>
                </x-ui.table.td>
                @if ($viewAll)
                    <x-ui.table.td>{{ $s->user?->name }}</x-ui.table.td>
                @endif
                <x-ui.table.td numeric muted>
                    {{ Format::tanggal($s->opened_at, true) }}
                    <p class="text-xs">{{ $s->closed_at ? 's.d. '.Format::tanggal($s->closed_at, true) : 'masih terbuka' }}</p>
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric muted>@rupiah($s->opening_cash)</x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($s->sales_total ?? 0)</x-ui.table.td>
                <x-ui.table.td align="right" numeric @class(['text-slate-400' => $selisih === null || $selisih == 0, 'font-medium text-success-700' => $selisih > 0, 'font-medium text-danger-600' => $selisih < 0])>
                    {{ $selisih === null ? '—' : ($selisih > 0 ? '+' : '').Format::rupiah($selisih) }}
                </x-ui.table.td>
                <x-ui.table.td>
                    @if ($s->isOpen())
                        <x-ui.badge color="success" dot>Terbuka</x-ui.badge>
                    @elseif ($s->verified_at)
                        <x-ui.badge color="info" dot>Diverifikasi</x-ui.badge>
                    @else
                        <x-ui.badge color="neutral" dot>Ditutup</x-ui.badge>
                    @endif
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty :colspan="$viewAll ? 7 : 6" icon="banknotes" title="Belum ada sesi kasir" description="Sesi dibuka dari layar Kasir atau tombol Buka sesi." />
        @endforelse
    </x-ui.table>

    @can('pos.sessions.create')
        @unless ($current)
            <x-ui.modal name="buka-sesi" title="Buka sesi kasir" description="Hitung uang di laci dulu, lalu isi sebagai modal awal."
                        :show="$errors->hasAny(['warehouse_id', 'opening_cash', 'opening_note'])">
                <form id="form-buka-sesi" method="POST" action="{{ route('pos.sessions.store') }}" class="grid gap-4">
                    @csrf
                    <x-ui.select name="warehouse_id" label="Gudang penjualan" required :options="$warehouses->pluck('name', 'id')" :selected="old('warehouse_id', $defaultWarehouse->id)"
                                 hint="Stok yang dijual selama sesi ini keluar dari gudang ini." />
                    <x-ui.input name="opening_cash" label="Modal awal" type="number" prefix="Rp" min="0" step="any" inputmode="decimal" :value="old('opening_cash')" placeholder="0" />
                    <x-ui.input name="opening_note" label="Catatan" maxlength="255" :value="old('opening_note')" placeholder="Opsional" />
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
                    <x-ui.button type="submit" form="form-buka-sesi" icon="play">Buka sesi</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endunless
    @endcan
</x-app-layout>
