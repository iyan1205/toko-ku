{{-- SDM → Rekap Absensi: ringkasan per karyawan dalam satu periode, siap dipakai penggajian. --}}
@use('App\Exports\Sdm\AttendanceRecapExport')
@use('App\Support\Format')
@use('Carbon\CarbonImmutable')
@php
    $bulan = CarbonImmutable::createFromFormat('!Y-m', $filter['bulan']);
    $query = array_filter($filter);
@endphp

<x-app-layout>
    <x-slot name="header">Rekap Absensi</x-slot>
    <x-slot name="description">Periode {{ Format::tanggal($from) }} – {{ Format::tanggal($to) }}. Alpha = hari kerja yang sudah lewat tanpa absen, cuti, izin, atau sakit.</x-slot>
    <x-slot name="actions">
        @can('sdm.attendance-reports.export')
            <x-ui.export-menu :href="route('attendance-recap.export')" />
        @endcan
    </x-slot>

    <x-ui.table :paginator="$employees">
        <x-slot:toolbar>
            <div class="flex items-center gap-2">
                <x-ui.button variant="ghost" size="sm" icon="chevron-left" :href="route('attendance-recap.index', ['bulan' => $bulan->subMonthNoOverflow()->format('Y-m')] + $query)" aria-label="Periode sebelumnya" />
                <h2 class="min-w-32 text-center text-sm font-semibold text-slate-900">{{ Format::bulan($bulan) }}</h2>
                <x-ui.button variant="ghost" size="sm" icon="chevron-right" :href="route('attendance-recap.index', ['bulan' => $bulan->addMonthNoOverflow()->format('Y-m')] + $query)" aria-label="Periode berikutnya" />
            </div>
            <x-ui.filter-bar placeholder="Cari nama atau kode karyawan">
                <input type="hidden" name="bulan" value="{{ $filter['bulan'] }}">
                <x-ui.select name="departemen" label="Departemen" hide-label :options="$departments" :selected="$filter['departemen'] ?? null" placeholder="Semua departemen" />
                @if ($branches->count() > 1)
                    <x-ui.select name="cabang" label="Cabang" hide-label :options="$branches" :selected="$filter['cabang'] ?? null" placeholder="Semua cabang" />
                @endif
            </x-ui.filter-bar>
        </x-slot:toolbar>
        <x-slot:head>
            @foreach ($columns as $c)
                @continue($c['key'] === 'code' || $c['key'] === 'department')
                <x-ui.table.th :align="$c['type'] === 'text' ? 'left' : 'right'">{{ $c['key'] === 'name' ? 'Karyawan' : $c['label'] }}</x-ui.table.th>
            @endforeach
        </x-slot:head>
        @forelse ($employees as $e)
            @php
                $row = AttendanceRecapExport::row($summaries[$e->id]);
            @endphp
            <tr>
                @foreach ($columns as $c)
                    @continue($c['key'] === 'code' || $c['key'] === 'department')
                    @if ($c['key'] === 'name')
                        <x-ui.table.td class="whitespace-nowrap">
                            <a href="{{ route('attendance-recap.show', [$e, 'bulan' => $filter['bulan']]) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $e->name }}</a>
                            <span class="block text-xs text-slate-500">{{ $e->code }}{{ $e->department ? ' · '.$e->department->name : '' }}</span>
                        </x-ui.table.td>
                    @else
                        <x-ui.table.td align="right" numeric @class([
                            'text-warning-700 font-medium' => in_array($c['key'], ['absent', 'missing_out'], true) && $row[$c['key']] > 0,
                            'text-slate-400' => $row[$c['key']] === 0,
                        ])>
                            {{ AttendanceRecapExport::display($c, $row[$c['key']]) }}
                            @if ($c['key'] === 'overtime_pay' && $summaries[$e->id]['overtime_pay_missing'])
                                <span class="block text-xs text-warning-700" title="{{ $summaries[$e->id]['overtime_pay_missing'] }}">belum lengkap</span>
                            @endif
                        </x-ui.table.td>
                    @endif
                @endforeach
            </tr>
        @empty
            <x-ui.table.empty :colspan="count($columns) - 2" icon="user-group" title="Tidak ada karyawan" description="Ubah filter atau tambahkan karyawan di SDM → Karyawan." />
        @endforelse
    </x-ui.table>
</x-app-layout>
