{{-- Detail pelanggan: identitas, ringkasan belanja, piutang yang belum lunas (bisa dilunasi di sini), dan transaksi terakhir. --}}
@use('App\Support\Format')
@php
    $user = auth()->user();
    $bisaLunasi = $user->can('pos.receivables.create') && $piutang->isNotEmpty();
    $awalTarget = [
        'url' => old('_piutang_url', ''),
        'nomor' => old('_piutang_nomor', ''),
        'pelanggan' => $customer->name,
        'sisa' => (float) old('_piutang_sisa', 0),
        'jumlah' => old('amount', ''),
    ];
    $baris = 'flex justify-between gap-4';
@endphp

<x-app-layout>
    <x-slot name="header">{{ $customer->name }}</x-slot>
    <x-slot name="description">{{ $customer->code }}{{ $customer->is_default ? ' · Bawaan sistem untuk transaksi tanpa pelanggan' : '' }}</x-slot>
    <x-slot name="actions">
        @unless ($customer->is_default)
            @can('pos.customers.delete')
                <x-ui.button variant="ghost" icon="trash" class="text-danger-600"
                    x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('customers.destroy', $customer)) }}, nama: {{ \Illuminate\Support\Js::from($customer->name) }} })">Hapus</x-ui.button>
            @endcan
            @can('pos.customers.update')
                <x-ui.button variant="secondary" icon="pencil-square" :href="route('customers.edit', $customer)">Ubah</x-ui.button>
            @endcan
        @endunless
    </x-slot>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Jumlah transaksi" :value="Format::jumlah($ringkasan['jumlah'])" icon="receipt-percent" color="neutral"
                        :hint="$ringkasan['terakhir'] ? 'terakhir '.Format::tanggal($ringkasan['terakhir']) : 'belum pernah belanja'" />
        <x-ui.stat-card label="Total belanja" :value="Format::rupiah($ringkasan['total'])" icon="banknotes"
                        :hint="$ringkasan['retur'] > 0 ? 'retur '.Format::rupiah($ringkasan['retur']) : null" />
        <x-ui.stat-card label="Saldo piutang" :value="Format::rupiah($customer->receivable_balance)" icon="clock" :color="(float) $customer->receivable_balance > 0 ? 'warning' : 'neutral'" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3" x-data="{ target: {{ \Illuminate\Support\Js::from($awalTarget) }} }">
        <x-ui.card title="Kontak">
            <dl class="grid gap-3 text-sm">
                <div class="{{ $baris }}"><dt class="text-slate-500">Nomor HP</dt><dd class="text-right text-slate-900">{{ $customer->phone ?: '—' }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Email</dt><dd class="text-right text-slate-900">{{ $customer->email ?: '—' }}</dd></div>
                <div><dt class="text-slate-500">Alamat</dt><dd class="mt-1 whitespace-pre-line text-slate-900">{{ $customer->address ?: '—' }}</dd></div>
                <div><dt class="text-slate-500">Catatan</dt><dd class="mt-1 whitespace-pre-line text-slate-900">{{ $customer->note ?: '—' }}</dd></div>
            </dl>
        </x-ui.card>

        <div class="grid gap-6 lg:col-span-2">
            @if ($piutang->isNotEmpty())
                <x-ui.card title="Piutang belum lunas" :padding="false">
                    <x-ui.table :sticky="false">
                        <x-slot:head>
                            <x-ui.table.th>Penjualan</x-ui.table.th>
                            <x-ui.table.th>Tanggal</x-ui.table.th>
                            <x-ui.table.th align="right">Total</x-ui.table.th>
                            <x-ui.table.th align="right">Sisa</x-ui.table.th>
                            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
                        </x-slot:head>
                        @foreach ($piutang as $p)
                            <tr>
                                <x-ui.table.td>
                                    @can('view', $p)
                                        <a href="{{ route('sales.show', $p) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $p->number }}</a>
                                    @else
                                        <span class="font-medium text-slate-900">{{ $p->number }}</span>
                                    @endcan
                                </x-ui.table.td>
                                <x-ui.table.td numeric muted>{{ Format::tanggal($p->sold_at) }}</x-ui.table.td>
                                <x-ui.table.td align="right" numeric muted>@rupiah($p->grand_total)</x-ui.table.td>
                                <x-ui.table.td align="right" numeric class="font-medium text-warning-700">@rupiah($p->balance)</x-ui.table.td>
                                <x-ui.table.td align="right">
                                    @if ($bisaLunasi)
                                        <x-ui.button size="sm" variant="secondary" icon="banknotes"
                                            x-on:click="target = { url: {{ \Illuminate\Support\Js::from(route('receivables.store', $p)) }}, nomor: {{ \Illuminate\Support\Js::from($p->number) }}, pelanggan: {{ \Illuminate\Support\Js::from($customer->name) }}, sisa: {{ (float) $p->balance }}, jumlah: {{ (float) $p->balance }} }; $dispatch('open-modal', 'terima-piutang')">Terima</x-ui.button>
                                    @endif
                                </x-ui.table.td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            @endif

            <x-ui.card title="Transaksi terakhir" :padding="false">
                <x-slot:actions>
                    @canany(['pos.transactions.view'])
                        <x-ui.button variant="link" size="sm" :href="route('sales.index', ['pelanggan' => $customer->id])">Semua transaksi</x-ui.button>
                    @endcanany
                </x-slot:actions>
                @if ($penjualan->isEmpty())
                    <x-ui.empty-state icon="receipt-percent" title="Belum ada transaksi" description="Transaksi pelanggan ini akan tampil di sini." />
                @else
                    <x-ui.table :sticky="false">
                        <x-slot:head>
                            <x-ui.table.th>Nomor</x-ui.table.th>
                            <x-ui.table.th>Waktu</x-ui.table.th>
                            <x-ui.table.th align="right">Total</x-ui.table.th>
                            <x-ui.table.th>Status</x-ui.table.th>
                        </x-slot:head>
                        @foreach ($penjualan as $p)
                            <tr @class(['text-slate-400' => $p->dibatalkan()])>
                                <x-ui.table.td>
                                    @can('view', $p)
                                        <a href="{{ route('sales.show', $p) }}" @class(['font-medium hover:underline', 'text-slate-900' => ! $p->dibatalkan(), 'line-through' => $p->dibatalkan()])>{{ $p->number }}</a>
                                    @else
                                        <span class="font-medium text-slate-900">{{ $p->number }}</span>
                                    @endcan
                                </x-ui.table.td>
                                <x-ui.table.td numeric muted>{{ Format::tanggal($p->sold_at, true) }}</x-ui.table.td>
                                <x-ui.table.td align="right" numeric>@rupiah($p->grand_total)</x-ui.table.td>
                                <x-ui.table.td>
                                    @if ($p->dibatalkan())
                                        <x-ui.badge color="neutral">Dibatalkan</x-ui.badge>
                                    @else
                                        @include('partials.badge-pembayaran', ['status' => $p->payment_status, 'penjualan' => true])
                                    @endif
                                </x-ui.table.td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
        </div>

        @if ($bisaLunasi)
            @include('partials.terima-piutang', ['methods' => $methods])
        @endif
    </div>
</x-app-layout>
