@php
    $baris = $shifts->mapWithKeys(fn ($s) => [$s->id => [
        'nama' => $s->nama, 'jam_masuk' => $s->jam_masuk, 'jam_pulang' => $s->jam_pulang, 'is_active' => $s->is_active,
        'late_tolerance_minutes' => $s->late_tolerance_minutes, 'early_leave_tolerance_minutes' => $s->early_leave_tolerance_minutes,
        'break_minutes' => $s->break_minutes, 'color' => $s->color ?? 'primary',
    ]]);
    $isianAwal = [
        'nama' => old('nama', ''),
        'jam_masuk' => old('jam_masuk', '08:00'),
        'jam_pulang' => old('jam_pulang', '16:00'),
        'late_tolerance_minutes' => old('late_tolerance_minutes', ''),
        'early_leave_tolerance_minutes' => old('early_leave_tolerance_minutes', ''),
        'break_minutes' => old('break_minutes', 60),
        'color' => old('color', 'primary'),
        // Kotak centang yang tidak dicentang tidak ikut terkirim, jadi setelah gagal validasi "tidak ada" = nonaktif.
        'is_active' => $errors->any() ? (bool) old('is_active') : true,
    ];
@endphp

<x-app-layout>
    <x-slot name="header">Shift</x-slot>

    <div x-data="{
            buka: @js($errors->any()),
            editId: @js(old('_edit_id')),
            isian: @js($isianAwal),
            baris: @js($baris),
            storeUrl: @js(route('shifts.store')),
            updateUrl: @js(route('shifts.update', '__ID__')),
            get action() { return this.editId ? this.updateUrl.replace('__ID__', this.editId) : this.storeUrl },
            get lintasHari() { return this.isian.jam_masuk && this.isian.jam_pulang && this.isian.jam_pulang < this.isian.jam_masuk },
            tambah() { this.editId = null; this.isian = { nama: '', jam_masuk: '08:00', jam_pulang: '16:00', late_tolerance_minutes: '', early_leave_tolerance_minutes: '', break_minutes: 60, color: 'primary', is_active: true }; this.buka = true },
            ubah(id) { this.editId = id; this.isian = { ...this.baris[id] }; this.buka = true },
         }"
         x-on:keydown.escape.window="buka = false">

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="teks-bantu">Jam kerja yang dipilih saat mengisi <a href="{{ route('schedules.index') }}" class="tombol-tautan">jadwal</a>. Absen masuk lewat jam masuk shift tercatat Terlambat.</p>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('shift-rotations.index') }}" class="tombol-kedua">Pola rotasi</a>
                <a href="{{ route('schedules.month') }}" class="tombol-kedua">Jadwal bulanan</a>
                @can('sdm.shifts.create')
                    <button type="button" @click="tambah()" class="tombol-utama">Tambah shift</button>
                @endcan
            </div>
        </div>

        <div class="kartu mt-4 overflow-x-auto">
            <table class="tabel">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Jam kerja</th>
                        <th class="text-right">Dipakai di jadwal</th>
                        <th>Status</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($shifts as $s)
                        <tr>
                            <td class="font-medium text-slate-900"><x-ui.badge :color="$s->color ?? 'primary'" dot>{{ $s->nama }}</x-ui.badge></td>
                            <td class="whitespace-nowrap">
                                {{ $s->rentang() }}
                                @if ($s->lintasHari())
                                    <span class="block text-xs text-slate-500">Pulang keesokan harinya</span>
                                @endif
                                <span class="block text-xs text-slate-500">
                                    Telat &gt; {{ $s->lateTolerance() }} mnt{{ $s->late_tolerance_minutes === null ? ' (bawaan)' : '' }}
                                    · istirahat {{ $s->break_minutes }} mnt · kerja {{ intdiv($s->workMinutes(), 60) }} j {{ $s->workMinutes() % 60 ? ($s->workMinutes() % 60).' m' : '' }}
                                </span>
                            </td>
                            <td class="text-right text-slate-600">{{ $s->jadwals_count }}</td>
                            <td>
                                @if ($s->is_active)
                                    <span class="badge-berhasil">Aktif</span>
                                @else
                                    <span class="badge-utilitas">Nonaktif</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex items-center justify-end gap-3">
                                    <button type="button" class="tombol-tautan" @click="ubah({{ $s->id }})">Ubah</button>
                                    <button type="button" class="text-sm font-medium text-amber-700 hover:underline"
                                            @click="$dispatch('konfirmasi-hapus', { action: @js(route('shifts.destroy', $s)), nama: @js('Shift ' . $s->nama) })">
                                        Hapus
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-slate-500">
                                Belum ada shift. Klik "Tambah shift" untuk membuat yang pertama, mis. Pagi 08:00–16:00.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Modal tambah/ubah --}}
        <div x-show="buka" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4"
             role="dialog" aria-modal="true" aria-labelledby="judul-modal">
            <div @click.outside="buka = false" class="w-full max-w-md rounded-card bg-white p-6 shadow-float">
                <h2 id="judul-modal" class="text-lg font-semibold text-slate-900" x-text="editId ? 'Ubah shift' : 'Tambah shift'"></h2>

                <form method="POST" :action="action" class="mt-4 space-y-4">
                    @csrf
                    <template x-if="editId"><input type="hidden" name="_method" value="PUT"></template>
                    <input type="hidden" name="_edit_id" :value="editId ?? ''">

                    <div>
                        <label for="nama" class="label-isian">Nama shift</label>
                        <input id="nama" name="nama" type="text" x-model="isian.nama" required maxlength="50" placeholder="Contoh: Pagi"
                               @class(['isian mt-1', 'isian-salah' => $errors->has('nama')])>
                        @error('nama') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="jam_masuk" class="label-isian">Jam masuk</label>
                            <input id="jam_masuk" name="jam_masuk" type="time" x-model="isian.jam_masuk" required
                                   @class(['isian mt-1', 'isian-salah' => $errors->has('jam_masuk')])>
                            @error('jam_masuk') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="jam_pulang" class="label-isian">Jam pulang</label>
                            <input id="jam_pulang" name="jam_pulang" type="time" x-model="isian.jam_pulang" required
                                   @class(['isian mt-1', 'isian-salah' => $errors->has('jam_pulang')])>
                            @error('jam_pulang') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <p class="text-xs text-slate-500" x-show="lintasHari" x-cloak>Jam pulang lebih awal dari jam masuk: shift ini selesai keesokan harinya.</p>

                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label for="late_tolerance_minutes" class="label-isian">Toleransi telat (menit)</label>
                            <input id="late_tolerance_minutes" name="late_tolerance_minutes" type="number" min="0" max="240" x-model="isian.late_tolerance_minutes" placeholder="Bawaan" class="isian mt-1">
                        </div>
                        <div>
                            <label for="early_leave_tolerance_minutes" class="label-isian">Toleransi pulang cepat</label>
                            <input id="early_leave_tolerance_minutes" name="early_leave_tolerance_minutes" type="number" min="0" max="240" x-model="isian.early_leave_tolerance_minutes" placeholder="Bawaan" class="isian mt-1">
                        </div>
                        <div>
                            <label for="break_minutes" class="label-isian">Istirahat (menit)</label>
                            <input id="break_minutes" name="break_minutes" type="number" min="0" max="480" x-model="isian.break_minutes" class="isian mt-1">
                        </div>
                    </div>
                    @error('late_tolerance_minutes') <p class="text-sm text-amber-600">{{ $message }}</p> @enderror
                    <div>
                        <label for="color" class="label-isian">Warna di kalender</label>
                        <select id="color" name="color" x-model="isian.color" class="isian mt-1">
                            @foreach (\App\Models\Shift::COLORS as $kunci => $nama)
                                <option value="{{ $kunci }}">{{ $nama }}</option>
                            @endforeach
                        </select>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="is_active" value="1" x-model="isian.is_active"
                               class="rounded border-slate-300 text-slate-700 focus:ring-slate-500">
                        Aktif (bisa dipilih di jadwal)
                    </label>

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="buka = false" class="tombol-kedua">Batal</button>
                        <button type="submit" class="tombol-utama">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
