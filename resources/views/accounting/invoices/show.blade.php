{{-- Detail faktur penjualan / tagihan pembelian manual: rincian, pembayaran & nota, aksi. --}}
@use('App\Support\Format')
@use('App\Enums\DocumentStatus')
@php
    $status = $invoice->documentStatus();
    $user = auth()->user();
    $penjualan = $invoice->isSales();
    $prefix = $invoice->type->routePrefix();
    $sisi = $penjualan ? 'receivable-settlements' : 'payable-settlements';
    $pihakKey = $penjualan ? 'pelanggan' : 'supplier';
    $bisaKelola = $user->can($invoice->type->permission('create')) && ! $invoice->isOpening();
    $bisaBatal = ! $invoice->isOpening() && ($status === DocumentStatus::Posted ? $user->can($invoice->type->permission('void')) : $bisaKelola && $status === DocumentStatus::Draft);
    $bisaLunasi = $status === DocumentStatus::Posted && (float) $invoice->balance > 0 && $user->can($penjualan ? 'pos.receivables.create' : 'purchasing.payables.create');
    $lewat = $status === DocumentStatus::Posted && (float) $invoice->balance > 0 && $invoice->due_date?->lt(today());
    $baris = 'flex justify-between gap-4';
@endphp

<x-app-layout>
    <x-slot name="header">{{ $invoice->type->label() }} {{ $invoice->number }}</x-slot>
    <x-slot name="description">{{ $invoice->party()?->name }} · {{ Format::tanggal($invoice->invoice_date) }}{{ $invoice->description ? ' · '.$invoice->description : '' }}</x-slot>
    <x-slot name="actions">
        @if ($bisaLunasi)
            <x-ui.button variant="secondary" icon="receipt-percent" :href="route($sisi.'.create', ['jenis' => $penjualan ? 'credit_note' : 'debit_note', $pihakKey => $invoice->party()?->id, 'dokumen' => 'invoice:'.$invoice->id])">{{ $penjualan ? 'Nota kredit' : 'Nota debit' }}</x-ui.button>
            <x-ui.button icon="banknotes" :href="route($sisi.'.create', [$pihakKey => $invoice->party()?->id, 'dokumen' => 'invoice:'.$invoice->id])">{{ $penjualan ? 'Terima pembayaran' : 'Bayar' }}</x-ui.button>
        @endif
        @if ($bisaBatal)
            <x-ui.button :variant="$status === DocumentStatus::Posted ? 'danger' : 'ghost'" icon="x-mark" x-data x-on:click="$dispatch('open-modal', 'batal-faktur')">Batalkan</x-ui.button>
        @endif
        @if ($bisaKelola && $invoice->isEditable())
            <x-ui.button variant="ghost" icon="trash" class="text-danger-600" x-data
                         x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route($prefix.'.destroy', $invoice)) }}, nama: {{ \Illuminate\Support\Js::from('draf '.$invoice->number) }} })">Hapus draf</x-ui.button>
            <x-ui.button variant="secondary" icon="pencil-square" :href="route($prefix.'.edit', $invoice)">Ubah draf</x-ui.button>
            <form method="POST" action="{{ route($prefix.'.post', $invoice) }}">
                @csrf
                <x-ui.button type="submit" icon="check">Posting</x-ui.button>
            </form>
        @endif
    </x-slot>

    @if ($status === DocumentStatus::Draft)
        <x-ui.alert type="info" class="mb-6" title="Draf: belum menjadi {{ $penjualan ? 'piutang' : 'hutang' }}">Periksa rinciannya, lalu Posting.</x-ui.alert>
    @elseif ($status === DocumentStatus::Cancelled)
        <x-ui.alert type="danger" class="mb-6" title="Dibatalkan">Oleh {{ $invoice->cancelledBy?->name ?? '-' }}, {{ Format::tanggal($invoice->cancelled_at, true) }}. Alasan: {{ $invoice->cancel_reason }}</x-ui.alert>
    @elseif ($lewat)
        <x-ui.alert type="warning" class="mb-6" title="Lewat jatuh tempo">Jatuh tempo {{ Format::tanggal($invoice->due_date) }}, sisa @rupiah($invoice->balance).</x-ui.alert>
    @endif
    @if ($invoice->isOpening())
        <x-ui.alert type="info" class="mb-6">
            Dibuat dari saldo awal
            @if ($invoice->openingBalance)
                <a href="{{ route('opening-balances.show', $invoice->openingBalance) }}" class="font-medium underline">{{ $invoice->openingBalance->number }}</a>.
            @endif
            Jurnalnya ikut jurnal saldo awal; pembatalannya lewat saldo awal itu.
        </x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Rincian">
            <dl class="grid gap-3 text-sm">
                <div class="{{ $baris }}"><dt class="text-slate-500">{{ $penjualan ? 'Pelanggan' : 'Supplier' }}</dt><dd class="text-right text-slate-900">{{ $invoice->party()?->name }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Tanggal</dt><dd class="text-slate-900">{{ Format::tanggal($invoice->invoice_date) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Jatuh tempo</dt><dd @class(['text-slate-900' => ! $lewat, 'font-medium text-danger-700' => $lewat])>{{ $invoice->due_date ? Format::tanggal($invoice->due_date) : '—' }}</dd></div>
                @if ($invoice->reference)
                    <div class="{{ $baris }}"><dt class="text-slate-500">Referensi</dt><dd class="text-slate-900">{{ $invoice->reference }}</dd></div>
                @endif
                <div class="{{ $baris }}"><dt class="text-slate-500">Status</dt><dd><x-ui.badge :color="$status->color()" dot>{{ $status->label() }}</x-ui.badge></dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Total</dt><dd class="font-medium tabular-nums text-slate-900">@rupiah($invoice->total)</dd></div>
                @if ($status === DocumentStatus::Posted)
                    <div class="{{ $baris }}"><dt class="text-slate-500">Dibayar</dt><dd class="tabular-nums text-slate-900">@rupiah($invoice->paid_amount)</dd></div>
                    @if ((float) $invoice->credited_amount > 0)
                        <div class="{{ $baris }}"><dt class="text-slate-500">{{ $penjualan ? 'Nota kredit' : 'Nota debit' }}</dt><dd class="tabular-nums text-slate-900">@rupiah($invoice->credited_amount)</dd></div>
                    @endif
                    <div class="{{ $baris }}"><dt class="text-slate-500">Sisa</dt><dd class="text-base font-semibold tabular-nums text-slate-900">@rupiah($invoice->balance)</dd></div>
                @endif
                <div class="{{ $baris }}"><dt class="text-slate-500">Dibuat oleh</dt><dd class="text-right text-slate-900">{{ $invoice->creator?->name ?? '-' }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.table :sticky="false" class="lg:col-span-2">
            <x-slot:head>
                <x-ui.table.th>Keterangan</x-ui.table.th>
                <x-ui.table.th align="right">Jumlah</x-ui.table.th>
                <x-ui.table.th align="right">Harga</x-ui.table.th>
                <x-ui.table.th align="right">Subtotal</x-ui.table.th>
            </x-slot:head>
            @forelse ($invoice->lines as $line)
                <tr>
                    <x-ui.table.td>
                        <p class="text-slate-900">{{ $line->description }}</p>
                        <p class="text-xs text-slate-500">{{ collect([$line->account?->label(), $line->costCenter ? 'Cost center '.$line->costCenter->label() : null])->filter()->join(' · ') }}</p>
                    </x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ Format::jumlah($line->quantity) }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric>@rupiah($line->unit_price)</x-ui.table.td>
                    <x-ui.table.td align="right" numeric>@rupiah($line->amount)</x-ui.table.td>
                </tr>
            @empty
                <x-ui.table.empty colspan="4" icon="document-text" title="Tanpa rincian" description="Faktur saldo awal hanya berisi nilai tagihan." />
            @endforelse
            <x-slot:footer>
                <div class="ml-auto grid w-64 gap-1 text-sm">
                    <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span class="tabular-nums">@rupiah($invoice->subtotal)</span></div>
                    @if ((float) $invoice->tax > 0)
                        <div class="flex justify-between"><span class="text-slate-500">Pajak {{ (float) $invoice->tax_rate }}%</span><span class="tabular-nums">@rupiah($invoice->tax)</span></div>
                    @endif
                    <div class="flex justify-between font-semibold text-slate-900"><span>Total</span><span class="tabular-nums">@rupiah($invoice->total)</span></div>
                </div>
            </x-slot:footer>
        </x-ui.table>
    </div>

    @if ($invoice->payments->isNotEmpty() || $notes->isNotEmpty())
        <x-ui.table :sticky="false" class="mt-6">
            <x-slot:toolbar><h2 class="text-sm font-semibold text-slate-900">Pembayaran &amp; nota</h2></x-slot:toolbar>
            <x-slot:head>
                <x-ui.table.th>Nomor</x-ui.table.th>
                <x-ui.table.th>Tanggal</x-ui.table.th>
                <x-ui.table.th>Keterangan</x-ui.table.th>
                <x-ui.table.th align="right">Jumlah</x-ui.table.th>
            </x-slot:head>
            @foreach ($invoice->payments as $p)
                <tr @class(['opacity-60' => $p->voided_at])>
                    <x-ui.table.td>
                        @if ($p->settlement)
                            <a href="{{ route($sisi.'.show', $p->settlement) }}" class="font-medium text-primary-700 hover:underline">{{ $p->settlement->number }}</a>
                        @else
                            {{ $p->number }}
                        @endif
                        @if ($p->voided_at)
                            <x-ui.badge color="danger" class="ml-1">Batal</x-ui.badge>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td numeric muted>{{ Format::tanggal($p->paid_at) }}</x-ui.table.td>
                    <x-ui.table.td muted>{{ $p->method?->label() }}{{ $p->note ? ' · '.$p->note : '' }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric>@rupiah($p->amount)</x-ui.table.td>
                </tr>
            @endforeach
            @foreach ($notes as $n)
                <tr>
                    <x-ui.table.td><a href="{{ route($sisi.'.show', $n->settlement) }}" class="font-medium text-primary-700 hover:underline">{{ $n->settlement->number }}</a></x-ui.table.td>
                    <x-ui.table.td numeric muted>{{ Format::tanggal($n->settlement->settlement_date) }}</x-ui.table.td>
                    <x-ui.table.td muted>{{ $n->settlement->type->label() }}{{ $n->settlement->description ? ' · '.$n->settlement->description : '' }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric>@rupiah($n->amount)</x-ui.table.td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif

    <x-ui.attachments :document="$invoice" class="mt-6" />
    <x-ui.journal-links :document="$invoice" class="mt-6" />

    @if ($bisaBatal)
        <x-ui.void-dialog name="batal-faktur" :action="route($prefix.'.cancel', $invoice)" :title="'Batalkan '.$invoice->number.'?'" confirm="Ya, batalkan" label="Alasan pembatalan">
            {{ $status === DocumentStatus::Posted ? 'Jurnalnya dibalik dan sisa tagihan dihapus. Hanya bisa bila belum ada pembayaran atau nota.' : 'Draf ini tidak jadi dipakai.' }}
        </x-ui.void-dialog>
    @endif
</x-app-layout>
