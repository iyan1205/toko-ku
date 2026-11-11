{{-- Detail saldo awal: rincian per bagian, jurnal yang dibuat, dan aksi posting/batal. --}}
@use('App\Support\Format')
@use('App\Enums\DocumentStatus')
@use('App\Enums\OpeningBalanceKind')
@php
    $status = $document->documentStatus();
    $user = auth()->user();
    $bisaUbah = $status === DocumentStatus::Draft && $user->can('accounting.opening-balances.create');
    $bisaPosting = $status === DocumentStatus::Draft && $user->can('accounting.opening-balances.approve');
    $bisaBatal = $status === DocumentStatus::Posted && $user->can('accounting.opening-balances.void');
    $baris = 'flex justify-between gap-4';
@endphp

<x-app-layout>
    <x-slot name="header">Saldo awal {{ $document->number }}</x-slot>
    <x-slot name="description">Per {{ Format::tanggal($document->balance_date) }} · {{ $document->branch?->name }}{{ $document->description ? ' · '.$document->description : '' }}</x-slot>
    <x-slot name="actions">
        @if ($bisaUbah)
            <x-ui.button variant="ghost" icon="trash" class="text-danger-600" x-data
                         x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('opening-balances.destroy', $document)) }}, nama: {{ \Illuminate\Support\Js::from('draf '.$document->number) }} })">Hapus draf</x-ui.button>
            <x-ui.button variant="secondary" icon="pencil-square" :href="route('opening-balances.edit', $document)">Ubah draf</x-ui.button>
        @endif
        @if ($bisaPosting)
            <form method="POST" action="{{ route('opening-balances.post', $document) }}">
                @csrf
                <x-ui.button type="submit" icon="check">Posting</x-ui.button>
            </form>
        @endif
        @if ($bisaBatal)
            <x-ui.button variant="danger" icon="arrow-uturn-left" x-data x-on:click="$dispatch('open-modal', 'batal-saldo-awal')">Batalkan</x-ui.button>
        @endif
    </x-slot>

    @if ($status === DocumentStatus::Draft)
        <x-ui.alert type="info" class="mb-6" title="Draf: belum masuk saldo akun">Periksa rinciannya, lalu Posting.</x-ui.alert>
    @elseif ($status === DocumentStatus::Cancelled)
        <x-ui.alert type="danger" class="mb-6" title="Dibatalkan">
            Oleh {{ $document->cancelledBy?->name ?? '-' }}, {{ Format::tanggal($document->cancelled_at, true) }}. Alasan: {{ $document->cancel_reason }}
        </x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Rincian">
            <dl class="grid gap-3 text-sm">
                <div class="{{ $baris }}"><dt class="text-slate-500">Per tanggal</dt><dd class="text-slate-900">{{ Format::tanggal($document->balance_date) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Status</dt><dd><x-ui.badge :color="$status->color()" dot>{{ $status->label() }}</x-ui.badge></dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Cabang</dt><dd class="text-slate-900">{{ $document->branch?->name }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Total jurnal</dt><dd class="font-medium tabular-nums text-slate-900">@rupiah($document->total)</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Dibuat oleh</dt><dd class="text-right text-slate-900">{{ $document->creator?->name ?? '-' }}</dd></div>
                @if ($document->posted_at)
                    <div class="{{ $baris }}"><dt class="text-slate-500">Diposting</dt><dd class="text-right text-slate-900">{{ Format::tanggal($document->posted_at, true) }}<span class="block text-xs text-slate-500">{{ $document->postedBy?->name }}</span></dd></div>
                @endif
                @foreach ($journals as $j)
                    <div class="{{ $baris }}"><dt class="text-slate-500">{{ $j->reversal_of_id ? 'Jurnal pembalik' : 'Jurnal' }}</dt>
                        <dd>
                            @can('accounting.journals.view')
                                <a href="{{ route('journals.show', $j) }}" class="text-primary-700 hover:underline">{{ $j->number }}</a>
                            @else
                                {{ $j->number }}
                            @endcan
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-ui.card>

        <div class="grid gap-6 lg:col-span-2">
            @foreach (OpeningBalanceKind::cases() as $kind)
                @php
                    $daftar = $document->linesOf($kind);
                @endphp
                @continue($daftar->isEmpty())
                <x-ui.table :sticky="false">
                    <x-slot:toolbar><h2 class="text-sm font-semibold text-slate-900">{{ $kind->label() }}</h2></x-slot:toolbar>
                    <x-slot:head>
                        <x-ui.table.th>{{ match ($kind) { OpeningBalanceKind::Account => 'Akun', OpeningBalanceKind::Inventory => 'Gudang', OpeningBalanceKind::Receivable => 'Pelanggan', OpeningBalanceKind::Payable => 'Supplier' } }}</x-ui.table.th>
                        <x-ui.table.th align="right">Debit</x-ui.table.th>
                        <x-ui.table.th align="right">Kredit</x-ui.table.th>
                    </x-slot:head>
                    @foreach ($daftar as $l)
                        <tr>
                            <x-ui.table.td>
                                <p class="text-slate-900">
                                    @switch($kind)
                                        @case(OpeningBalanceKind::Account) <span class="font-mono text-xs text-slate-500">{{ $l->account?->code }}</span> {{ $l->account?->name }} @break
                                        @case(OpeningBalanceKind::Inventory) {{ $l->warehouse?->name }} @break
                                        @case(OpeningBalanceKind::Receivable) {{ $l->customer?->name }} @break
                                        @default {{ $l->supplier?->name }}
                                    @endswitch
                                </p>
                                @if ($l->description || $l->reference || $l->due_date)
                                    <p class="text-xs text-slate-500">{{ collect([$l->description, $l->reference ? 'Faktur '.$l->reference : null, $l->due_date ? 'Jatuh tempo '.Format::tanggal($l->due_date) : null])->filter()->join(' · ') }}</p>
                                @endif
                            </x-ui.table.td>
                            <x-ui.table.td align="right" numeric>{{ (float) $l->debit > 0 ? Format::rupiah($l->debit) : '' }}</x-ui.table.td>
                            <x-ui.table.td align="right" numeric>{{ (float) $l->credit > 0 ? Format::rupiah($l->credit) : '' }}</x-ui.table.td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endforeach
        </div>
    </div>

    @if ($bisaBatal)
        <x-ui.void-dialog name="batal-saldo-awal" :action="route('opening-balances.cancel', $document)" :title="'Batalkan '.$document->number.'?'" confirm="Ya, batalkan" label="Alasan pembatalan">
            Jurnal saldo awal dibalik pada tanggal yang sama ({{ Format::tanggal($document->balance_date) }}), sehingga pengaruhnya ke saldo akun hilang. Setelah itu buat saldo awal yang benar.
        </x-ui.void-dialog>
    @endif
</x-app-layout>
