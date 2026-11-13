{{-- Tambah / ubah karyawan: data pribadi, pajak & BPJS, rekening, kontak darurat, penempatan, keluarga, pendidikan, pengalaman, akun login. --}}
@use('App\Enums\EducationLevel')
@use('App\Enums\EmploymentType')
@use('App\Enums\FamilyRelation')
@use('App\Enums\Gender')
@use('App\Enums\MaritalStatus')
@use('App\Enums\PtkpStatus')
@php
    $edit = $employee->exists;
    $opsi = fn (string $enum) => collect($enum::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]);
    $kelas = 'block w-full min-w-0 rounded-lg border border-slate-300 text-sm shadow-sm placeholder:text-slate-400 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500';
    $keluarga = old('family', $employee->familyMembers->map(fn ($f) => [
        'name' => $f->name, 'relation' => $f->relation->value, 'identity_number' => $f->identity_number,
        'birth_date' => $f->birth_date?->format('Y-m-d'), 'occupation' => $f->occupation, 'is_dependent' => $f->is_dependent, 'phone' => $f->phone,
    ])->all());
    $keluarga = array_map(fn ($r) => ['is_dependent' => filter_var($r['is_dependent'] ?? false, FILTER_VALIDATE_BOOLEAN)] + (array) $r, (array) $keluarga);
    $pendidikan = old('educations', $employee->educations->map(fn ($e) => [
        'level' => $e->level->value, 'institution' => $e->institution, 'major' => $e->major, 'start_year' => $e->start_year, 'end_year' => $e->end_year,
    ])->all());
    $pengalaman = old('experiences', $employee->experiences->map(fn ($e) => [
        'company' => $e->company, 'position' => $e->position, 'start_date' => $e->start_date?->format('Y-m-d'), 'end_date' => $e->end_date?->format('Y-m-d'), 'description' => $e->description,
    ])->all());
    $galatBaris = collect($errors->getMessages())->filter(fn ($m, $k) => preg_match('/^(family|educations|experiences)\./', $k))->flatten()->unique();
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah karyawan' : 'Tambah karyawan' }}</x-slot>
    <x-slot name="description">{{ $edit ? $employee->name.' · '.$employee->code : 'Data karyawan untuk absensi, jadwal, cuti, dan penggajian.' }}</x-slot>

    <form method="POST" action="{{ $edit ? route('employees.update', $employee) : route('employees.store') }}" enctype="multipart/form-data" class="grid gap-8">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <x-ui.form-section title="Identitas" description="Kode dibuat otomatis (misal KRY-0001) bila dikosongkan.">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="code" label="Kode karyawan" :value="$employee->code" maxlength="20" style="text-transform: uppercase" :placeholder="$edit ? null : 'Otomatis'" />
                <x-ui.input name="name" label="Nama lengkap" :value="$employee->name" maxlength="150" required class="sm:col-span-2" />
            </div>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.select name="gender" label="Jenis kelamin" :options="$opsi(Gender::class)" :selected="$employee->gender?->value" placeholder="Pilih" />
                <x-ui.input name="photo" label="Foto" type="file" accept="image/jpeg,image/png,image/webp" hint="JPG/PNG/WebP, maks 2 MB." class="sm:col-span-2" />
            </div>
            @if ($edit && $employee->photo_path)
                <div class="flex items-center gap-3">
                    <img src="{{ route('employees.photo', $employee) }}" alt="Foto {{ $employee->name }}" class="h-14 w-14 rounded-full object-cover">
                    <x-ui.checkbox name="remove_photo" label="Hapus foto" />
                </div>
            @endif
        </x-ui.form-section>

        <x-ui.form-section title="Data pribadi">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="identity_number" label="NIK (KTP)" :value="$employee->identity_number" maxlength="30" inputmode="numeric" />
                <x-ui.input name="birth_place" label="Tempat lahir" :value="$employee->birth_place" maxlength="100" />
                <x-ui.input name="birth_date" label="Tanggal lahir" type="date" :value="$employee->birth_date?->format('Y-m-d')" />
            </div>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.select name="religion" label="Agama" :options="array_combine(config('sdm.religions'), config('sdm.religions'))" :selected="$employee->religion" placeholder="Pilih" />
                <x-ui.select name="marital_status" label="Status pernikahan" :options="$opsi(MaritalStatus::class)" :selected="$employee->marital_status?->value" placeholder="Pilih" />
                <x-ui.select name="blood_type" label="Golongan darah" :options="array_combine(config('sdm.blood_types'), config('sdm.blood_types'))" :selected="$employee->blood_type" placeholder="Pilih" />
            </div>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="phone" label="Telepon" :value="$employee->phone" maxlength="25" />
                <x-ui.input name="email" label="Email" type="email" :value="$employee->email" maxlength="150" />
                <x-ui.input name="address" label="Alamat" :value="$employee->address" maxlength="255" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Pajak & BPJS" description="Dipakai penggajian. Nilai PTKP dan tarif BPJS diatur terpisah dengan tanggal berlaku.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input name="tax_id" label="NPWP" :value="$employee->tax_id" maxlength="25" inputmode="numeric" hint="15 atau 16 angka; titik dan tanda hubung boleh." />
                <x-ui.select name="ptkp_status" label="Status PTKP" :options="$opsi(PtkpStatus::class)" :selected="$employee->ptkp_status?->value" placeholder="Pilih" hint="TK = tidak kawin, K = kawin, angka = jumlah tanggungan." />
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input name="bpjs_health_number" label="Nomor BPJS Kesehatan" :value="$employee->bpjs_health_number" maxlength="30" inputmode="numeric" />
                <x-ui.input name="bpjs_employment_number" label="Nomor BPJS Ketenagakerjaan" :value="$employee->bpjs_employment_number" maxlength="30" inputmode="numeric" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Rekening bank & kontak darurat">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="bank_name" label="Bank" :value="$employee->bank_name" maxlength="100" list="daftar-bank" />
                <x-ui.input name="bank_account_number" label="Nomor rekening" :value="$employee->bank_account_number" maxlength="40" inputmode="numeric" />
                <x-ui.input name="bank_account_name" label="Atas nama" :value="$employee->bank_account_name" maxlength="150" />
            </div>
            <datalist id="daftar-bank">
                @foreach (config('sdm.banks') as $bank)
                    <option value="{{ $bank }}"></option>
                @endforeach
            </datalist>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="emergency_contact_name" label="Kontak darurat" :value="$employee->emergency_contact_name" maxlength="150" />
                <x-ui.input name="emergency_contact_relation" label="Hubungan" :value="$employee->emergency_contact_relation" maxlength="50" placeholder="Misal: istri, ayah" />
                <x-ui.input name="emergency_contact_phone" label="Telepon kontak darurat" :value="$employee->emergency_contact_phone" maxlength="25" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Kepegawaian" :description="$edit ? 'Perubahan penempatan dengan SK sebaiknya dicatat sebagai mutasi/promosi di halaman detail; riwayat kontrak juga di sana.' : 'Departemen dan jabatan diatur di menu SDM → Departemen & Jabatan.'">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.select name="branch_id" label="Cabang" :options="$branches->pluck('name', 'id')" :selected="$employee->branch_id" placeholder="Belum ditentukan" />
                <x-ui.select name="department_id" label="Departemen" :options="$departments->pluck('name', 'id')" :selected="$employee->department_id" placeholder="Belum ditentukan" />
                <x-ui.select name="position_id" label="Jabatan" :options="$positions->pluck('name', 'id')" :selected="$employee->position_id" placeholder="Belum ditentukan" />
            </div>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.select name="supervisor_id" label="Atasan langsung" :options="$supervisors->mapWithKeys(fn ($s) => [$s->id => $s->name.' · '.$s->code])" :selected="$employee->supervisor_id" placeholder="Tidak ada" />
                <x-ui.input name="grade" label="Grade / golongan" :value="$employee->grade" maxlength="20" />
                <x-ui.select name="employment_type" label="Status kerja" required :options="$opsi(EmploymentType::class)" :selected="$employee->employment_type?->value" />
            </div>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="join_date" label="Mulai kerja" type="date" :value="$employee->join_date?->format('Y-m-d')" />
                <x-ui.input name="end_date" label="Berhenti kerja" type="date" :value="$employee->end_date?->format('Y-m-d')" hint="Kosongkan bila masih bekerja. Untuk resign/PHK pakai Offboarding." />
                <x-ui.input name="attendance_pin" label="PIN mesin absen" :value="$employee->attendance_pin" maxlength="30" hint="ID karyawan di mesin fingerprint, untuk impor data absen." />
            </div>
            <x-ui.checkbox name="is_active" label="Karyawan aktif" :checked="$employee->is_active ?? true" unchecked-value="0" />
        </x-ui.form-section>

        <x-ui.form-section title="Keluarga, pendidikan, pengalaman" description="Baris yang namanya kosong diabaikan. Tanggungan dipakai untuk menentukan status PTKP.">
            @if ($galatBaris->isNotEmpty())
                <x-ui.alert type="danger">{{ $galatBaris->join(' ') }}</x-ui.alert>
            @endif

            <div x-data="{ rows: @js(array_values($keluarga)) }" class="grid gap-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-800">Keluarga</h3>
                    <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="rows.push({ relation: 'child', is_dependent: false })">Tambah anggota</x-ui.button>
                </div>
                <template x-for="(row, i) in rows" :key="i">
                    <div class="grid gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-12">
                        <input type="text" x-bind:name="`family[${i}][name]`" x-model="row.name" placeholder="Nama" maxlength="150" aria-label="Nama anggota keluarga" class="{{ $kelas }} sm:col-span-3">
                        <select x-bind:name="`family[${i}][relation]`" x-model="row.relation" aria-label="Hubungan" class="{{ $kelas }} sm:col-span-2">
                            @foreach ($opsi(FamilyRelation::class) as $v => $l)
                                <option value="{{ $v }}">{{ $l }}</option>
                            @endforeach
                        </select>
                        <input type="date" x-bind:name="`family[${i}][birth_date]`" x-model="row.birth_date" aria-label="Tanggal lahir" class="{{ $kelas }} sm:col-span-2">
                        <input type="text" x-bind:name="`family[${i}][identity_number]`" x-model="row.identity_number" placeholder="NIK" inputmode="numeric" maxlength="30" aria-label="NIK anggota keluarga" class="{{ $kelas }} sm:col-span-2">
                        <input type="text" x-bind:name="`family[${i}][occupation]`" x-model="row.occupation" placeholder="Pekerjaan" maxlength="100" aria-label="Pekerjaan" class="{{ $kelas }} sm:col-span-2">
                        <div class="flex items-center justify-between gap-2 sm:col-span-1">
                            <label class="flex items-center gap-1 text-xs text-slate-600">
                                <input type="hidden" x-bind:name="`family[${i}][is_dependent]`" value="0">
                                <input type="checkbox" x-bind:name="`family[${i}][is_dependent]`" value="1" x-model="row.is_dependent" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500">
                                Tanggungan
                            </label>
                            <button type="button" x-on:click="rows.splice(i, 1)" class="text-slate-400 hover:text-danger-600" aria-label="Hapus baris"><x-ui.icon name="x-mark" variant="m" class="h-4 w-4" /></button>
                        </div>
                    </div>
                </template>
                <p x-show="rows.length === 0" class="text-sm text-slate-500">Belum ada data keluarga.</p>
            </div>

            <div x-data="{ rows: @js(array_values($pendidikan)) }" class="grid gap-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-800">Pendidikan</h3>
                    <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="rows.push({ level: 'sma' })">Tambah pendidikan</x-ui.button>
                </div>
                <template x-for="(row, i) in rows" :key="i">
                    <div class="grid gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-12">
                        <select x-bind:name="`educations[${i}][level]`" x-model="row.level" aria-label="Jenjang" class="{{ $kelas }} sm:col-span-2">
                            @foreach ($opsi(EducationLevel::class) as $v => $l)
                                <option value="{{ $v }}">{{ $l }}</option>
                            @endforeach
                        </select>
                        <input type="text" x-bind:name="`educations[${i}][institution]`" x-model="row.institution" placeholder="Sekolah / kampus" maxlength="150" aria-label="Sekolah atau kampus" class="{{ $kelas }} sm:col-span-4">
                        <input type="text" x-bind:name="`educations[${i}][major]`" x-model="row.major" placeholder="Jurusan" maxlength="100" aria-label="Jurusan" class="{{ $kelas }} sm:col-span-3">
                        <input type="number" x-bind:name="`educations[${i}][start_year]`" x-model="row.start_year" placeholder="Masuk" min="1950" max="2100" aria-label="Tahun masuk" class="{{ $kelas }} sm:col-span-1">
                        <input type="number" x-bind:name="`educations[${i}][end_year]`" x-model="row.end_year" placeholder="Lulus" min="1950" max="2100" aria-label="Tahun lulus" class="{{ $kelas }} sm:col-span-1">
                        <button type="button" x-on:click="rows.splice(i, 1)" class="justify-self-end text-slate-400 hover:text-danger-600 sm:col-span-1" aria-label="Hapus baris"><x-ui.icon name="x-mark" variant="m" class="h-4 w-4" /></button>
                    </div>
                </template>
                <p x-show="rows.length === 0" class="text-sm text-slate-500">Belum ada riwayat pendidikan.</p>
            </div>

            <div x-data="{ rows: @js(array_values($pengalaman)) }" class="grid gap-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-800">Pengalaman kerja</h3>
                    <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="rows.push({})">Tambah pengalaman</x-ui.button>
                </div>
                <template x-for="(row, i) in rows" :key="i">
                    <div class="grid gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-12">
                        <input type="text" x-bind:name="`experiences[${i}][company]`" x-model="row.company" placeholder="Perusahaan" maxlength="150" aria-label="Perusahaan" class="{{ $kelas }} sm:col-span-3">
                        <input type="text" x-bind:name="`experiences[${i}][position]`" x-model="row.position" placeholder="Posisi" maxlength="100" aria-label="Posisi" class="{{ $kelas }} sm:col-span-2">
                        <input type="date" x-bind:name="`experiences[${i}][start_date]`" x-model="row.start_date" aria-label="Mulai" class="{{ $kelas }} sm:col-span-2">
                        <input type="date" x-bind:name="`experiences[${i}][end_date]`" x-model="row.end_date" aria-label="Selesai" class="{{ $kelas }} sm:col-span-2">
                        <input type="text" x-bind:name="`experiences[${i}][description]`" x-model="row.description" placeholder="Keterangan" maxlength="255" aria-label="Keterangan" class="{{ $kelas }} sm:col-span-2">
                        <button type="button" x-on:click="rows.splice(i, 1)" class="justify-self-end text-slate-400 hover:text-danger-600 sm:col-span-1" aria-label="Hapus baris"><x-ui.icon name="x-mark" variant="m" class="h-4 w-4" /></button>
                    </div>
                </template>
                <p x-show="rows.length === 0" class="text-sm text-slate-500">Belum ada pengalaman kerja.</p>
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Akun login" description="Opsional. Karyawan yang punya akun bisa absen sendiri, mengajukan cuti, dan membuka Layanan Mandiri.">
            <x-ui.select name="user_id" label="Akun" :options="$users->mapWithKeys(fn ($u) => [$u->id => $u->name.' · '.$u->email])" :selected="$employee->user_id" placeholder="Tanpa akun login"
                         hint="Hanya akun yang belum tertaut ke karyawan lain yang tampil. Akun baru dibuat di menu Pengguna." />
            <x-ui.textarea name="notes" label="Catatan" :value="$employee->notes" rows="2" maxlength="1000" />

            <x-slot:footer>
                <x-ui.button :href="$edit ? route('employees.show', $employee) : route('employees.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit">{{ $edit ? 'Simpan perubahan' : 'Simpan karyawan' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
