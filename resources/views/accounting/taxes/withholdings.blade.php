{{-- Rekap bukti potong PPh 23 / 4(2) dari tagihan pembelian, dengan nomor bukti potong yang bisa diisi. --}}
@use('App\Support\Format')
@use('App\Models\TaxRate')
@php
    $bolehIsi = auth()->user()->can('accounting.taxes.update');
    $input = 'block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';
@endphp

<x-app-layout>
    <x-slot name="header">Bukti Potong PPh</x-slot>
    <x-slot name="description">PPh yang dipotong dari pembayaran ke supplier di masa {{ Format::bulan($period.'-01') }}. Setor ke kas negara (Kas &amp; Bank → Pengeluaran, akun Hutang PPh), lalu isi nomor bukti potongnya di sini.</x-slot>

    @include('accounting.taxes.nav')

    <x-ui.card class="mb-6">
        <x-ui.filter-bar :search="false">
            <x-ui.input name="masa" label="Masa" hide-label type="month" :value="$period" />
            <x-ui.select name="jenis" label="Jenis" hide-label :options="TaxRate::KINDS" :selected="$kind" placeholder="PPh 23 & 4(2)" />
        </x-ui.filter-bar>
    </x-ui.card>

    <x-ui.table :sticky="false">
        <x-slot:head>
            <x-ui.table.th>Tagihan</x-ui.table.th>
            <x-ui.table.th>Supplier</x-ui.table.th>
            <x-ui.table.th>Jenis</x-ui.table.th>
            <x-ui.table.th align="right">DPP</x-ui.table.th>
            <x-ui.table.th align="right">PPh</x-ui.table.th>
            <x-ui.table.th>Bukti potong</x-ui.table.th>
        </x-slot:head>
        @forelse ($invoices as $inv)
            <tr class="align-top">
                <x-ui.table.td>
                    <a href="{{ route('purchase-bills.show', $inv) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $inv->number }}</a>
                    <p class="text-xs text-slate-500">{{ Format::tanggal($inv->invoice_date) }}{{ $inv->reference ? ' · '.$inv->reference : '' }}</p>
                </x-ui.table.td>
                <x-ui.table.td>
                    {{ $inv->supplier?->name }}
                    <p class="text-xs text-slate-500">{{ $inv->supplier?->npwp ?? 'Tanpa NPWP' }}</p>
                </x-ui.table.td>
                <x-ui.table.td muted>{{ $inv->withholding_name }} {{ (float) $inv->withholding_rate }}%</x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($inv->withholding_base)</x-ui.table.td>
                <x-ui.table.td align="right" numeric class="font-medium">@rupiah($inv->withholding_amount)</x-ui.table.td>
                <x-ui.table.td>
                    @if ($bolehIsi)
                        <form method="POST" action="{{ route('taxes.withholdings.update', $inv) }}" class="flex flex-wrap items-center gap-2">
                            @csrf
                            <input type="text" name="withholding_slip_number" value="{{ $inv->withholding_slip_number }}" maxlength="50" placeholder="Nomor" class="{{ $input }} w-36" aria-label="Nomor bukti potong {{ $inv->number }}">
                            <input type="date" name="withholding_slip_date" value="{{ $inv->withholding_slip_date?->format('Y-m-d') }}" class="{{ $input }} w-40" aria-label="Tanggal bukti potong {{ $inv->number }}">
                            <x-ui.button type="submit" size="sm" variant="secondary">Simpan</x-ui.button>
                        </form>
                    @else
                        {{ $inv->withholding_slip_number ?? '—' }}
                    @endif
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="6" icon="document-check" title="Tidak ada PPh dipotong di masa ini" description="PPh dipotong lewat isian PPh dipotong di Tagihan Pembelian." />
        @endforelse
        @if ($invoices->isNotEmpty())
            <tr class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                <x-ui.table.td colspan="3" class="text-slate-900">Total</x-ui.table.td>
                <x-ui.table.td align="right" numeric class="text-slate-900">{{ Format::rupiah($invoices->sum(fn ($i) => (float) $i->withholding_base)) }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric class="text-slate-900">{{ Format::rupiah($invoices->sum(fn ($i) => (float) $i->withholding_amount)) }}</x-ui.table.td>
                <x-ui.table.td></x-ui.table.td>
            </tr>
        @endif
    </x-ui.table>
</x-app-layout>
