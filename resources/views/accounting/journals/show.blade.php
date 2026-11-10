{{-- Detail jurnal: baris debit/kredit, dokumen asal, dan jurnal pembalik. --}}
@use('App\Support\Format')
@php
    $bisaBalik = $journal->canBeReversedManually() && auth()->user()->can('accounting.journals.void');
    $baris = 'flex justify-between gap-4';
@endphp

<x-app-layout>
    <x-slot name="header">Jurnal {{ $journal->number }}</x-slot>
    <x-slot name="description">{{ Format::tanggal($journal->journal_date) }} · {{ $journal->description }}</x-slot>
    <x-slot name="actions">
        @if ($bisaBalik)
            <x-ui.button variant="danger" icon="arrow-uturn-left" x-data x-on:click="$dispatch('open-modal', 'balik-jurnal')">Balik jurnal</x-ui.button>
        @endif
    </x-slot>

    @if ($journal->reversal)
        <x-ui.alert type="warning" class="mb-6" title="Jurnal ini sudah dibalik">
            Dibalik dengan <a href="{{ route('journals.show', $journal->reversal) }}" class="font-medium underline">{{ $journal->reversal->number }}</a>
            pada {{ Format::tanggal($journal->reversal->journal_date) }}.
        </x-ui.alert>
    @elseif ($journal->reversalOf)
        <x-ui.alert type="info" class="mb-6">
            Jurnal pembalik untuk <a href="{{ route('journals.show', $journal->reversalOf) }}" class="font-medium underline">{{ $journal->reversalOf->number }}</a>.
        </x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Rincian">
            <dl class="grid gap-3 text-sm">
                <div class="{{ $baris }}"><dt class="text-slate-500">Tanggal</dt><dd class="text-slate-900">{{ Format::tanggal($journal->journal_date) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Sumber</dt><dd><x-ui.badge :color="$journal->isManual() ? 'primary' : 'neutral'">{{ $journal->sourceLabel() }}</x-ui.badge></dd></div>
                @if (! $journal->isManual())
                    <div class="{{ $baris }}"><dt class="text-slate-500">Dokumen</dt>
                        <dd class="text-right">
                            @if ($sourceUrl)
                                <a href="{{ $sourceUrl }}" class="text-primary-700 hover:underline">{{ $journal->sourceDocument()?->number ?? $journal->source?->number }}</a>
                            @else
                                <span class="text-slate-900">{{ $journal->sourceDocument()?->number ?? $journal->source?->number ?? '—' }}</span>
                            @endif
                        </dd>
                    </div>
                @endif
                <div class="{{ $baris }}"><dt class="text-slate-500">Total</dt><dd class="font-medium tabular-nums text-slate-900">@rupiah($journal->total)</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Dibuat oleh</dt><dd class="text-right text-slate-900">{{ $journal->user?->name ?? 'Sistem' }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Dicatat</dt><dd class="text-slate-900">{{ Format::tanggal($journal->created_at, true) }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.table :sticky="false" class="lg:col-span-2">
            <x-slot:head>
                <x-ui.table.th>Akun</x-ui.table.th>
                <x-ui.table.th align="right">Debit</x-ui.table.th>
                <x-ui.table.th align="right">Kredit</x-ui.table.th>
            </x-slot:head>
            @foreach ($journal->lines as $line)
                <tr>
                    <x-ui.table.td>
                        <p @class(['font-medium text-slate-900', 'pl-6' => (float) $line->credit > 0])>
                            <span class="font-mono text-xs text-slate-500">{{ $line->account?->code }}</span> {{ $line->account?->name }}
                        </p>
                        @if ($line->description)
                            <p @class(['text-xs text-slate-500', 'pl-6' => (float) $line->credit > 0])>{{ $line->description }}</p>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ (float) $line->debit > 0 ? Format::rupiah($line->debit) : '' }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ (float) $line->credit > 0 ? Format::rupiah($line->credit) : '' }}</x-ui.table.td>
                </tr>
            @endforeach
            <x-slot:footer>
                <div class="flex justify-end gap-8 text-sm font-semibold text-slate-900">
                    <span>Total debit <span class="tabular-nums">@rupiah($journal->lines->sum(fn ($l) => (float) $l->debit))</span></span>
                    <span>Total kredit <span class="tabular-nums">@rupiah($journal->lines->sum(fn ($l) => (float) $l->credit))</span></span>
                </div>
            </x-slot:footer>
        </x-ui.table>
    </div>

    @if ($bisaBalik)
        <x-ui.void-dialog name="balik-jurnal" :action="route('journals.reverse', $journal)" :title="'Balik '.$journal->number.'?'" confirm="Ya, balik jurnal" label="Alasan pembalikan">
            Jurnal baru dibuat dengan debit dan kredit ditukar, sehingga pengaruh jurnal ini hilang. Jurnal ini tetap tersimpan.
        </x-ui.void-dialog>
    @endif
</x-app-layout>
