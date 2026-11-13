@php
    $edit = $jenis->exists;
    $tahapAwal = collect(old('tahap', $jenis->tahap->map(fn ($t) => [
        'tipe' => $t->approver_id ? 'orang' : 'peran',
        'peran' => $t->peran,
        'approver_id' => $t->approver_id,
    ])->all()))->map(fn ($t, $i) => [
        'uid' => $i,
        'tipe' => $t['tipe'] ?? 'peran',
        'peran' => $t['peran'] ?? \App\Enums\RoleName::Admin->value,
        'approver_id' => (string) ($t['approver_id'] ?? ''),
    ])->values();
    // Pesan galat per baris tahap (tahap.0.peran, tahap.1.approver_id, ...) untuk ditampilkan oleh Alpine.
    $galatTahap = collect($errors->getMessages())->filter(fn ($pesan, $kunci) => str_starts_with($kunci, 'tahap.'))->map(fn ($pesan) => $pesan[0]);
    $statusAwal = old('status_absensi', $jenis->status_absensi?->value);
    $aktif = $errors->any() ? (bool) old('is_active') : $jenis->is_active;
    $centang = fn (string $kolom, bool $bawaan) => $errors->any() ? (bool) old($kolom) : ($jenis->exists ? (bool) $jenis->{$kolom} : $bawaan);
    $tingkatAwal = collect(old('quota_tiers', $jenis->quota_tiers ?? []))->map(fn ($t) => ['months' => (string) ($t['months'] ?? ''), 'days' => (string) ($t['days'] ?? '')])->values();
    $input = 'block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500';
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah jenis cuti' : 'Tambah jenis cuti' }}</x-slot>

    <form method="POST" action="{{ $edit ? route('leave-types.update', $jenis) : route('leave-types.store') }}"
          x-data="{
              tahap: @js($tahapAwal),
              tingkat: @js($tingkatAwal),
              surat: @js($centang('requires_document', false)),
              galat: @js($galatTahap),
              uid: @js($tahapAwal->count()),
              tambah() { if (this.tahap.length < 5) this.tahap.push({ uid: this.uid++, tipe: 'peran', peran: @js(\App\Enums\RoleName::Admin->value), approver_id: '' }) },
              hapus(i) { this.tahap.splice(i, 1); this.galat = {} },
              naik(i) { if (i > 0) { const t = this.tahap.splice(i, 1)[0]; this.tahap.splice(i - 1, 0, t); this.galat = {} } },
          }"
          class="max-w-3xl space-y-6">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <div class="kartu space-y-5 p-6">
            <h2 class="judul-bagian">Jenis cuti</h2>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-isian label="Nama" name="nama" :value="$jenis->nama" :wajib="true" maxlength="100" placeholder="Contoh: Cuti Tahunan" />
                <x-isian label="Kuota per tahun (hari)" name="kuota_tahunan" type="number" min="1" max="366"
                         :value="$jenis->kuota_tahunan" hint="Kosongkan bila tanpa batas, mis. untuk Sakit." />
            </div>

            <fieldset>
                <legend class="label-isian">Dicatat di absensi sebagai</legend>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($statusAbsensi as $s)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm has-[:checked]:border-slate-600 has-[:checked]:bg-slate-50">
                            <input type="radio" name="status_absensi" value="{{ $s->value }}" @checked($statusAwal === $s->value)
                                   class="border-slate-300 text-slate-700 focus:ring-slate-500">
                            {{ $s->label() }}
                        </label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-slate-500">Setelah pengajuan disetujui penuh, tiap tanggalnya tercatat di absensi dengan status ini.</p>
                @error('status_absensi') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
            </fieldset>

            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="is_active" value="1" @checked($aktif)
                       class="rounded border-slate-300 text-slate-700 focus:ring-slate-500">
                Aktif (bisa dipilih saat mengajukan cuti)
            </label>
        </div>

        <div class="kartu space-y-5 p-6">
            <div>
                <h2 class="judul-bagian">Aturan</h2>
                <p class="mt-1 teks-bantu">Diperiksa saat karyawan mengajukan. Kosongkan yang tidak berlaku.</p>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="category" class="label-isian">Kategori</label>
                    <select id="category" name="category" class="isian mt-1">
                        @foreach (\App\Models\JenisCuti::CATEGORIES as $kunci => $label)
                            <option value="{{ $kunci }}" @selected(old('category', $jenis->category ?? 'other') === $kunci)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">Dipakai untuk rekap dan penggajian.</p>
                </div>
                <div>
                    <label for="gender" class="label-isian">Khusus karyawan</label>
                    <select id="gender" name="gender" class="isian mt-1">
                        <option value="">Semua</option>
                        @foreach (\App\Enums\Gender::cases() as $g)
                            <option value="{{ $g->value }}" @selected(old('gender', $jenis->gender?->value) === $g->value)>{{ $g->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <x-isian label="Paling lama per pengajuan (hari)" name="max_days_per_request" type="number" min="1" max="366" :value="$jenis->max_days_per_request" hint="Misal 90 untuk melahirkan, 3 untuk menikah." />
                <x-isian label="Masa kerja minimal (bulan)" name="min_service_months" type="number" min="0" max="600" :value="$jenis->min_service_months ?: ''" hint="Misal 12 untuk cuti tahunan." />
            </div>

            <div class="grid gap-3">
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="hidden" name="is_paid" value="0">
                    <input type="checkbox" name="is_paid" value="1" @checked($centang('is_paid', true)) class="rounded border-slate-300 text-slate-700 focus:ring-slate-500">
                    Dibayar (hilangkan centang untuk cuti tanpa upah)
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="hidden" name="working_days_only" value="0">
                    <input type="checkbox" name="working_days_only" value="1" @checked($centang('working_days_only', true)) class="rounded border-slate-300 text-slate-700 focus:ring-slate-500">
                    Hitung hari kerja saja (libur mingguan dan hari libur tidak memotong saldo)
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="hidden" name="requires_document" value="0">
                    <input type="checkbox" name="requires_document" value="1" x-model="surat" class="rounded border-slate-300 text-slate-700 focus:ring-slate-500">
                    Wajib surat keterangan (misal surat dokter)
                </label>
                <div x-show="surat" x-cloak class="pl-6 sm:max-w-xs">
                    <x-isian label="Wajib bila lebih dari (hari)" name="document_after_days" type="number" min="0" max="366" :value="$jenis->document_after_days ?? 0" hint="0 = selalu wajib." />
                </div>
            </div>

            <div>
                <p class="label-isian">Saldo per masa kerja</p>
                <p class="teks-bantu">Opsional, untuk cuti tahunan yang bertambah sesuai masa kerja. Tanpa baris = kuota per tahun di atas.</p>
                <template x-for="(t, i) in tingkat" x-bind:key="i">
                    <div class="mt-2 flex items-center gap-2 text-sm">
                        <span class="text-slate-500">Masa kerja ≥</span>
                        <input type="number" min="0" max="600" x-bind:name="'quota_tiers[' + i + '][months]'" x-model="t.months" class="{{ $input }} w-20" x-bind:aria-label="'Masa kerja tingkat ' + (i + 1)">
                        <span class="text-slate-500">bulan →</span>
                        <input type="number" min="0" max="366" x-bind:name="'quota_tiers[' + i + '][days]'" x-model="t.days" class="{{ $input }} w-20" x-bind:aria-label="'Hari tingkat ' + (i + 1)">
                        <span class="text-slate-500">hari setahun</span>
                        <button type="button" class="text-xs font-medium text-amber-700 hover:underline" x-on:click="tingkat.splice(i, 1)">Hapus</button>
                    </div>
                </template>
                <button type="button" class="tombol-tautan mt-2" x-on:click="tingkat.push({ months: '', days: '' })" x-bind:disabled="tingkat.length >= 6">+ Tambah tingkat</button>
                @error('quota_tiers') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
                @error('quota_tiers.*.months') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
                @error('quota_tiers.*.days') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="kartu space-y-4 p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="judul-bagian">Alur persetujuan</h2>
                    <p class="mt-1 teks-bantu">Diputuskan berurutan. Satu penolakan mengakhiri pengajuan.</p>
                </div>
                <button type="button" @click="tambah()" class="tombol-kedua" :disabled="tahap.length >= 5">+ Tambah tahap</button>
            </div>

            <template x-for="(t, i) in tahap" :key="t.uid">
                <div class="rounded-lg border border-slate-200 p-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-slate-700 text-xs font-semibold text-white" x-text="i + 1"></span>
                        <select :name="`tahap[${i}][tipe]`" x-model="t.tipe" aria-label="Jenis approver" class="isian w-auto">
                            <option value="peran">Peran</option>
                            <option value="orang">Orang tertentu</option>
                        </select>

                        {{-- Pilihan yang tidak dipakai dinonaktifkan supaya tidak ikut terkirim. --}}
                        <select x-show="t.tipe === 'peran'" :disabled="t.tipe !== 'peran'" :name="`tahap[${i}][peran]`" x-model="t.peran"
                                aria-label="Peran approver" class="isian w-auto min-w-[10rem] flex-1">
                            @foreach ($peran as $r)
                                <option value="{{ $r }}">Semua {{ $r }}</option>
                            @endforeach
                        </select>
                        <select x-show="t.tipe === 'orang'" x-cloak :disabled="t.tipe !== 'orang'" :name="`tahap[${i}][approver_id]`" x-model="t.approver_id"
                                aria-label="Orang yang menyetujui" class="isian w-auto min-w-[10rem] flex-1">
                            <option value="">Pilih orang</option>
                            @foreach ($pengguna as $u)
                                <option value="{{ $u->id }}">{{ $u->name }}{{ $u->is_active ? '' : ' (nonaktif)' }}</option>
                            @endforeach
                        </select>

                        <div class="ml-auto flex items-center gap-1">
                            <button type="button" @click="naik(i)" x-show="i > 0" class="tombol-kedua px-2 py-1.5" aria-label="Naikkan tahap">↑</button>
                            <button type="button" @click="hapus(i)" class="px-2 py-1.5 text-sm font-medium text-amber-700 hover:underline">Hapus</button>
                        </div>
                    </div>
                    <p class="mt-2 text-sm text-amber-600" x-show="galat[`tahap.${i}.peran`] || galat[`tahap.${i}.approver_id`]"
                       x-text="galat[`tahap.${i}.peran`] || galat[`tahap.${i}.approver_id`]"></p>
                </div>
            </template>

            <p x-show="tahap.length === 0" class="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">
                Tanpa tahap: pengajuan jenis ini langsung disetujui dan tercatat di absensi.
            </p>
            @error('tahap') <p class="text-sm text-amber-600">{{ $message }}</p> @enderror

            <ul class="list-disc space-y-1 pl-5 text-xs text-slate-500">
                <li>"Peran": siapa pun dengan peran itu boleh memutuskan, kecuali pemohon sendiri.</li>
                <li>Super Admin dapat memutuskan tahap mana pun bila approver berhalangan.</li>
                <li>Perubahan alur hanya berlaku untuk pengajuan baru.</li>
            </ul>
        </div>

        <div class="flex justify-end gap-2">
            <a href="{{ route('leave-types.index') }}" class="tombol-kedua">Batal</a>
            <button type="submit" class="tombol-utama">{{ $edit ? 'Simpan perubahan' : 'Simpan jenis cuti' }}</button>
        </div>
    </form>
</x-app-layout>
