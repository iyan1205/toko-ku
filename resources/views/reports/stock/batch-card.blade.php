{{-- Kartu stok per batch (App\Exports\Reports\BatchStockCardExport). Hanya produk yang melacak batch. --}}
@use('App\Support\Format')
@use('App\Support\DocumentLink')

<x-app-layout>
    <x-slot name="header">Laporan Stok</x-slot>
    <x-slot name="description">Riwayat masuk dan keluar setiap batch beserta saldonya, untuk produk yang melacak batch dan kedaluwarsa.</x-slot>
    <x-slot name="actions">
        @can('reports.stock.export')
            <x-ui.export-menu :href="route('reports.stock.export', 'kartu-batch')" />
        @endcan
    </x-slot>

    @include('reports.stock.nav')

    @if ($produkDipilih && $lots->isNotEmpty())
        <x-ui.card title="Batch {{ $produkDipilih->name }}" class="mb-6" :padding="false">
            <ul class="divide-y divide-slate-100">
                @foreach ($lots as $lot)
                    @php
                        $aktif = (int) ($filter['lot'] ?? 0) === $lot->id;
                    @endphp
                    <li @class(['flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm', 'bg-primary-50' => $aktif])>
                        <a href="{{ route('reports.stock.batch-card', array_filter(['produk' => $produkDipilih->id, 'gudang' => $filter['gudang'] ?? null, 'lot' => $aktif ? null : $lot->id])) }}"
                           class="font-mono text-slate-900 hover:underline">{{ $lot->batch_number ?? 'Tanpa batch' }}</a>
                        <span class="text-slate-600">{{ $lot->warehouse?->name }}</span>
                        <span class="text-slate-600">
                            @if ($lot->expiry_date)
                                Kedaluwarsa {{ Format::tanggal($lot->expiry_date) }}
                                @if ($lot->isExpired())
                                    <x-ui.badge color="danger" class="ml-1">Lewat</x-ui.badge>
                                @endif
                            @else
                                Tanpa kedaluwarsa
                            @endif
                        </span>
                        <span class="tabular-nums text-slate-700">Masuk {{ Format::jumlah($lot->quantity_in) }} · sisa <strong class="text-slate-900">{{ Format::jumlah($lot->quantity) }}</strong> {{ $produkDipilih->unit?->name }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    <x-ui.table :paginator="$rows">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nomor batch">
                @isset($filter['lot'])
                    <input type="hidden" name="lot" value="{{ $filter['lot'] }}">
                @endisset
                <x-ui.select name="produk" label="Produk" hide-label :options="$produkBatch->mapWithKeys(fn ($p) => [$p->id => $p->name.' ('.$p->sku.')'])" :selected="$filter['produk'] ?? null" placeholder="Semua produk berbatch" />
                <x-ui.select name="gudang" label="Gudang" hide-label :options="$gudang->pluck('name', 'id')" :selected="$filter['gudang'] ?? null" placeholder="Semua gudang" />
                <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="$filter['dari'] ?? null" />
                <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="$filter['sampai'] ?? null" />
            </x-ui.filter-bar>
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th sort="date">Waktu</x-ui.table.th>
            <x-ui.table.th>Produk & batch</x-ui.table.th>
            <x-ui.table.th>Jenis</x-ui.table.th>
            <x-ui.table.th align="right">Jumlah</x-ui.table.th>
            <x-ui.table.th align="right">Saldo batch</x-ui.table.th>
            <x-ui.table.th>Dokumen</x-ui.table.th>
            <x-ui.table.th>Oleh</x-ui.table.th>
        </x-slot:head>
        @forelse ($rows as $r)
            @php
                $masuk = (float) $r->quantity > 0;
                $m = $r->movement;
            @endphp
            <tr>
                <x-ui.table.td numeric muted>{{ $m ? Format::tanggal($m->created_at, true) : '—' }}</x-ui.table.td>
                <x-ui.table.td>
                    <span class="font-medium text-slate-900">{{ $r->lot?->product?->name }}</span>
                    <span class="block text-xs text-slate-500">
                        Batch <span class="font-mono">{{ $r->lot?->batch_number ?? '—' }}</span>
                        @if ($r->lot?->expiry_date)
                            · ED {{ Format::tanggal($r->lot->expiry_date) }}
                        @endif
                        · {{ $r->lot?->warehouse?->name }}
                    </span>
                </x-ui.table.td>
                <x-ui.table.td>
                    @if ($m)
                        @include('partials.badge-mutasi', ['jenis' => $m->type])
                    @endif
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric :class="$masuk ? 'font-semibold text-success-700' : 'font-semibold text-danger-600'">
                    {{ $masuk ? '+' : '−' }}{{ Format::jumlah(abs((float) $r->quantity)) }}
                    <span class="block text-xs font-normal text-slate-500">@rupiah($r->unit_cost) / unit</span>
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric class="font-medium text-slate-900">{{ Format::jumlah($r->lot_balance) }} {{ $r->lot?->product?->unit?->name }}</x-ui.table.td>
                <x-ui.table.td>
                    @if ($nomor = DocumentLink::number($m?->reference))
                        @if ($url = DocumentLink::url($m->reference, auth()->user()))
                            <a href="{{ $url }}" class="font-medium text-primary-700 hover:underline">{{ $nomor }}</a>
                        @else
                            <span class="font-medium text-slate-700">{{ $nomor }}</span>
                        @endif
                    @endif
                    <p class="max-w-xs text-xs text-slate-500">{{ $m?->note ?: '—' }}</p>
                </x-ui.table.td>
                <x-ui.table.td muted>{{ $m?->user?->name ?? 'Sistem' }}</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="7" icon="clipboard-document-list" title="Belum ada mutasi batch yang cocok"
                              description="Kartu ini hanya untuk produk yang melacak batch. Ubah produk, gudang, atau rentang tanggal." />
        @endforelse
    </x-ui.table>
</x-app-layout>
