{{-- Kalender cuti tim: siapa yang cuti pada tanggal berapa (disetujui dan masih menunggu). --}}
@use('App\Enums\StatusCuti')
@use('App\Support\Format')
@php
    $sebelum = $start->subMonthNoOverflow()->format('Y-m');
    $sesudah = $start->addMonthNoOverflow()->format('Y-m');
    $query = array_filter(['departemen' => $filter['departemen'] ?? null]);
    $hariIni = today()->toDateString();
@endphp

<x-app-layout>
    <x-slot name="header">Kalender Cuti</x-slot>
    <x-slot name="description">{{ $semua ? 'Cuti dan izin semua karyawan.' : 'Cuti dan izin rekan satu departemen.' }} Kotak bergaris = masih menunggu persetujuan.</x-slot>
    <x-slot name="actions">
        <x-ui.button variant="secondary" icon="list-bullet" :href="route('leaves.index')">Daftar cuti</x-ui.button>
        @can('sdm.leaves.create')
            <x-ui.button icon="plus" :href="route('leaves.create')">Ajukan cuti</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :sticky="false">
        <x-slot:toolbar>
            <div class="flex items-center gap-2">
                <x-ui.button variant="ghost" size="sm" icon="chevron-left" :href="route('leaves.calendar', ['bulan' => $sebelum] + $query)" aria-label="Bulan sebelumnya" />
                <h2 class="min-w-32 text-center text-sm font-semibold text-slate-900">{{ Format::bulan($start) }}</h2>
                <x-ui.button variant="ghost" size="sm" icon="chevron-right" :href="route('leaves.calendar', ['bulan' => $sesudah] + $query)" aria-label="Bulan berikutnya" />
            </div>
            @if ($semua)
                <form method="GET" class="flex items-center gap-2">
                    <input type="hidden" name="bulan" value="{{ $start->format('Y-m') }}">
                    <x-ui.select name="departemen" label="Departemen" hide-label :options="$departments" :selected="$filter['departemen'] ?? null" placeholder="Semua departemen" />
                    <x-ui.button type="submit" variant="secondary" size="sm">Terapkan</x-ui.button>
                </form>
            @endif
        </x-slot:toolbar>
        <x-slot:head>
            <th scope="col" class="sticky left-0 z-10 bg-slate-50 px-3 py-2 text-left text-xs font-semibold text-slate-600">Karyawan</th>
            @foreach ($dates as $d)
                @php
                    $libur = $holidays[$d->toDateString()] ?? null;
                    $mingguan = in_array($d->dayOfWeekIso, $weeklyOff, true);
                @endphp
                <th scope="col" title="{{ $libur }}" @class([
                    'px-0.5 py-2 text-center text-[11px] font-medium',
                    'bg-danger-50 text-danger-700' => $libur,
                    'text-slate-400' => ! $libur && $mingguan,
                    'text-slate-600' => ! $libur && ! $mingguan,
                    'ring-2 ring-inset ring-primary-400' => $d->toDateString() === $hariIni,
                ])>
                    <span class="block">{{ $d->format('j') }}</span>
                    <span class="block font-normal">{{ mb_substr($d->locale('id')->translatedFormat('D'), 0, 2) }}</span>
                </th>
            @endforeach
        </x-slot:head>
        @forelse ($employees as $e)
            <tr>
                <th scope="row" class="sticky left-0 z-10 whitespace-nowrap bg-white px-3 py-1.5 text-left text-sm font-medium text-slate-900">{{ $e->name }}</th>
                @foreach ($dates as $d)
                    @php
                        $c = $cells[$e->id.'|'.$d->toDateString()] ?? null;
                    @endphp
                    <td class="px-0.5 py-1 text-center">
                        @if ($c)
                            <a href="{{ route('leaves.show', $c) }}" title="{{ $c->jenisCuti?->nama }} · {{ $c->status->label() }} · {{ $c->rentangTanggal() }}" @class([
                                'block rounded py-1 text-[11px] font-semibold',
                                'bg-primary-100 text-primary-800' => $c->status === StatusCuti::Disetujui,
                                'border border-dashed border-warning-500 bg-warning-50 text-warning-800' => $c->status === StatusCuti::Menunggu,
                            ])>{{ mb_strtoupper(mb_substr($c->jenisCuti?->nama ?? 'C', 0, 1)) }}</a>
                        @else
                            <span class="block py-1 text-[11px] text-slate-200">·</span>
                        @endif
                    </td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="{{ $dates->count() + 1 }}"><x-ui.empty-state icon="calendar-days" title="Tidak ada karyawan" description="Akun Anda belum ditautkan ke data karyawan atau departemen." /></td>
            </tr>
        @endforelse
    </x-ui.table>

    <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-600">
        <span class="inline-flex items-center gap-1.5"><span class="rounded bg-primary-100 px-1.5 py-0.5 font-semibold text-primary-800">C</span>Disetujui (huruf awal jenis cuti)</span>
        <span class="inline-flex items-center gap-1.5"><span class="rounded border border-dashed border-warning-500 bg-warning-50 px-1.5 py-0.5 font-semibold text-warning-800">C</span>Menunggu persetujuan</span>
        <span class="inline-flex items-center gap-1.5"><span class="rounded bg-danger-50 px-1.5 py-0.5 text-danger-700">12</span>Hari libur</span>
    </div>
</x-app-layout>
