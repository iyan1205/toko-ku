{{--
    Jurnal akuntansi yang dibuat dari dokumen ini (dan dari pembayarannya), dengan tautan ke
    halaman jurnal. Hanya tampil untuk pengguna dengan izin accounting.journals.view dan bila
    jurnalnya ada. Pasangan dari tautan "Dokumen" di halaman jurnal.

    <x-ui.journal-links :document="$penjualan" class="mt-6" />
--}}
@props(['document'])

@php
    $journals = collect();
    if ($document && auth()->user()?->can('accounting.journals.view')) {
        $paymentIds = method_exists($document, 'payments') ? $document->payments()->pluck('id')->all() : [];
        $journals = \App\Models\Journal::query()
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('source_type', $document->getMorphClass())->where('source_id', $document->getKey()))
                ->when($paymentIds !== [], fn ($q) => $q->orWhere(fn ($w) => $w->where('source_type', (new \App\Models\Payment)->getMorphClass())->whereIn('source_id', $paymentIds))))
            ->with('branch:id,name')
            ->orderBy('journal_date')->orderBy('id')
            ->get();
    }
@endphp

@if ($journals->isNotEmpty())
    <x-ui.table :sticky="false" {{ $attributes }}>
        <x-slot:toolbar>
            <div>
                <h2 class="text-sm font-semibold text-slate-900">Jurnal akuntansi</h2>
                <p class="text-sm text-slate-500">Dibuat otomatis dari dokumen ini{{ count($paymentIds ?? []) ? ' dan pembayarannya' : '' }}.</p>
            </div>
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th>Nomor</x-ui.table.th>
            <x-ui.table.th>Tanggal</x-ui.table.th>
            <x-ui.table.th>Keterangan</x-ui.table.th>
            <x-ui.table.th align="right">Total</x-ui.table.th>
        </x-slot:head>
        @foreach ($journals as $j)
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('journals.show', $j) }}" class="font-medium text-primary-700 hover:underline">{{ $j->number }}</a>
                    @if ($j->reversal_of_id)
                        <x-ui.badge color="warning" class="ml-1">Pembalik</x-ui.badge>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td numeric muted>{{ \App\Support\Format::tanggal($j->journal_date) }}</x-ui.table.td>
                <x-ui.table.td>
                    <p class="text-slate-800">{{ $j->description }}</p>
                    @if (app(\App\Support\CurrentBranch::class)->options()->count() > 1)
                        <p class="text-xs text-slate-500">{{ $j->branch?->name }}</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($j->total)</x-ui.table.td>
            </tr>
        @endforeach
    </x-ui.table>
@endif
