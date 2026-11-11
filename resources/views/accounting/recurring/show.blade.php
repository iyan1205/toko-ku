{{-- Detail templat jurnal berulang: jadwal, baris, dan jurnal yang sudah dibuat. --}}
@use('App\Support\Format')
@php
    $baris = 'flex justify-between gap-4';
@endphp

<x-app-layout>
    <x-slot name="header">{{ $template->name }}</x-slot>
    <x-slot name="description">{{ $template->scheduleLabel() }} · {{ $template->description }}</x-slot>
    <x-slot name="actions">
        @can('accounting.recurring.delete')
            <x-ui.button variant="ghost" icon="trash" class="text-danger-600" x-data
                         x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('recurring-journals.destroy', $template)) }}, nama: {{ \Illuminate\Support\Js::from('jurnal berulang '.$template->name) }} })">Hapus</x-ui.button>
        @endcan
        @can('accounting.recurring.update')
            <x-ui.button variant="secondary" icon="pencil-square" :href="route('recurring-journals.edit', $template)">Ubah</x-ui.button>
            @if ($template->is_active && $template->next_run_on && $template->next_run_on->lte(today()))
                <form method="POST" action="{{ route('recurring-journals.run', $template) }}">
                    @csrf
                    <x-ui.button type="submit" icon="play">Buat jurnal sekarang</x-ui.button>
                </form>
            @endif
        @endcan
    </x-slot>

    @if ($template->last_error)
        <x-ui.alert type="danger" class="mb-6" title="Jurnal terakhir gagal dibuat">{{ $template->last_error }} Perbaiki penyebabnya lalu klik "Buat jurnal sekarang".</x-ui.alert>
    @elseif (! $schedulerAlive && $template->is_active)
        <x-ui.alert type="warning" class="mb-6">Scheduler belum berjalan, jadi jurnal tidak dibuat otomatis. Gunakan "Buat jurnal sekarang" saat jadwal tiba.</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Jadwal">
            <dl class="grid gap-3 text-sm">
                <div class="{{ $baris }}"><dt class="text-slate-500">Frekuensi</dt><dd class="text-right text-slate-900">{{ $template->scheduleLabel() }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Periode</dt><dd class="text-right text-slate-900">{{ Format::tanggal($template->start_on) }} s.d. {{ $template->end_on ? Format::tanggal($template->end_on) : 'tanpa batas' }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Status</dt><dd class="text-right"><x-ui.badge :color="$template->is_active && $template->next_run_on ? 'success' : 'neutral'" dot>{{ $template->statusLabel() }}</x-ui.badge></dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Terakhir dibuat</dt><dd class="text-right text-slate-900">{{ $template->last_run_on ? Format::tanggal($template->last_run_on) : '—' }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Hasil</dt><dd class="text-right text-slate-900">{{ $template->auto_post ? 'Langsung diposting' : 'Draf untuk diperiksa' }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Atas nama</dt><dd class="text-right text-slate-900">{{ $template->creator?->name ?? '—' }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.table :sticky="false" class="lg:col-span-2">
            <x-slot:head>
                <x-ui.table.th>Akun</x-ui.table.th>
                <x-ui.table.th align="right">Debit</x-ui.table.th>
                <x-ui.table.th align="right">Kredit</x-ui.table.th>
            </x-slot:head>
            @foreach ($template->lines as $line)
                <tr>
                    <x-ui.table.td>
                        <p class="font-medium text-slate-900"><span class="font-mono text-xs text-slate-500">{{ $line->account?->code }}</span> {{ $line->account?->name }}</p>
                        @if ($line->description || $line->costCenter)
                            <p class="text-xs text-slate-500">{{ collect([$line->description, $line->costCenter ? 'Cost center '.$line->costCenter->label() : null])->filter()->join(' · ') }}</p>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ (float) $line->debit > 0 ? Format::rupiah($line->debit) : '' }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ (float) $line->credit > 0 ? Format::rupiah($line->credit) : '' }}</x-ui.table.td>
                </tr>
            @endforeach
        </x-ui.table>
    </div>

    <x-ui.table :paginator="$journals" :sticky="false" class="mt-6">
        <x-slot:toolbar><h2 class="text-sm font-semibold text-slate-900">Jurnal yang sudah dibuat</h2></x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th>Nomor</x-ui.table.th>
            <x-ui.table.th>Tanggal</x-ui.table.th>
            <x-ui.table.th>Periode</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th align="right">Total</x-ui.table.th>
        </x-slot:head>
        @forelse ($journals as $j)
            <tr>
                <x-ui.table.td><a href="{{ route('journals.show', $j) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $j->number }}</a></x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($j->journal_date) }}</x-ui.table.td>
                <x-ui.table.td muted>{{ $j->recurring_period }}</x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$j->documentStatus()->color()" dot>{{ $j->documentStatus()->label() }}</x-ui.badge></x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($j->total)</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="5" icon="document-text" title="Belum ada jurnal" description="Jurnal pertama dibuat pada tanggal jadwal." />
        @endforelse
    </x-ui.table>
</x-app-layout>
