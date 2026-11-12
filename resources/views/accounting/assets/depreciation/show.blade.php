{{-- Rincian satu kali penyusutan dan pembatalannya. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Penyusutan {{ Format::bulan($run->period.'-01') }}</x-slot>
    <x-slot name="description">{{ $run->branch?->name }} · {{ $run->asset_count }} aset · {{ $run->creator?->name ?? 'Scheduler' }}, {{ Format::tanggal($run->created_at, true) }}</x-slot>
    <x-slot name="actions">
        @if (! $run->isCancelled() && auth()->user()->can('accounting.assets.approve'))
            <x-ui.button variant="danger" icon="arrow-uturn-left" x-data x-on:click="$dispatch('open-modal', 'batal-penyusutan')">Batalkan</x-ui.button>
        @endif
    </x-slot>

    @if ($run->isCancelled())
        <x-ui.alert type="danger" class="mb-6" title="Dibatalkan">Oleh {{ $run->cancelledBy?->name ?? '-' }}, {{ Format::tanggal($run->cancelled_at, true) }}. Alasan: {{ $run->cancel_reason }}</x-ui.alert>
    @endif

    <x-ui.table :sticky="false">
        <x-slot:head>
            <x-ui.table.th>Aset</x-ui.table.th>
            <x-ui.table.th>Bulan</x-ui.table.th>
            <x-ui.table.th align="right">Penyusutan</x-ui.table.th>
        </x-slot:head>
        @forelse ($run->items as $item)
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('fixed-assets.show', $item->fixed_asset_id) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $item->asset?->name }}</a>
                    <p class="text-xs text-slate-500">{{ $item->asset?->code }} · {{ $item->asset?->category?->name }}</p>
                </x-ui.table.td>
                <x-ui.table.td muted>{{ Format::bulan($item->period.'-01') }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($item->amount)</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="3" icon="calendar" title="Rincian sudah dihapus karena dibatalkan" />
        @endforelse
        <x-slot:footer><div class="flex justify-end text-sm font-semibold text-slate-900">Total @rupiah($run->total)</div></x-slot:footer>
    </x-ui.table>

    <x-ui.journal-links :document="$run" class="mt-6" />

    @if (! $run->isCancelled())
        <x-ui.void-dialog name="batal-penyusutan" :action="route('depreciation-runs.cancel', $run)" title="Batalkan penyusutan ini?" confirm="Ya, batalkan" label="Alasan">
            Jurnalnya dibalik dan penyusutan aset di periode ini dihapus, sehingga bisa dihitung ulang. Hanya bisa bila belum ada penyusutan yang lebih baru.
        </x-ui.void-dialog>
    @endif
</x-app-layout>
