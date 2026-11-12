{{-- Profil karyawan sendiri (hanya baca). Perubahan data lewat bagian SDM. --}}
@use('App\Support\Format')
@php
    $baris = 'flex justify-between gap-4';
    $isi = fn ($v) => filled($v) ? $v : '—';
@endphp

<x-app-layout>
    <x-slot name="header">Profil Saya</x-slot>
    <x-slot name="description">Data kepegawaian Anda. Bila ada yang keliru, hubungi bagian SDM.</x-slot>

    @include('sdm.ess.nav')

    <div class="mb-6 flex items-center gap-4">
        @if ($employee->photo_path)
            <img src="{{ route('employees.photo', $employee) }}" alt="Foto {{ $employee->name }}" class="h-16 w-16 rounded-full object-cover">
        @else
            <span class="flex h-16 w-16 items-center justify-center rounded-full bg-primary-100 text-lg font-semibold text-primary-700">{{ $employee->initials() }}</span>
        @endif
        <div>
            <p class="text-lg font-semibold text-slate-900">{{ $employee->name }}</p>
            <p class="text-sm text-slate-500">{{ $employee->code }} · {{ $employee->employment_type?->label() }}</p>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Kepegawaian">
            <dl class="grid gap-3 text-sm">
                <div class="{{ $baris }}"><dt class="text-slate-500">Cabang</dt><dd class="text-right text-slate-900">{{ $isi($employee->branch?->name) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Departemen</dt><dd class="text-right text-slate-900">{{ $isi($employee->department?->name) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Jabatan</dt><dd class="text-right text-slate-900">{{ $isi($employee->position?->name) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Atasan</dt><dd class="text-right text-slate-900">{{ $isi($employee->supervisor?->name) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Grade</dt><dd class="text-right text-slate-900">{{ $isi($employee->grade) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Mulai bekerja</dt><dd class="text-right text-slate-900">{{ $employee->join_date ? Format::tanggal($employee->join_date) : '—' }}</dd></div>
                @if ($employee->currentContract)
                    <div class="{{ $baris }}"><dt class="text-slate-500">Kontrak berjalan</dt>
                        <dd class="text-right text-slate-900">{{ Format::tanggal($employee->currentContract->start_date) }} – {{ $employee->currentContract->end_date ? Format::tanggal($employee->currentContract->end_date) : 'tanpa batas' }}</dd></div>
                @endif
            </dl>
        </x-ui.card>

        <x-ui.card title="Data pribadi">
            <dl class="grid gap-3 text-sm">
                <div class="{{ $baris }}"><dt class="text-slate-500">Tempat, tanggal lahir</dt><dd class="text-right text-slate-900">{{ collect([$employee->birth_place, $employee->birth_date ? Format::tanggal($employee->birth_date) : null])->filter()->join(', ') ?: '—' }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Jenis kelamin</dt><dd class="text-right text-slate-900">{{ $isi($employee->gender?->label()) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Status perkawinan</dt><dd class="text-right text-slate-900">{{ $isi($employee->marital_status?->label()) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">No. HP</dt><dd class="text-right text-slate-900">{{ $isi($employee->phone) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Email</dt><dd class="text-right text-slate-900">{{ $isi($employee->email) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Alamat</dt><dd class="text-right text-slate-900">{{ $isi($employee->address) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Kontak darurat</dt><dd class="text-right text-slate-900">{{ collect([$employee->emergency_contact_name, $employee->emergency_contact_relation, $employee->emergency_contact_phone])->filter()->join(' · ') ?: '—' }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Pajak, BPJS & rekening gaji">
            <dl class="grid gap-3 text-sm">
                <div class="{{ $baris }}"><dt class="text-slate-500">NPWP</dt><dd class="text-right text-slate-900">{{ $isi($employee->tax_id) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Status PTKP</dt><dd class="text-right text-slate-900">{{ $isi($employee->ptkp_status?->label()) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">BPJS Kesehatan</dt><dd class="text-right text-slate-900">{{ $isi($employee->bpjs_health_number) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">BPJS Ketenagakerjaan</dt><dd class="text-right text-slate-900">{{ $isi($employee->bpjs_employment_number) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Rekening</dt><dd class="text-right text-slate-900">{{ collect([$employee->bank_name, $employee->bank_account_number, $employee->bank_account_name ? 'a.n. '.$employee->bank_account_name : null])->filter()->join(' · ') ?: '—' }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Keluarga & pendidikan">
            @if ($employee->familyMembers->isEmpty() && $employee->educations->isEmpty())
                <p class="text-sm text-slate-500">Belum diisi.</p>
            @endif
            <ul class="grid gap-2 text-sm">
                @foreach ($employee->familyMembers as $f)
                    <li class="flex justify-between gap-4"><span class="text-slate-900">{{ $f->name }}</span><span class="text-slate-500">{{ $f->relation?->label() }}{{ $f->is_dependent ? ' · tanggungan' : '' }}</span></li>
                @endforeach
                @foreach ($employee->educations as $e)
                    <li class="flex justify-between gap-4"><span class="text-slate-900">{{ $e->institution }}</span><span class="text-slate-500">{{ $e->level?->label() }}{{ $e->end_year ? ' · '.$e->end_year : '' }}</span></li>
                @endforeach
            </ul>
        </x-ui.card>
    </div>

    @include('sdm.ess.spacer')
</x-app-layout>
