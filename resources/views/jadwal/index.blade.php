@php
    $akhir = $senin->addDays(6);
    $judulMinggu = $senin->month === $akhir->month
        ? $senin->format('d') . '–' . $akhir->locale('id')->translatedFormat('d F Y')
        : $senin->locale('id')->translatedFormat('d M') . ' – ' . $akhir->locale('id')->translatedFormat('d M Y');
    $hariIni = today()->toDateString();
    $shiftAktif = $shifts->where('is_active', true);
@endphp

<x-app-layout>
    <x-slot name="header">Jadwal Kerja</x-slot>

    <div class="space-y-4">
        {{-- Navigasi minggu --}}
        <div class="kartu flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-2">
                <a href="{{ route('schedules.index', ['minggu' => $senin->subWeek()->toDateString()]) }}" class="tombol-kedua px-3" aria-label="Minggu sebelumnya">‹</a>
                <p class="min-w-0 px-1 text-center font-semibold text-slate-900">{{ $judulMinggu }}</p>
                <a href="{{ route('schedules.index', ['minggu' => $senin->addWeek()->toDateString()]) }}" class="tombol-kedua px-3" aria-label="Minggu berikutnya">›</a>
                @unless ($senin->isSameDay(today()->startOfWeek()))
                    <a href="{{ route('schedules.index') }}" class="tombol-tautan ml-1">Minggu ini</a>
                @endunless
            </div>

            @if ($bisaUbah)
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('shifts.index') }}" class="tombol-kedua">Kelola shift</a>
                    <form method="POST" action="{{ route('schedules.copy') }}">
                        @csrf
                        <input type="hidden" name="minggu" value="{{ $senin->toDateString() }}">
                        <button type="submit" class="tombol-kedua" title="Hanya mengisi sel yang masih kosong">Salin dari minggu lalu</button>
                    </form>
                </div>
            @endif
        </div>

        {{-- Keterangan shift --}}
        @if ($shiftAktif->isNotEmpty())
            <div class="flex flex-wrap gap-2 text-sm text-slate-600">
                @foreach ($shiftAktif as $s)
                    <span class="badge-utilitas">{{ $s->nama }} · {{ $s->rentang() }}</span>
                @endforeach
                <span class="badge bg-teal-100 text-teal-800">Cuti disetujui</span>
            </div>
        @elseif ($bisaUbah)
            <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Belum ada shift. <a href="{{ route('shifts.index') }}" class="font-medium underline">Buat shift dulu</a>, mis. Pagi 08:00–16:00, lalu isi jadwal di sini.
            </p>
        @endif

        @error('jadwal') <p class="text-sm text-amber-600">{{ $message }}</p> @enderror

        <form method="POST" action="{{ route('schedules.update') }}" class="kartu">
            @csrf
            @method('PUT')
            <input type="hidden" name="minggu" value="{{ $senin->toDateString() }}">

            <div class="overflow-x-auto">
                <table class="tabel">
                    <thead>
                        <tr>
                            <th class="sticky left-0 z-10 bg-slate-100">Pegawai</th>
                            @foreach ($hari as $h)
                                <th @class(['min-w-[8.5rem] text-center', 'bg-amber-100 text-slate-900' => $h->toDateString() === $hariIni])>
                                    {{ $h->locale('id')->translatedFormat('D') }}
                                    <span class="block text-[11px] font-normal normal-case tracking-normal">{{ $h->format('d/m') }}</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pegawai as $p)
                            <tr>
                                <td class="sticky left-0 z-10 whitespace-nowrap bg-white font-medium text-slate-900">
                                    {{ $p->name }}
                                    @if ($p->is(auth()->user()))
                                        <span class="block text-xs font-normal text-slate-500">Anda</span>
                                    @endif
                                </td>
                                @foreach ($hari as $h)
                                    @php
                                        $tanggal = $h->toDateString();
                                        $sel = $jadwal->get($p->id . '|' . $tanggal);
                                        $namaCuti = $cuti->get($p->id . '|' . $tanggal);
                                        $nilai = $sel === null ? '' : ($sel->libur() ? 'libur' : (string) $sel->shift_id);
                                    @endphp
                                    <td @class(['text-center', 'bg-amber-50/60' => $tanggal === $hariIni])>
                                        @if ($namaCuti)
                                            <span class="badge mb-1 bg-teal-100 text-teal-800">{{ $namaCuti }}</span>
                                        @endif

                                        @if ($bisaUbah)
                                            <select name="jadwal[{{ $p->id }}][{{ $tanggal }}]" aria-label="Shift {{ $p->name }} {{ $h->format('d-m-Y') }}"
                                                    class="isian py-1.5 text-xs">
                                                <option value="">—</option>
                                                <option value="libur" @selected($nilai === 'libur')>Libur</option>
                                                @foreach ($shifts as $s)
                                                    {{-- Shift nonaktif hanya muncul di sel yang masih memakainya. --}}
                                                    @if ($s->is_active || $nilai === (string) $s->id)
                                                        <option value="{{ $s->id }}" @selected($nilai === (string) $s->id)>{{ $s->nama }} {{ $s->jam_masuk }}</option>
                                                    @endif
                                                @endforeach
                                            </select>
                                        @elseif ($sel === null)
                                            <span class="text-slate-300">—</span>
                                        @elseif ($sel->libur())
                                            <span class="text-slate-500">Libur</span>
                                        @else
                                            <span class="font-medium text-slate-900">{{ $sel->shift->nama }}</span>
                                            <span class="block text-xs text-slate-500">{{ $sel->shift->rentang() }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-10 text-center text-slate-500">Belum ada pegawai aktif.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($bisaUbah && $pegawai->isNotEmpty())
                <div class="flex flex-col gap-3 border-t border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="teks-bantu">"—" = belum dijadwalkan (penanda terlambat memakai jam masuk umum toko). "Libur" tidak pernah dihitung terlambat.</p>
                    <button type="submit" class="tombol-utama shrink-0">Simpan jadwal</button>
                </div>
            @endif
        </form>
    </div>
</x-app-layout>
