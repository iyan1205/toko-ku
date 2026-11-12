{{-- Absensi saya per bulan: ringkasan status dan daftar harian. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Absensi Saya</x-slot>
    <x-slot name="description">{{ $month->translatedFormat('F Y') }}</x-slot>

    @include('sdm.ess.nav')

    <div class="mb-4 flex items-center justify-between gap-2">
        <x-ui.button variant="secondary" size="sm" icon="chevron-left" :href="route('ess.attendance', ['bulan' => $month->subMonthNoOverflow()->format('Y-m')])">{{ $month->subMonthNoOverflow()->translatedFormat('M Y') }}</x-ui.button>
        <span class="text-sm font-semibold text-slate-900">{{ $month->translatedFormat('F Y') }}</span>
        @if ($month->addMonthNoOverflow()->lte(now()->startOfMonth()))
            <x-ui.button variant="secondary" size="sm" icon-right="chevron-right" :href="route('ess.attendance', ['bulan' => $month->addMonthNoOverflow()->format('Y-m')])">{{ $month->addMonthNoOverflow()->translatedFormat('M Y') }}</x-ui.button>
        @else
            <span class="w-24"></span>
        @endif
    </div>

    <div class="mb-6 flex flex-wrap gap-2">
        @foreach ($summary as $label => $jumlah)
            <span @class(['rounded-full px-3 py-1 text-xs font-medium', 'bg-slate-100 text-slate-500' => $jumlah === 0, 'bg-primary-50 text-primary-700' => $jumlah > 0])>{{ $label }}: {{ $jumlah }}</span>
        @endforeach
    </div>

    <x-ui.table :sticky="false">
        <x-slot:head>
            <x-ui.table.th>Tanggal</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th align="right">Masuk</x-ui.table.th>
            <x-ui.table.th align="right">Pulang</x-ui.table.th>
        </x-slot:head>
        @forelse ($rows as $row)
            <tr>
                <x-ui.table.td numeric>{{ Format::tanggal($row->tanggal) }}
                    @if ($row->keterangan)
                        <p class="text-xs text-slate-500">{{ $row->keterangan }}</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="in_array($row->status->value, ['alpa', 'terlambat'], true) ? 'warning' : 'neutral'">{{ $row->status->label() }}</x-ui.badge></x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ $row->clock_in?->format('H:i') ?? '—' }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ $row->clock_out?->format('H:i') ?? '—' }}</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="4" icon="clock" title="Belum ada catatan absensi bulan ini" />
        @endforelse
    </x-ui.table>

    @include('sdm.ess.spacer')
</x-app-layout>
