{{-- Rincian absensi harian satu karyawan dalam satu periode (dasar rekap dan penggajian). --}}
@use('App\Exports\Sdm\AttendanceRecapExport')
@use('App\Services\Sdm\AttendanceRecapService')
@use('App\Support\Format')
@php
    $warna = ['present' => 'success', 'late' => 'warning', 'permit' => 'info', 'sick' => 'info', 'leave' => 'primary', 'unpaid_leave' => 'primary', 'absent' => 'danger'];
    $row = AttendanceRecapExport::row($summary);
    $kartu = [
        ['Hari kerja', $row['work_days']], ['Hadir', $row['attended']], ['Terlambat', $row['late'].' kali · '.$row['late_minutes'].' mnt'],
        ['Pulang cepat', $row['early_count'].' kali · '.$row['early_minutes'].' mnt'], ['Izin / sakit', $row['permit'].' / '.$row['sick']],
        ['Cuti', $row['leave'].($row['unpaid_leave'] ? ' + '.$row['unpaid_leave'].' tanpa upah' : '')], ['Alpha', $row['absent']],
        ['Lembur', Format::durasi($summary['overtime_minutes'])],
    ];
    if ($withPay) {
        $kartu[] = ['Upah lembur', $summary['overtime_pay'] === null ? '—' : Format::rupiah($summary['overtime_pay'])];
    }
@endphp

<x-app-layout>
    <x-slot name="header">{{ $employee->name }}</x-slot>
    <x-slot name="description">Absensi {{ Format::tanggal($from) }} – {{ Format::tanggal($to) }} · {{ collect([$employee->code, $employee->department?->name, $employee->branch?->name])->filter()->join(' · ') }}</x-slot>
    <x-slot name="actions">
        <x-ui.button variant="secondary" icon="arrow-left" :href="route('attendance-recap.index', ['bulan' => $filter['bulan']])">Rekap</x-ui.button>
    </x-slot>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        @foreach ($kartu as [$label, $nilai])
            <x-ui.card>
                <p class="text-xs text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-lg font-semibold tabular-nums text-slate-900">{{ $nilai }}</p>
            </x-ui.card>
        @endforeach
    </div>
    @if ($withPay && $summary['overtime_pay_missing'])
        <x-ui.alert type="warning" class="mb-6">Upah lembur belum lengkap: {{ $summary['overtime_pay_missing'] }}</x-ui.alert>
    @endif

    <x-ui.table :sticky="false">
        <x-slot:head>
            <x-ui.table.th>Tanggal</x-ui.table.th>
            <x-ui.table.th>Jadwal</x-ui.table.th>
            <x-ui.table.th>Keterangan</x-ui.table.th>
            <x-ui.table.th align="right">Masuk</x-ui.table.th>
            <x-ui.table.th align="right">Pulang</x-ui.table.th>
            <x-ui.table.th align="right">Telat / cepat</x-ui.table.th>
            <x-ui.table.th align="right">Lembur</x-ui.table.th>
        </x-slot:head>
        @foreach ($days as $key => $d)
            <tr @class(['bg-slate-50/70' => in_array($d['type'], ['off', 'holiday'], true)])>
                <x-ui.table.td class="whitespace-nowrap">
                    {{ Format::tanggal($d['date']) }}
                    <span class="block text-xs text-slate-500">{{ $d['date']->locale('id')->translatedFormat('l') }}</span>
                </x-ui.table.td>
                <x-ui.table.td class="whitespace-nowrap text-slate-600">
                    @if ($d['type'] === 'holiday')
                        {{ $d['holiday'] }}
                    @elseif ($d['type'] === 'off')
                        Libur
                    @else
                        {{ $d['shift'] ? $d['shift']->nama.' '.$d['shift']->rentang() : 'Hari kerja' }}
                        @if ($d['holiday'])
                            <span class="block text-xs text-danger-700">{{ $d['holiday'] }}</span>
                        @endif
                    @endif
                </x-ui.table.td>
                <x-ui.table.td>
                    @if (isset($warna[$d['category']]))
                        <x-ui.badge :color="$warna[$d['category']]">{{ AttendanceRecapService::CATEGORIES[$d['category']] }}</x-ui.badge>
                    @else
                        <span class="text-xs text-slate-400">{{ AttendanceRecapService::CATEGORIES[$d['category']] }}</span>
                    @endif
                    @if ($d['leave'])
                        <span class="block text-xs text-slate-500">{{ $d['leave'] }}</span>
                    @elseif ($d['record']?->keterangan)
                        <span class="block text-xs text-slate-500">{{ $d['record']->keterangan }}</span>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ $d['record']?->clock_in?->format('H:i') ?? '—' }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ $d['record']?->clock_out?->format('H:i') ?? ($d['record']?->clock_in ? 'tidak absen' : '—') }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric muted>
                    {{ $d['record']?->late_minutes ? $d['record']->late_minutes.' mnt' : '' }}{{ $d['record']?->late_minutes && $d['record']?->early_leave_minutes ? ' / ' : '' }}{{ $d['record']?->early_leave_minutes ? $d['record']->early_leave_minutes.' mnt' : '' }}
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ $d['overtime_minutes'] ? Format::durasi($d['overtime_minutes']) : '' }}</x-ui.table.td>
            </tr>
        @endforeach
    </x-ui.table>
</x-app-layout>
