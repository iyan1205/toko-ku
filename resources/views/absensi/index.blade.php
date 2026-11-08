@php($kolom = $semuaPegawai ? 9 : 6)

<x-app-layout>
    <x-slot name="header">Absensi</x-slot>

    {{-- ---------------- Absen hari ini (pegawai) ---------------- --}}
    @if ($bisaAbsen)
        <div class="kartu mb-4 p-5">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="teks-bantu">{{ today()->locale('id')->translatedFormat('l, d F Y') }}</p>
                    {{-- Jam server (bukan jam perangkat), karena jam itulah yang tercatat saat absen. --}}
                    <p class="mt-1 text-3xl font-semibold text-slate-900 angka-rata"
                       x-data="{ jam: @js(now()->format('H:i:s')) }"
                       x-init="const selisih = {{ now()->getTimestampMs() }} - Date.now();
                               setInterval(() => jam = new Date(Date.now() + selisih).toLocaleTimeString('en-GB', { hour12: false, timeZone: @js(config('app.timezone')) }), 1000)"
                       x-text="jam">{{ now()->format('H:i:s') }}</p>

                    @if ($jadwalHariIni)
                        <p class="mt-1 text-sm text-slate-600">
                            @if ($jadwalHariIni->libur())
                                Jadwal hari ini: <strong class="text-slate-900">Libur</strong>
                            @else
                                Shift hari ini: <strong class="text-slate-900">{{ $jadwalHariIni->shift->nama }}</strong>
                                <span class="angka-rata">({{ $jadwalHariIni->shift->rentang() }})</span>
                            @endif
                        </p>
                    @endif

                    <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-600">
                        @if ($hariIni === null)
                            <span>Anda belum absen hari ini.</span>
                        @elseif ($hariIni->clock_in === null)
                            <span>Hari ini Anda tercatat</span>
                            @include('partials.badge-absensi', ['status' => $hariIni->status])
                        @else
                            <span>Masuk <strong class="text-slate-900">{{ $hariIni->clock_in->format('H:i') }}</strong></span>
                            @if ($hariIni->clock_out)
                                <span aria-hidden="true">·</span>
                                <span>Pulang <strong class="text-slate-900">{{ $hariIni->clock_out->format('H:i') }}</strong></span>
                                <span aria-hidden="true">·</span>
                                <span>{{ \App\Support\Format::durasi($hariIni->durasiMenit()) }}</span>
                            @endif
                            @include('partials.badge-absensi', ['status' => $hariIni->status])
                        @endif
                    </div>
                </div>

                <div class="shrink-0">
                    @if ($hariIni === null)
                        {{-- Lokasi GPS opsional: dikirim bila browser mengizinkan (butuh HTTPS). --}}
                        <form method="POST" action="{{ route('attendances.clock-in') }}"
                              x-data="{ lokasi: '', mengirim: false }"
                              x-init="navigator.geolocation?.getCurrentPosition(
                                  p => lokasi = p.coords.latitude.toFixed(6) + ',' + p.coords.longitude.toFixed(6),
                                  () => {}, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 })"
                              @submit="mengirim = true">
                            @csrf
                            <input type="hidden" name="lokasi" :value="lokasi">
                            <button type="submit" class="tombol-utama w-full px-6 py-3 text-base sm:w-auto" :disabled="mengirim">Absen Masuk</button>
                            <p class="mt-1 text-xs text-slate-500" x-show="lokasi" x-cloak>📍 Lokasi terdeteksi</p>
                        </form>
                    @elseif ($hariIni->clock_in !== null && $hariIni->clock_out === null)
                        <form method="POST" action="{{ route('attendances.clock-out') }}" x-data="{ mengirim: false }" @submit="mengirim = true">
                            @csrf
                            <button type="submit" class="tombol-bahaya w-full px-6 py-3 text-base sm:w-auto" :disabled="mengirim">Absen Pulang</button>
                        </form>
                    @elseif ($hariIni->clock_out !== null)
                        <span class="badge-berhasil px-3 py-1 text-sm">✓ Absensi hari ini lengkap</span>
                    @endif
                </div>
            </div>
            @error('lokasi') <p class="mt-2 text-sm text-amber-600">{{ $message }}</p> @enderror
        </div>
    @endif

    {{-- ---------------- Rekap periode ---------------- --}}
    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach (\App\Enums\StatusAbsensi::cases() as $s)
            <div class="kartu p-4">
                <p class="text-sm text-slate-500">{{ $s->label() }}</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $rekap[$s->value] }}</p>
            </div>
        @endforeach
    </div>

    <div class="kartu">
        <div class="space-y-3 border-b border-slate-200 p-4">
            <form method="GET" action="{{ route('attendances.index') }}" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                @if ($semuaPegawai)
                    <select name="pegawai" aria-label="Filter pegawai" class="isian">
                        <option value="">Semua pegawai</option>
                        @foreach ($pegawai as $p)
                            <option value="{{ $p->id }}" @selected((string) request('pegawai') === (string) $p->id)>{{ $p->name }}</option>
                        @endforeach
                    </select>
                @endif
                <select name="status" aria-label="Filter status" class="isian">
                    <option value="">Semua status</option>
                    @foreach (\App\Enums\StatusAbsensi::cases() as $s)
                        <option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>
                    @endforeach
                </select>
                <div class="flex gap-2 lg:col-span-2">
                    <input type="date" name="dari" value="{{ $filter['dari'] ?? '' }}" aria-label="Dari tanggal" class="isian">
                    <input type="date" name="sampai" value="{{ $filter['sampai'] ?? '' }}" aria-label="Sampai tanggal" class="isian">
                </div>
                <button type="submit" class="tombol-kedua">Terapkan filter</button>
            </form>
            @error('sampai') <p class="text-sm text-amber-600">{{ $message }}</p> @enderror

            @if ($semuaPegawai)
                <a href="{{ route('attendances.create') }}" class="tombol-utama">Catat absensi manual</a>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="tabel">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        @if ($semuaPegawai)
                            <th>Pegawai</th>
                        @endif
                        <th>Masuk</th>
                        <th>Pulang</th>
                        <th>Durasi</th>
                        <th>Status</th>
                        @if ($semuaPegawai)
                            <th>Perangkat</th>
                        @endif
                        <th>Keterangan</th>
                        @if ($semuaPegawai)
                            <th class="text-right">Aksi</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($absensi as $a)
                        @php($lupaPulang = $a->clock_in !== null && $a->clock_out === null && $a->tanggal->lt(today()))
                        <tr>
                            <td class="whitespace-nowrap">
                                <span class="text-slate-900">{{ $a->tanggal->format('d-m-Y') }}</span>
                                <span class="block text-xs text-slate-500">{{ $a->tanggal->locale('id')->translatedFormat('l') }}</span>
                            </td>
                            @if ($semuaPegawai)
                                <td class="font-medium text-slate-900">{{ $a->user?->name }}</td>
                            @endif
                            <td>{{ $a->clock_in?->format('H:i') ?? '-' }}</td>
                            <td>
                                @if ($lupaPulang)
                                    <span class="text-amber-700">Tidak absen pulang</span>
                                @else
                                    {{ $a->clock_out?->format('H:i') ?? '-' }}
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-slate-600">{{ \App\Support\Format::durasi($a->durasiMenit()) }}</td>
                            <td>@include('partials.badge-absensi', ['status' => $a->status])</td>
                            @if ($semuaPegawai)
                                <td class="whitespace-nowrap text-slate-600">
                                    @if ($a->device_type)
                                        {{ $a->device_type->label() }}
                                        <span class="block text-xs text-slate-500">
                                            {{ $a->ip_address ?? '-' }}
                                            @if ($a->tautanPeta())
                                                · <a href="{{ $a->tautanPeta() }}" target="_blank" rel="noopener noreferrer" class="text-slate-700 underline">Lokasi</a>
                                            @endif
                                        </span>
                                    @else
                                        <span class="text-slate-400">-</span>
                                    @endif
                                </td>
                            @endif
                            <td class="text-slate-600">
                                {{ $a->keterangan ?? '' }}
                                @if ($a->pencatat)
                                    <span class="block text-xs text-slate-400">Dicatat oleh {{ $a->pencatat->name }}</span>
                                @endif
                            </td>
                            @if ($semuaPegawai)
                                <td>
                                    <div class="flex items-center justify-end gap-3">
                                        <a href="{{ route('attendances.edit', $a) }}" class="tombol-tautan">Ubah</a>
                                        <button type="button" class="text-sm font-medium text-amber-700 hover:underline"
                                                @click="$dispatch('konfirmasi-hapus', { action: @js(route('attendances.destroy', $a)), nama: @js('Absensi ' . $a->user?->name . ' ' . $a->tanggal->format('d-m-Y')) })">
                                            Hapus
                                        </button>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $kolom }}" class="px-4 py-10 text-center text-slate-500">Belum ada absensi pada periode ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($absensi->hasPages())
            <div class="border-t border-slate-200 p-4">{{ $absensi->links() }}</div>
        @endif
    </div>
</x-app-layout>
