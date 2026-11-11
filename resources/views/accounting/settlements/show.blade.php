{{-- Detail pelunasan / nota: pihak, uang (rekening atau giro), uang muka, alokasi ke faktur, pembatalan. --}}
@use('App\Support\Format')
@use('App\Enums\DocumentStatus')
@use('App\Enums\GiroStatus')
@php
    $status = $settlement->documentStatus();
    $user = auth()->user();
    $prefix = $settlement->type->routePrefix();
    $giroCair = $settlement->isGiro() && $settlement->giro_status === GiroStatus::Cleared;
    $bisaBatal = $status === DocumentStatus::Posted && ! $giroCair && $user->can($settlement->type->permission('void'));
    $baris = 'flex justify-between gap-4';
@endphp

<x-app-layout>
    <x-slot name="header">{{ $settlement->type->label() }} {{ $settlement->number }}</x-slot>
    <x-slot name="description">{{ $settlement->party()?->name }} · {{ Format::tanggal($settlement->settlement_date) }}{{ $settlement->description ? ' · '.$settlement->description : '' }}</x-slot>
    <x-slot name="actions">
        @if ($bisaBatal)
            <x-ui.button variant="danger" icon="arrow-uturn-left" x-data x-on:click="$dispatch('open-modal', 'batal-pelunasan')">Batalkan</x-ui.button>
        @endif
    </x-slot>

    @if ($status === DocumentStatus::Cancelled)
        <x-ui.alert type="danger" class="mb-6" title="Dibatalkan">Oleh {{ $settlement->cancelledBy?->name ?? '-' }}, {{ Format::tanggal($settlement->cancelled_at, true) }}. Alasan: {{ $settlement->cancel_reason }}</x-ui.alert>
    @elseif ($settlement->isGiro() && $settlement->giro_status === GiroStatus::Pending)
        <x-ui.alert type="warning" class="mb-6" title="Giro belum cair">
            Jatuh tempo {{ Format::tanggal($settlement->giro_due_date) }}. Catat cair atau ditolak di
            <a href="{{ route('giros.index') }}" class="font-medium underline">Kas &amp; Bank → Giro &amp; Cek</a>.
        </x-ui.alert>
    @elseif ($giroCair)
        <x-ui.alert type="success" class="mb-6">Giro cair {{ Format::tanggal($settlement->giro_cleared_on) }} ke {{ $settlement->giroCashBankAccount?->name }}. Pelunasan dengan giro yang sudah cair tidak bisa dibatalkan.</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Rincian">
            <dl class="grid gap-3 text-sm">
                <div class="{{ $baris }}"><dt class="text-slate-500">{{ $receivable ? 'Pelanggan' : 'Supplier' }}</dt><dd class="text-right text-slate-900">{{ $settlement->party()?->name }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Tanggal</dt><dd class="text-slate-900">{{ Format::tanggal($settlement->settlement_date) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Status</dt><dd><x-ui.badge :color="$status->color()" dot>{{ $status->label() }}</x-ui.badge></dd></div>
                @if ($settlement->type->isCash())
                    <div class="{{ $baris }}"><dt class="text-slate-500">Cara bayar</dt>
                        <dd class="text-right text-slate-900">
                            @if ($settlement->isGiro())
                                Giro {{ $settlement->giro_number }}{{ $settlement->giro_bank ? ' · '.$settlement->giro_bank : '' }}
                                <x-ui.badge :color="$settlement->giro_status?->color() ?? 'neutral'" class="ml-1">{{ $settlement->giro_status?->label() }}</x-ui.badge>
                            @else
                                {{ $settlement->method === 'cash' ? 'Tunai' : 'Transfer' }} · {{ $settlement->cashBankAccount?->label() }}
                            @endif
                        </dd>
                    </div>
                    <div class="{{ $baris }}"><dt class="text-slate-500">Uang {{ $receivable ? 'diterima' : 'dibayar' }}</dt><dd class="font-medium tabular-nums text-slate-900">@rupiah($settlement->amount)</dd></div>
                    @if ((float) $settlement->deposit_applied > 0)
                        <div class="{{ $baris }}"><dt class="text-slate-500">Uang muka dipakai</dt><dd class="tabular-nums text-slate-900">@rupiah($settlement->deposit_applied)</dd></div>
                    @endif
                    @if ((float) $settlement->deposit_added > 0)
                        <div class="{{ $baris }}"><dt class="text-slate-500">Menjadi uang muka</dt><dd class="tabular-nums text-slate-900">@rupiah($settlement->deposit_added)</dd></div>
                    @endif
                @else
                    <div class="{{ $baris }}"><dt class="text-slate-500">Nilai nota</dt><dd class="font-medium tabular-nums text-slate-900">@rupiah($settlement->amount)</dd></div>
                    <div class="{{ $baris }}"><dt class="text-slate-500">Akun lawan</dt><dd class="text-right text-slate-900">{{ $settlement->counterAccount?->label() }}</dd></div>
                @endif
                @if ($settlement->reference)
                    <div class="{{ $baris }}"><dt class="text-slate-500">Referensi</dt><dd class="text-slate-900">{{ $settlement->reference }}</dd></div>
                @endif
                <div class="{{ $baris }}"><dt class="text-slate-500">Dicatat oleh</dt><dd class="text-right text-slate-900">{{ $settlement->creator?->name ?? '-' }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.table :sticky="false" class="lg:col-span-2">
            <x-slot:toolbar><h2 class="text-sm font-semibold text-slate-900">Dokumen yang dilunasi</h2></x-slot:toolbar>
            <x-slot:head>
                <x-ui.table.th>Dokumen</x-ui.table.th>
                <x-ui.table.th align="right">Nilai</x-ui.table.th>
                <x-ui.table.th align="right">Sisa sekarang</x-ui.table.th>
            </x-slot:head>
            @forelse ($settlement->allocations as $a)
                @php
                    $url = $a->document ? $documents->url($a->document, $user) : null;
                @endphp
                <tr>
                    <x-ui.table.td>
                        @if ($url)
                            <a href="{{ $url }}" class="font-medium text-primary-700 hover:underline">{{ $a->document?->number }}</a>
                        @else
                            <span class="font-medium text-slate-900">{{ $a->document?->number ?? '—' }}</span>
                        @endif
                        @if ($a->payment)
                            <p class="text-xs text-slate-500">Pembayaran {{ $a->payment->number }}{{ $a->payment->voided_at ? ' (batal)' : '' }}</p>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td align="right" numeric>@rupiah($a->amount)</x-ui.table.td>
                    <x-ui.table.td align="right" numeric muted>{{ $a->document ? Format::rupiah($a->document->balance) : '—' }}</x-ui.table.td>
                </tr>
            @empty
                <x-ui.table.empty colspan="3" icon="banknotes" title="Tidak dialokasikan ke faktur" description="Seluruh uangnya menjadi uang muka." />
            @endforelse
        </x-ui.table>
    </div>

    <x-ui.attachments :document="$settlement" class="mt-6" />
    <x-ui.journal-links :document="$settlement" class="mt-6" />

    @if ($bisaBatal)
        <x-ui.void-dialog name="batal-pelunasan" :action="route($prefix.'.cancel', $settlement)" :title="'Batalkan '.$settlement->number.'?'" confirm="Ya, batalkan" label="Alasan pembatalan">
            Pembayaran/nota per faktur dibatalkan sehingga sisa faktur kembali, dan jurnalnya dibalik dengan tanggal hari ini. Uang muka dari pelunasan ini harus belum terpakai.
        </x-ui.void-dialog>
    @endif
</x-app-layout>
