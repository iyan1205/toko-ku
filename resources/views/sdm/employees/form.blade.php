{{-- Tambah / ubah karyawan. Tautan ke akun login opsional (satu akun untuk satu karyawan). --}}
@use('App\Enums\EmploymentType')
@php
    $edit = $employee->exists;
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah karyawan' : 'Tambah karyawan' }}</x-slot>
    <x-slot name="description">{{ $edit ? $employee->name.' · '.$employee->code : 'Data karyawan untuk absensi, jadwal, cuti, dan nantinya penggajian.' }}</x-slot>

    <form method="POST" action="{{ $edit ? route('employees.update', $employee) : route('employees.store') }}" class="grid gap-8">
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
                <x-ui.input name="identity_number" label="NIK (KTP)" :value="$employee->identity_number" maxlength="30" inputmode="numeric" />
                <x-ui.input name="birth_date" label="Tanggal lahir" type="date" :value="$employee->birth_date?->format('Y-m-d')" />
                <x-ui.input name="phone" label="Telepon" :value="$employee->phone" maxlength="25" />
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input name="email" label="Email" type="email" :value="$employee->email" maxlength="150" />
                <x-ui.input name="address" label="Alamat" :value="$employee->address" maxlength="255" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Pekerjaan" description="Departemen dan jabatan diatur di menu SDM → Departemen & Jabatan.">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.select name="department_id" label="Departemen" :options="$departments->pluck('name', 'id')" :selected="$employee->department_id" placeholder="Belum ditentukan" />
                <x-ui.select name="position_id" label="Jabatan" :options="$positions->pluck('name', 'id')" :selected="$employee->position_id" placeholder="Belum ditentukan" />
                <x-ui.select name="employment_type" label="Status kerja" required :options="collect(EmploymentType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])" :selected="$employee->employment_type?->value" />
            </div>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="join_date" label="Mulai kerja" type="date" :value="$employee->join_date?->format('Y-m-d')" />
                <x-ui.input name="end_date" label="Berhenti kerja" type="date" :value="$employee->end_date?->format('Y-m-d')" hint="Kosongkan bila masih bekerja." />
            </div>
            <x-ui.checkbox name="is_active" label="Karyawan aktif" :checked="$employee->is_active ?? true" unchecked-value="0" />
        </x-ui.form-section>

        <x-ui.form-section title="Akun login" description="Opsional. Karyawan yang punya akun bisa absen sendiri dan mengajukan cuti; datanya otomatis terhubung.">
            <x-ui.select name="user_id" label="Akun" :options="$users->mapWithKeys(fn ($u) => [$u->id => $u->name.' · '.$u->email])" :selected="$employee->user_id" placeholder="Tanpa akun login"
                         hint="Hanya akun yang belum tertaut ke karyawan lain yang tampil. Akun baru dibuat di menu Pengguna." />
            <x-ui.textarea name="notes" label="Catatan" :value="$employee->notes" rows="2" maxlength="1000" />

            <x-slot:footer>
                <x-ui.button :href="route('employees.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit">{{ $edit ? 'Simpan perubahan' : 'Simpan karyawan' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
