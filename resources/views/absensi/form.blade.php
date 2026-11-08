@php
    $edit = $absensi->exists;
    $statusAwal = old('status', $absensi->status?->value ?? \App\Enums\StatusAbsensi::Izin->value);
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah absensi' : 'Catat absensi manual' }}</x-slot>

    <form method="POST" action="{{ $edit ? route('attendances.update', $absensi) : route('attendances.store') }}"
          x-data="{ status: @js($statusAwal), pakaiJam: @js(\App\Enums\StatusAbsensi::valuesPakaiJam()) }"
          class="kartu max-w-2xl space-y-5 p-6">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        @if ($edit && $absensi->device_type)
            <p class="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">
                Diabsen sendiri oleh pegawai lewat {{ $absensi->device_type->label() }}
                pukul {{ $absensi->clock_in?->format('H:i') ?? '-' }}{{ $absensi->ip_address ? ', IP ' . $absensi->ip_address : '' }}.
                Perubahan di sini tercatat atas nama Anda.
            </p>
        @endif

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="user_id" class="label-isian">Pegawai</label>
                <select id="user_id" name="user_id" required @class(['isian mt-1', 'isian-salah' => $errors->has('user_id')])>
                    <option value="">Pilih pegawai</option>
                    @foreach ($pegawai as $p)
                        <option value="{{ $p->id }}" @selected((string) old('user_id', $absensi->user_id) === (string) $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
                @error('user_id') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="tanggal" class="label-isian">Tanggal</label>
                <input id="tanggal" name="tanggal" type="date" required
                       value="{{ old('tanggal', $absensi->tanggal?->toDateString()) }}"
                       :max="pakaiJam.includes(status) ? @js(today()->toDateString()) : null"
                       @class(['isian mt-1', 'isian-salah' => $errors->has('tanggal')])>
                @error('tanggal') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <fieldset>
            <legend class="label-isian">Status</legend>
            <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                @foreach (\App\Enums\StatusAbsensi::cases() as $s)
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm has-[:checked]:border-slate-600 has-[:checked]:bg-slate-50">
                        <input type="radio" name="status" value="{{ $s->value }}" x-model="status" @checked($statusAwal === $s->value)
                               class="border-slate-300 text-slate-700 focus:ring-slate-500">
                        {{ $s->label() }}
                    </label>
                @endforeach
            </div>
            @error('status') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
        </fieldset>

        {{-- Jam hanya untuk Hadir/Terlambat. Untuk Izin/Sakit/Alpa diabaikan server dan dikosongkan. --}}
        <div class="grid gap-5 sm:grid-cols-2" x-show="pakaiJam.includes(status)" x-cloak>
            <div>
                <label for="jam_masuk" class="label-isian">Jam masuk</label>
                <input id="jam_masuk" name="jam_masuk" type="time" :required="pakaiJam.includes(status)"
                       value="{{ old('jam_masuk', $absensi->clock_in?->format('H:i')) }}"
                       @class(['isian mt-1', 'isian-salah' => $errors->has('jam_masuk')])>
                @error('jam_masuk') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="jam_pulang" class="label-isian">Jam pulang <span class="font-normal text-slate-400">(opsional)</span></label>
                <input id="jam_pulang" name="jam_pulang" type="time"
                       value="{{ old('jam_pulang', $absensi->clock_out?->format('H:i')) }}"
                       @class(['isian mt-1', 'isian-salah' => $errors->has('jam_pulang')])>
                @error('jam_pulang') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label for="keterangan" class="label-isian">Keterangan <span class="font-normal text-slate-400">(opsional)</span></label>
            <textarea id="keterangan" name="keterangan" rows="2" maxlength="255"
                      placeholder="Contoh: izin acara keluarga, lupa absen pulang"
                      @class(['isian mt-1', 'isian-salah' => $errors->has('keterangan')])>{{ old('keterangan', $absensi->keterangan) }}</textarea>
            @error('keterangan') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex justify-end gap-2 border-t border-slate-100 pt-5">
            <a href="{{ route('attendances.index') }}" class="tombol-kedua">Batal</a>
            <button type="submit" class="tombol-utama">{{ $edit ? 'Simpan perubahan' : 'Simpan absensi' }}</button>
        </div>
    </form>
</x-app-layout>
