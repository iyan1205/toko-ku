<x-app-layout>
    <x-slot name="header">Ajukan cuti</x-slot>

    @if ($jenisCuti->isEmpty())
        <div class="kartu max-w-2xl p-6 text-sm text-slate-600">
            Belum ada jenis cuti yang aktif. Minta Super Admin menambahkannya di menu Jenis Cuti.
        </div>
    @else
        <form method="POST" action="{{ route('leaves.store') }}"
              x-data="{
                  jenis: @js($jenisCuti),
                  jenisId: @js((string) old('jenis_cuti_id', $jenisCuti->count() === 1 ? $jenisCuti->first()['id'] : '')),
                  mulai: @js(old('tanggal_mulai', today()->toDateString())),
                  selesai: @js(old('tanggal_selesai', today()->toDateString())),
                  mengirim: false,
                  get dipilih() { return this.jenis.find(j => String(j.id) === this.jenisId) },
                  get hari() {
                      if (! this.mulai || ! this.selesai) return 0;
                      const selisih = (new Date(this.selesai) - new Date(this.mulai)) / 86400000;
                      return selisih >= 0 ? Math.round(selisih) + 1 : 0;
                  },
                  get sisa() {
                      if (! this.dipilih || this.dipilih.sisa === null || ! this.mulai) return null;
                      return this.dipilih.sisa[Number(this.mulai.slice(0, 4))] ?? this.dipilih.kuota;
                  },
              }"
              @submit="mengirim = true"
              class="kartu max-w-2xl space-y-5 p-6">
            @csrf

            <div>
                <label for="jenis_cuti_id" class="label-isian">Jenis cuti</label>
                <select id="jenis_cuti_id" name="jenis_cuti_id" x-model="jenisId" required
                        @class(['isian mt-1', 'isian-salah' => $errors->has('jenis_cuti_id')])>
                    <option value="">Pilih jenis cuti</option>
                    @foreach ($jenisCuti as $j)
                        <option value="{{ $j['id'] }}">{{ $j['nama'] }}</option>
                    @endforeach
                </select>
                @error('jenis_cuti_id') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror

                <div class="mt-2 space-y-1 text-sm text-slate-600" x-show="dipilih" x-cloak>
                    <p x-show="sisa !== null">
                        Sisa kuota tahun <span x-text="mulai.slice(0, 4)"></span>:
                        <strong class="text-slate-900" x-text="sisa + ' hari'"></strong>
                    </p>
                    <p>
                        Disetujui oleh:
                        <span x-text="dipilih && dipilih.alur.length ? dipilih.alur.map((a, i) => (i + 1) + '. ' + a).join(' → ') : 'langsung disetujui'"></span>
                    </p>
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="tanggal_mulai" class="label-isian">Tanggal mulai</label>
                    <input id="tanggal_mulai" name="tanggal_mulai" type="date" x-model="mulai" required
                           @change="if (selesai < mulai) selesai = mulai"
                           @class(['isian mt-1', 'isian-salah' => $errors->has('tanggal_mulai')])>
                    @error('tanggal_mulai') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="tanggal_selesai" class="label-isian">Tanggal selesai</label>
                    <input id="tanggal_selesai" name="tanggal_selesai" type="date" x-model="selesai" :min="mulai" required
                           @class(['isian mt-1', 'isian-salah' => $errors->has('tanggal_selesai')])>
                    @error('tanggal_selesai') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <p class="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">
                Lama cuti: <strong class="text-slate-900" x-text="hari + ' hari'"></strong> (hari kalender, termasuk Sabtu dan Minggu).
                <span class="mt-1 block text-amber-700" x-show="sisa !== null && hari > sisa" x-cloak>Melebihi sisa kuota, pengajuan akan ditolak.</span>
            </p>

            <div>
                <label for="alasan" class="label-isian">Alasan</label>
                <textarea id="alasan" name="alasan" rows="3" maxlength="500" required placeholder="Contoh: acara keluarga di luar kota"
                          @class(['isian mt-1', 'isian-salah' => $errors->has('alasan')])>{{ old('alasan') }}</textarea>
                @error('alasan') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-100 pt-5">
                <a href="{{ route('leaves.index') }}" class="tombol-kedua">Batal</a>
                <button type="submit" class="tombol-utama" :disabled="mengirim">Kirim pengajuan</button>
            </div>
        </form>
    @endif
</x-app-layout>
