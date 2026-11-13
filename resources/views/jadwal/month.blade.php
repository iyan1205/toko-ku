{{-- Jadwal bulanan: karyawan × tanggal. Sumber: jadwal kerja, hari libur, libur mingguan (Pengaturan → SDM), dan cuti disetujui. --}}
@use('App\Support\Format')
@php
    $warna = [
        'primary' => 'bg-primary-100 text-primary-800',
        'success' => 'bg-success-100 text-success-800',
        'warning' => 'bg-warning-100 text-warning-900',
        'danger' => 'bg-danger-100 text-danger-800',
        'info' => 'bg-info-100 text-info-800',
        'neutral' => 'bg-slate-200 text-slate-800',
    ];
    $singkat = fn ($shift) => mb_strtoupper(mb_substr($shift->nama, 0, 2));
    $sebelum = $start->subMonth()->format('Y-m');
    $sesudah = $start->addMonth()->format('Y-m');
    $hariIni = today()->toDateString();
@endphp

<x-app-layout>
    <x-slot name="header">Jadwal Bulanan</x-slot>
    <x-slot name="description">Shift tiap karyawan dalam sebulan. Ubah per minggu di Jadwal Kerja, atau isi banyak sekaligus dengan pola rotasi.</x-slot>
    <x-slot name="actions">
        <x-ui.button variant="secondary" icon="table-cells" :href="route('schedules.index', ['minggu' => $start->startOfWeek()->toDateString()])">Jadwal mingguan</x-ui.button>
        @can('sdm.shifts.view')
            <x-ui.button variant="secondary" icon="arrow-path" :href="route('shift-rotations.index')">Pola rotasi</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :sticky="false">
        <x-slot:toolbar>
            <div class="flex items-center gap-2">
                <x-ui.button variant="ghost" size="sm" icon="chevron-left" :href="route('schedules.month', ['bulan' => $sebelum] + $filter)" aria-label="Bulan sebelumnya" />
                <h2 class="min-w-32 text-center text-sm font-semibold text-slate-900">{{ Format::bulan($start) }}</h2>
                <x-ui.button variant="ghost" size="sm" icon="chevron-right" :href="route('schedules.month', ['bulan' => $sesudah] + $filter)" aria-label="Bulan berikutnya" />
            </div>
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="bulan" value="{{ $start->format('Y-m') }}">
                <x-ui.select name="departemen" label="Departemen" hide-label :options="$departments" :selected="$filter['departemen'] ?? null" placeholder="Semua departemen" />
                @if ($branches->count() > 1)
                    <x-ui.select name="cabang" label="Cabang" hide-label :options="$branches" :selected="$filter['cabang'] ?? null" placeholder="Semua cabang" />
                @endif
                <x-ui.button type="submit" variant="secondary" size="sm">Terapkan</x-ui.button>
            </form>
        </x-slot:toolbar>
        <x-slot:head>
            <th scope="col" class="sticky left-0 z-10 bg-slate-50 px-3 py-2 text-left text-xs font-semibold text-slate-600">Karyawan</th>
            @foreach ($dates as $d)
                @php
                    $libur = $holidays[$d->toDateString()] ?? null;
                @endphp
                <th scope="col" title="{{ $libur }}" @class([
                    'px-0.5 py-2 text-center text-[11px] font-medium',
                    'bg-danger-50 text-danger-700' => $libur,
                    'text-slate-400' => ! $libur && $d->isSunday(),
                    'text-slate-600' => ! $libur && ! $d->isSunday(),
                    'ring-2 ring-inset ring-primary-400' => $d->toDateString() === $hariIni,
                ])>
                    <span class="block">{{ $d->format('j') }}</span>
                    <span class="block font-normal">{{ mb_substr($d->locale('id')->translatedFormat('D'), 0, 2) }}</span>
                </th>
            @endforeach
        </x-slot:head>
        @forelse ($employees as $e)
            <tr>
                <th scope="row" class="sticky left-0 z-10 whitespace-nowrap bg-white px-3 py-1.5 text-left text-sm font-medium text-slate-900">
                    {{ $e->name }}
                    <span class="block text-xs font-normal text-slate-500">{{ $e->department?->name ?? $e->code }}</span>
                </th>
                @foreach ($calendar[$e->id] as $key => $day)
                    @php
                        $cuti = $leaveDays[$e->id.'|'.$key] ?? null;
                    @endphp
                    <td class="px-0.5 py-1 text-center">
                        @if ($cuti)
                            <span class="block rounded bg-info-50 py-1 text-[11px] font-semibold text-info-700 ring-1 ring-inset ring-info-200" title="{{ $cuti }}">C</span>
                        @elseif ($day['type'] === 'holiday')
                            <span class="block rounded bg-danger-50 py-1 text-[11px] font-semibold text-danger-700" title="{{ $day['holiday'] }}">LN</span>
                        @elseif ($day['type'] === 'off')
                            <span class="block rounded bg-slate-100 py-1 text-[11px] text-slate-400" title="Libur">L</span>
                        @elseif ($day['shift'])
                            <span class="block rounded py-1 text-[11px] font-semibold {{ $warna[$day['shift']->color ?? 'primary'] ?? $warna['primary'] }}" title="{{ $day['shift']->nama }} {{ $day['shift']->rentang() }}">{{ $singkat($day['shift']) }}</span>
                        @else
                            <span class="block py-1 text-[11px] text-slate-300" title="Hari kerja tanpa shift (jam bawaan)">•</span>
                        @endif
                    </td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="{{ $dates->count() + 1 }}"><x-ui.empty-state icon="user-group" title="Tidak ada karyawan" description="Ubah filter atau tambahkan karyawan di SDM → Karyawan." /></td>
            </tr>
        @endforelse
    </x-ui.table>

    <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-600">
        @foreach ($shifts->where('is_active', true) as $s)
            <span class="inline-flex items-center gap-1.5"><span class="rounded px-1.5 py-0.5 font-semibold {{ $warna[$s->color ?? 'primary'] ?? $warna['primary'] }}">{{ $singkat($s) }}</span>{{ $s->nama }} {{ $s->rentang() }}</span>
        @endforeach
        <span class="inline-flex items-center gap-1.5"><span class="rounded bg-slate-100 px-1.5 py-0.5 text-slate-400">L</span>Libur</span>
        <span class="inline-flex items-center gap-1.5"><span class="rounded bg-danger-50 px-1.5 py-0.5 font-semibold text-danger-700">LN</span>Hari libur</span>
        <span class="inline-flex items-center gap-1.5"><span class="rounded bg-info-50 px-1.5 py-0.5 font-semibold text-info-700 ring-1 ring-inset ring-info-200">C</span>Cuti</span>
        <span class="inline-flex items-center gap-1.5"><span class="text-slate-300">•</span>Hari kerja tanpa shift</span>
    </div>
</x-app-layout>
