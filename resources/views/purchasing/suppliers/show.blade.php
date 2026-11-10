{{-- Detail supplier: kontak, ringkasan pembelian & hutang, PO berjalan, dan faktur terakhir. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">{{ $supplier->name }}</x-slot>
    <x-slot name="description">{{ $supplier->code }}</x-slot>
    <x-slot name="actions">
        @can('purchasing.orders.create')
            <x-ui.button variant="secondary" :href="route('purchase-orders.create', ['supplier' => $supplier->id])" icon="document-plus">Buat PO</x-ui.button>
        @endcan
        @can('purchasing.suppliers.update')
            <x-ui.button :href="route('suppliers.edit', $supplier)" icon="pencil-square">Ubah</x-ui.button>
        @endcan
    </x-slot>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Jumlah faktur" :value="Format::jumlah($ringkasan['jumlah'])" icon="document-text" color="neutral" />
        <x-ui.stat-card label="Total pembelian" :value="Format::rupiah($ringkasan['total'])" icon="shopping-bag" />
        <x-ui.stat-card label="Sisa hutang" :value="Format::rupiah($ringkasan['hutang'])" icon="banknotes" :color="$ringkasan['hutang'] > 0 ? 'warning' : 'success'"
                        :href="auth()->user()->can('purchasing.payables.view') ? route('payables.index', ['supplier' => $supplier->id]) : null" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Kontak">
            <dl class="grid gap-3 text-sm">
                <div><dt class="text-slate-500">Nama kontak</dt><dd class="text-slate-900">{{ $supplier->contact_name ?: '—' }}</dd></div>
                <div><dt class="text-slate-500">Telepon</dt><dd class="text-slate-900">{{ $supplier->phone ?: '—' }}</dd></div>
                <div><dt class="text-slate-500">Email</dt><dd class="text-slate-900">{{ $supplier->email ?: '—' }}</dd></div>
                <div><dt class="text-slate-500">NPWP</dt><dd class="text-slate-900">{{ $supplier->npwp ?: '—' }}</dd></div>
                <div><dt class="text-slate-500">Alamat</dt><dd class="whitespace-pre-line text-slate-900">{{ $supplier->address ?: '—' }}</dd></div>
                @if ($supplier->note)
                    <div><dt class="text-slate-500">Catatan</dt><dd class="whitespace-pre-line text-slate-900">{{ $supplier->note }}</dd></div>
                @endif
            </dl>
        </x-ui.card>

        <div class="grid gap-6 lg:col-span-2">
            @if ($po->isNotEmpty())
                <x-ui.card title="PO berjalan" :padding="false">
                    <ul class="divide-y divide-slate-100">
                        @foreach ($po as $o)
                            <li class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                                <a href="{{ route('purchase-orders.show', $o) }}" class="font-medium text-primary-700 hover:underline">{{ $o->number }}</a>
                                <span class="text-slate-500">{{ Format::tanggal($o->order_date) }}</span>
                                <x-ui.badge :color="$o->status->color()">{{ $o->status->label() }}</x-ui.badge>
                                <span class="tabular-nums text-slate-900">@rupiah($o->grand_total)</span>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif

            <x-ui.table :sticky="false">
                <x-slot:head>
                    <x-ui.table.th>Faktur terakhir</x-ui.table.th>
                    <x-ui.table.th>Tanggal</x-ui.table.th>
                    <x-ui.table.th align="right">Total</x-ui.table.th>
                    <x-ui.table.th align="right">Sisa hutang</x-ui.table.th>
                    <x-ui.table.th>Status</x-ui.table.th>
                </x-slot:head>
                @forelse ($pembelian as $p)
                    <tr @class(['text-slate-400' => $p->dibatalkan()])>
                        <x-ui.table.td><a href="{{ route('purchases.show', $p) }}" class="font-medium text-slate-900 hover:underline">{{ $p->number }}</a></x-ui.table.td>
                        <x-ui.table.td numeric muted>{{ Format::tanggal($p->purchase_date) }}</x-ui.table.td>
                        <x-ui.table.td align="right" numeric>@rupiah($p->grand_total)</x-ui.table.td>
                        <x-ui.table.td align="right" numeric>@rupiah($p->balance)</x-ui.table.td>
                        <x-ui.table.td>
                            @if ($p->dibatalkan())
                                <x-ui.badge color="danger">Dibatalkan</x-ui.badge>
                            @else
                                @include('partials.badge-pembayaran', ['status' => $p->payment_status])
                            @endif
                        </x-ui.table.td>
                    </tr>
                @empty
                    <x-ui.table.empty colspan="5" icon="document-text" title="Belum ada pembelian dari supplier ini" />
                @endforelse
            </x-ui.table>
        </div>
    </div>
</x-app-layout>
