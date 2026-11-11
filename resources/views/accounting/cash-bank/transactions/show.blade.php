{{-- Detail transaksi kas: rincian, baris akun / transfer, persetujuan, lampiran, jurnal. --}}
@use('App\Support\Format')
@use('App\Enums\DocumentStatus')
@use('App\Enums\CashTransactionType')
@php
    $status = $transaction->documentStatus();
    $user = auth()->user();
    $bisaKelola = $user->can('accounting.cash-bank.create');
    $bisaBatal = $status === DocumentStatus::Posted ? $user->can('accounting.cash-bank.void') : ($bisaKelola && ! $status->isLocked());
    $baris = 'flex justify-between gap-4';
    $transfer = $transaction->type === CashTransactionType::Transfer;
@endphp

<x-app-layout>
    <x-slot name="header">{{ $transaction->type->label() }} {{ $transaction->number }}</x-slot>
    <x-slot name="description">{{ Format::tanggal($transaction->transaction_date) }} · {{ $transaction->description }}</x-slot>
    <x-slot name="actions">
        @if ($bisaBatal)
            <x-ui.button :variant="$status === DocumentStatus::Posted ? 'danger' : 'ghost'" :icon="$status === DocumentStatus::Posted ? 'arrow-uturn-left' : 'x-mark'" x-data x-on:click="$dispatch('open-modal', 'batal-transaksi')">Batalkan</x-ui.button>
        @endif
        @if ($bisaKelola && $transaction->isEditable())
            <x-ui.button variant="secondary" icon="pencil-square" :href="route('cash-transactions.edit', $transaction)">Ubah draf</x-ui.button>
            <form method="POST" action="{{ route('cash-transactions.post', $transaction) }}">
                @csrf
                <x-ui.button type="submit" :icon="$approvalFlow ? 'paper-airplane' : 'check'">{{ $approvalFlow ? 'Ajukan persetujuan' : 'Posting' }}</x-ui.button>
            </form>
        @endif
    </x-slot>

    @if ($status === DocumentStatus::Draft)
        <x-ui.alert type="info" class="mb-6" title="Draf: belum mengubah saldo rekening">
            {{ $approvalFlow ? "Transaksi ini perlu persetujuan ({$approvalFlow->name}). Ajukan; transaksi diposting otomatis setelah disetujui." : 'Klik Posting bila isinya sudah benar.' }}
        </x-ui.alert>
    @elseif ($status === DocumentStatus::Submitted)
        <x-ui.alert type="warning" class="mb-6" title="Menunggu persetujuan">Saldo rekening berubah setelah semua tahap persetujuan selesai.</x-ui.alert>
    @elseif ($status === DocumentStatus::Cancelled)
        <x-ui.alert type="danger" class="mb-6" title="Dibatalkan">
            Oleh {{ $transaction->cancelledBy?->name ?? '-' }}, {{ Format::tanggal($transaction->cancelled_at, true) }}. Alasan: {{ $transaction->cancel_reason }}
        </x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Rincian">
            <dl class="grid gap-3 text-sm">
                <div class="{{ $baris }}"><dt class="text-slate-500">Tanggal</dt><dd class="text-slate-900">{{ Format::tanggal($transaction->transaction_date) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Status</dt><dd><x-ui.badge :color="$status->color()" dot>{{ $status->label() }}</x-ui.badge></dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">{{ $transfer ? 'Dari' : 'Rekening' }}</dt><dd class="text-right text-slate-900">{{ $transaction->cashBankAccount?->label() }}</dd></div>
                @if ($transfer)
                    <div class="{{ $baris }}"><dt class="text-slate-500">Ke</dt><dd class="text-right text-slate-900">{{ $transaction->targetCashBankAccount?->label() }}</dd></div>
                @endif
                @if ($transaction->counterparty)
                    <div class="{{ $baris }}"><dt class="text-slate-500">{{ $transaction->type === CashTransactionType::In ? 'Diterima dari' : 'Dibayar kepada' }}</dt><dd class="text-right text-slate-900">{{ $transaction->counterparty }}</dd></div>
                @endif
                @if ($transaction->reference)
                    <div class="{{ $baris }}"><dt class="text-slate-500">Referensi</dt><dd class="text-slate-900">{{ $transaction->reference }}</dd></div>
                @endif
                <div class="{{ $baris }}"><dt class="text-slate-500">Jumlah</dt><dd class="font-medium tabular-nums text-slate-900">@rupiah($transaction->amount)</dd></div>
                @if ((float) $transaction->fee > 0)
                    <div class="{{ $baris }}"><dt class="text-slate-500">Biaya admin</dt><dd class="text-right tabular-nums text-slate-900">@rupiah($transaction->fee)<span class="block text-xs text-slate-500">{{ $transaction->feeAccount?->label() }}</span></dd></div>
                @endif
                <div class="{{ $baris }}"><dt class="text-slate-500">Dibuat oleh</dt><dd class="text-right text-slate-900">{{ $transaction->creator?->name ?? '-' }}</dd></div>
                @if ($transaction->posted_at)
                    <div class="{{ $baris }}"><dt class="text-slate-500">Diposting</dt><dd class="text-right text-slate-900">{{ Format::tanggal($transaction->posted_at, true) }}<span class="block text-xs text-slate-500">{{ $transaction->postedBy?->name }}</span></dd></div>
                @endif
            </dl>
        </x-ui.card>

        @unless ($transfer)
            <x-ui.table :sticky="false" class="lg:col-span-2">
                <x-slot:head>
                    <x-ui.table.th>{{ $transaction->type === CashTransactionType::In ? 'Sumber' : 'Keperluan' }}</x-ui.table.th>
                    <x-ui.table.th align="right">Jumlah</x-ui.table.th>
                </x-slot:head>
                @foreach ($transaction->lines as $line)
                    <tr>
                        <x-ui.table.td>
                            <p class="font-medium text-slate-900"><span class="font-mono text-xs text-slate-500">{{ $line->account?->code }}</span> {{ $line->account?->name }}</p>
                            @if ($line->description || $line->costCenter)
                                <p class="text-xs text-slate-500">{{ collect([$line->description, $line->costCenter ? 'Cost center '.$line->costCenter->label() : null])->filter()->join(' · ') }}</p>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td align="right" numeric>@rupiah($line->amount)</x-ui.table.td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endunless
    </div>

    <x-ui.approval-panel :document="$transaction" class="mt-6" />
    <x-ui.attachments :document="$transaction" class="mt-6" />
    <x-ui.journal-links :document="$transaction" class="mt-6" />

    @if ($bisaBatal)
        <x-ui.void-dialog name="batal-transaksi" :action="route('cash-transactions.cancel', $transaction)" :title="'Batalkan '.$transaction->number.'?'" confirm="Ya, batalkan" label="Alasan pembatalan">
            {{ $status === DocumentStatus::Posted ? 'Jurnalnya dibalik dengan tanggal hari ini, sehingga saldo rekening kembali.' : 'Transaksi belum diposting, jadi saldo rekening tidak berubah. Pengajuan persetujuan yang berjalan ikut ditarik.' }}
        </x-ui.void-dialog>
    @endif
</x-app-layout>
