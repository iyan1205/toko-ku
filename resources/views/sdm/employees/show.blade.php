{{-- Detail karyawan: profil lengkap, keluarga & pendidikan, riwayat kontrak, mutasi/promosi, aset yang dipegang, bawahan. --}}
@use('App\Enums\EmploymentType')
@use('App\Enums\TerminationType')
@use('App\Support\Format')
@php
    $e = $employee;
    $bolehUbah = auth()->user()->can('sdm.employees.update');
    $kontrak = $e->contracts->first();
    $sisa = $kontrak?->daysLeft();
    $masaKerja = $e->join_date ? $e->join_date->diff($e->end_date ?? now()) : null;
    $tab = in_array(request('tab'), ['profil', 'keluarga', 'kontrak', 'mutasi', 'dokumen', 'aset', 'bawahan'], true) ? request('tab') : 'profil';
    $offboarding = $e->offboardings->firstWhere('status', \App\Models\EmployeeOffboarding::IN_PROGRESS) ?? $e->offboardings->firstWhere('status', \App\Models\EmployeeOffboarding::COMPLETED);
    $hariPengingat = (int) setting('sdm.reminder_days', 30);
    $baris = fn (string $label, $nilai) => ['label' => $label, 'value' => ($nilai === null || $nilai === '') ? '—' : $nilai];
    $kelompok = [
        'Data pribadi' => [
            $baris('NIK (KTP)', $e->identity_number), $baris('Jenis kelamin', $e->gender?->label()),
            $baris('Tempat, tanggal lahir', trim(($e->birth_place ?? '').($e->birth_date ? ', '.Format::tanggal($e->birth_date) : ''), ', ')),
            $baris('Agama', $e->religion), $baris('Status pernikahan', $e->marital_status?->label()), $baris('Golongan darah', $e->blood_type),
            $baris('Telepon', $e->phone), $baris('Email', $e->email), $baris('Alamat', $e->address),
        ],
        'Pajak & BPJS' => [
            $baris('NPWP', $e->tax_id), $baris('Status PTKP', $e->ptkp_status?->label()),
            $baris('BPJS Kesehatan', $e->bpjs_health_number), $baris('BPJS Ketenagakerjaan', $e->bpjs_employment_number),
        ],
        'Rekening & kontak darurat' => [
            $baris('Bank', $e->bank_name), $baris('Nomor rekening', $e->bank_account_number), $baris('Atas nama', $e->bank_account_name),
            $baris('Kontak darurat', trim(($e->emergency_contact_name ?? '').($e->emergency_contact_relation ? ' ('.$e->emergency_contact_relation.')' : ''))),
            $baris('Telepon darurat', $e->emergency_contact_phone),
        ],
    ];
@endphp

<x-app-layout>
    <x-slot name="header">{{ $e->name }}</x-slot>
    <x-slot name="description">{{ $e->code }} · {{ $e->position?->name ?? 'Jabatan belum ditentukan' }}{{ $e->department ? ' · '.$e->department->name : '' }}</x-slot>
    <x-slot name="actions">
        @if ($e->is_active && ! $offboarding?->isInProgress())
            @can('sdm.offboarding.create')
                <x-ui.button variant="ghost" icon="arrow-right-start-on-rectangle" x-data x-on:click="$dispatch('open-modal', 'mulai-offboarding')">Offboarding</x-ui.button>
            @endcan
        @endif
        @can('sdm.employees.update')
            <x-ui.button variant="secondary" icon="pencil-square" :href="route('employees.edit', $e)">Ubah data</x-ui.button>
        @endcan
    </x-slot>

    @if ($offboarding)
        <x-ui.alert :type="$offboarding->isInProgress() ? 'warning' : 'info'" class="mb-6">
            {{ $offboarding->isInProgress() ? 'Offboarding sedang berjalan' : 'Offboarding selesai' }}: {{ $offboarding->termination_type->label() }}, hari terakhir kerja {{ Format::tanggal($offboarding->last_working_date) }}.
            @can('sdm.offboarding.view')
                <a href="{{ route('offboardings.show', $offboarding) }}" class="font-medium underline">Buka checklist</a>
            @endcan
        </x-ui.alert>
    @endif

    <div class="mb-6 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-card sm:grid-cols-[auto_1fr]">
        @if ($e->photo_path)
            <img src="{{ route('employees.photo', $e) }}" alt="Foto {{ $e->name }}" class="h-20 w-20 rounded-full object-cover">
        @else
            <span class="flex h-20 w-20 items-center justify-center rounded-full bg-primary-50 text-xl font-semibold text-primary-700" aria-hidden="true">{{ $e->initials() }}</span>
        @endif
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-slate-500">Status</dt><dd class="mt-0.5 flex flex-wrap gap-1">
                <x-ui.badge color="neutral">{{ $e->employment_type->label() }}</x-ui.badge>
                @if ($e->is_active)
                    <x-ui.badge color="success" dot>Aktif</x-ui.badge>
                @else
                    <x-ui.badge color="danger" dot>Tidak aktif</x-ui.badge>
                @endif
            </dd></div>
            <div><dt class="text-slate-500">Cabang</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $e->branch?->name ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Atasan langsung</dt><dd class="mt-0.5 font-medium text-slate-900">
                @if ($e->supervisor)
                    <a href="{{ route('employees.show', $e->supervisor) }}" class="hover:text-primary-700 hover:underline">{{ $e->supervisor->name }}</a>
                @else
                    —
                @endif
            </dd></div>
            <div><dt class="text-slate-500">Grade</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $e->grade ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Mulai kerja</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $e->join_date ? Format::tanggal($e->join_date) : '—' }}
                @if ($masaKerja)<span class="block text-xs font-normal text-slate-500">{{ $masaKerja->y }} tahun {{ $masaKerja->m }} bulan</span>@endif
            </dd></div>
            <div><dt class="text-slate-500">Kontrak</dt><dd class="mt-0.5 font-medium text-slate-900">
                @if ($kontrak)
                    {{ $kontrak->end_date ? 's.d. '.Format::tanggal($kontrak->end_date) : 'Tanpa batas' }}
                    @if ($sisa !== null)
                        <x-ui.badge :color="$sisa < 0 ? 'danger' : ($sisa <= (int) setting('sdm.reminder_days', 30) ? 'warning' : 'neutral')">{{ $sisa < 0 ? 'Lewat '.abs($sisa).' hari' : $sisa.' hari lagi' }}</x-ui.badge>
                    @endif
                @else
                    <span class="font-normal text-slate-500">Belum dicatat</span>
                @endif
            </dd></div>
            <div><dt class="text-slate-500">Akun login</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $e->user?->email ?? 'Tidak ada' }}
                @if ($e->user && ! $e->user->is_active)<span class="block text-xs font-normal text-danger-600">Akun dinonaktifkan</span>@endif
            </dd></div>
            @if ($e->end_date)
                <div><dt class="text-slate-500">Berhenti</dt><dd class="mt-0.5 font-medium text-slate-900">{{ Format::tanggal($e->end_date) }}
                    @if ($e->termination_type)<span class="block text-xs font-normal text-slate-500">{{ $e->termination_type->label() }}</span>@endif
                </dd></div>
            @endif
        </dl>
    </div>

    <x-ui.tabs :tabs="['profil' => 'Profil', 'keluarga' => 'Keluarga & pendidikan', 'kontrak' => 'Kontrak ('.$e->contracts->count().')', 'mutasi' => 'Mutasi & promosi ('.$e->movements->count().')', 'dokumen' => 'Dokumen ('.$e->documents->count().')', 'aset' => 'Aset dipegang ('.$e->fixedAssets->count().')', 'bawahan' => 'Bawahan ('.$e->subordinates->count().')']" :active="$tab">
        <x-ui.tab-panel name="profil" class="pt-6">
            <div class="grid gap-6 lg:grid-cols-3">
                @foreach ($kelompok as $judul => $isi)
                    <x-ui.card :title="$judul">
                        <dl class="grid gap-3 text-sm">
                            @foreach ($isi as $b)
                                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ $b['label'] }}</dt><dd class="text-right font-medium text-slate-900">{{ $b['value'] }}</dd></div>
                            @endforeach
                        </dl>
                    </x-ui.card>
                @endforeach
            </div>
            @if ($e->notes)
                <p class="mt-4 text-sm text-slate-600"><span class="font-medium">Catatan:</span> {{ $e->notes }}</p>
            @endif
        </x-ui.tab-panel>

        <x-ui.tab-panel name="keluarga" class="grid gap-6 pt-6">
            <x-ui.table :sticky="false">
                <x-slot:toolbar><p class="text-sm font-medium text-slate-700">Keluarga</p></x-slot:toolbar>
                <x-slot:head>
                    <x-ui.table.th>Nama</x-ui.table.th><x-ui.table.th>Hubungan</x-ui.table.th><x-ui.table.th>Tanggal lahir</x-ui.table.th><x-ui.table.th>Pekerjaan</x-ui.table.th><x-ui.table.th>Tanggungan</x-ui.table.th>
                </x-slot:head>
                @forelse ($e->familyMembers as $f)
                    <tr>
                        <x-ui.table.td><span class="font-medium text-slate-900">{{ $f->name }}</span>@if ($f->identity_number)<span class="block font-mono text-xs text-slate-500">{{ $f->identity_number }}</span>@endif</x-ui.table.td>
                        <x-ui.table.td>{{ $f->relation->label() }}</x-ui.table.td>
                        <x-ui.table.td muted>{{ $f->birth_date ? Format::tanggal($f->birth_date) : '—' }}</x-ui.table.td>
                        <x-ui.table.td muted>{{ $f->occupation ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td>{{ $f->is_dependent ? 'Ya' : 'Tidak' }}</x-ui.table.td>
                    </tr>
                @empty
                    <x-ui.table.empty colspan="5" icon="users" title="Belum ada data keluarga" />
                @endforelse
            </x-ui.table>
            <div class="grid gap-6 lg:grid-cols-2">
                <x-ui.card title="Pendidikan">
                    <ul class="grid gap-3 text-sm">
                        @forelse ($e->educations as $ed)
                            <li><span class="font-medium text-slate-900">{{ $ed->level->label() }} · {{ $ed->institution }}</span>
                                <span class="block text-slate-500">{{ $ed->major ?? '' }}{{ $ed->start_year || $ed->end_year ? ' ('.($ed->start_year ?? '?').'–'.($ed->end_year ?? '?').')' : '' }}</span></li>
                        @empty
                            <li class="text-slate-500">Belum ada riwayat pendidikan.</li>
                        @endforelse
                    </ul>
                </x-ui.card>
                <x-ui.card title="Pengalaman kerja">
                    <ul class="grid gap-3 text-sm">
                        @forelse ($e->experiences as $ex)
                            <li><span class="font-medium text-slate-900">{{ $ex->company }}</span>@if ($ex->position) · {{ $ex->position }}@endif
                                <span class="block text-slate-500">{{ $ex->start_date ? Format::tanggal($ex->start_date) : '?' }} – {{ $ex->end_date ? Format::tanggal($ex->end_date) : '?' }}{{ $ex->description ? ' · '.$ex->description : '' }}</span></li>
                        @empty
                            <li class="text-slate-500">Belum ada pengalaman kerja.</li>
                        @endforelse
                    </ul>
                </x-ui.card>
            </div>
        </x-ui.tab-panel>

        <x-ui.tab-panel name="kontrak" class="pt-6">
            <x-ui.table :sticky="false">
                <x-slot:toolbar>
                    <p class="text-sm text-slate-500">Status kerja karyawan mengikuti kontrak terbaru. Kontrak yang hampir habis diingatkan {{ (int) setting('sdm.reminder_days', 30) }} hari sebelumnya.</p>
                    @if ($bolehUbah)
                        <x-ui.button size="sm" icon="plus" x-data x-on:click="$dispatch('open-modal', 'tambah-kontrak')">Tambah kontrak</x-ui.button>
                    @endif
                </x-slot:toolbar>
                <x-slot:head>
                    <x-ui.table.th>Periode</x-ui.table.th><x-ui.table.th>Status kerja</x-ui.table.th><x-ui.table.th>Nomor</x-ui.table.th><x-ui.table.th>Dicatat</x-ui.table.th><x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
                </x-slot:head>
                @forelse ($e->contracts as $i => $c)
                    <tr>
                        <x-ui.table.td><span class="font-medium text-slate-900">{{ $c->periodLabel() }}</span>
                            @if ($i === 0 && ($d = $c->daysLeft()) !== null)
                                <x-ui.badge :color="$d < 0 ? 'danger' : 'warning'" class="ml-1">{{ $d < 0 ? 'Sudah berakhir' : $d.' hari lagi' }}</x-ui.badge>
                            @endif
                            @if ($c->notes)<span class="block text-xs text-slate-500">{{ $c->notes }}</span>@endif
                        </x-ui.table.td>
                        <x-ui.table.td>{{ $c->employment_type->label() }}</x-ui.table.td>
                        <x-ui.table.td muted>{{ $c->number ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td muted>{{ Format::tanggal($c->created_at) }} · {{ $c->creator?->name ?? '-' }}</x-ui.table.td>
                        <x-ui.table.td align="right">
                            @if ($bolehUbah && $i === 0)
                                <x-ui.button variant="ghost" size="sm" icon="trash" class="text-danger-600" aria-label="Hapus kontrak"
                                    x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('employees.contracts.destroy', [$e, $c])) }}, nama: {{ \Illuminate\Support\Js::from('kontrak '.$c->periodLabel()) }} })" />
                            @endif
                        </x-ui.table.td>
                    </tr>
                @empty
                    <x-ui.table.empty colspan="5" icon="document-text" title="Belum ada riwayat kontrak" description="Catat kontrak pertama agar status kerja dan pengingat kontrak habis berjalan." />
                @endforelse
            </x-ui.table>
        </x-ui.tab-panel>

        <x-ui.tab-panel name="mutasi" class="pt-6">
            <x-ui.table :sticky="false">
                <x-slot:toolbar>
                    <p class="text-sm text-slate-500">Mutasi bertanggal depan diterapkan otomatis pada tanggal efektifnya.</p>
                    @if ($bolehUbah && $e->is_active)
                        <x-ui.button size="sm" icon="arrows-right-left" x-data x-on:click="$dispatch('open-modal', 'catat-mutasi')">Catat mutasi/promosi</x-ui.button>
                    @endif
                </x-slot:toolbar>
                <x-slot:head>
                    <x-ui.table.th>Efektif</x-ui.table.th><x-ui.table.th>Jenis</x-ui.table.th><x-ui.table.th>Perubahan</x-ui.table.th><x-ui.table.th>SK</x-ui.table.th><x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
                </x-slot:head>
                @forelse ($e->movements as $m)
                    <tr>
                        <x-ui.table.td><span class="font-medium text-slate-900">{{ Format::tanggal($m->effective_date) }}</span>
                            @unless ($m->isApplied())<x-ui.badge color="warning" class="ml-1">Menunggu</x-ui.badge>@endunless
                        </x-ui.table.td>
                        <x-ui.table.td><x-ui.badge :color="$m->type->color()">{{ $m->type->label() }}</x-ui.badge></x-ui.table.td>
                        <x-ui.table.td>
                            <ul class="text-sm">
                                @foreach ($m->changes() as $label => [$dari, $ke])
                                    <li><span class="text-slate-500">{{ $label }}:</span> {{ $dari ?? '—' }} → <span class="font-medium text-slate-900">{{ $ke ?? '—' }}</span></li>
                                @endforeach
                            </ul>
                            @if ($m->notes)<span class="block text-xs text-slate-500">{{ $m->notes }}</span>@endif
                        </x-ui.table.td>
                        <x-ui.table.td muted>{{ $m->decree_number ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td align="right">
                            @if ($bolehUbah && ! $m->isApplied())
                                <x-ui.button variant="ghost" size="sm" icon="x-mark" class="text-danger-600" aria-label="Batalkan mutasi"
                                    x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('employees.movements.destroy', [$e, $m])) }}, judul: 'Batalkan mutasi?', pesan: 'Mutasi yang menunggu tanggal efektif ini dibatalkan.', tombol: 'Ya, batalkan' })" />
                            @endif
                        </x-ui.table.td>
                    </tr>
                @empty
                    <x-ui.table.empty colspan="5" icon="arrows-right-left" title="Belum ada riwayat mutasi atau promosi" />
                @endforelse
            </x-ui.table>
        </x-ui.tab-panel>

        <x-ui.tab-panel name="dokumen" class="pt-6">
            <x-ui.table :sticky="false">
                <x-slot:toolbar>
                    <p class="text-sm text-slate-500">Dokumen dengan masa berlaku diingatkan {{ $hariPengingat }} hari sebelum kedaluwarsa.</p>
                    @if ($bolehUbah)
                        <x-ui.button size="sm" icon="arrow-up-tray" x-data x-on:click="$dispatch('open-modal', 'tambah-dokumen')">Unggah dokumen</x-ui.button>
                    @endif
                </x-slot:toolbar>
                <x-slot:head>
                    <x-ui.table.th>Dokumen</x-ui.table.th><x-ui.table.th>Nomor</x-ui.table.th><x-ui.table.th>Berlaku sampai</x-ui.table.th><x-ui.table.th>Berkas</x-ui.table.th><x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
                </x-slot:head>
                @forelse ($e->documents as $doc)
                    <tr>
                        <x-ui.table.td><span class="font-medium text-slate-900">{{ $doc->name }}</span><span class="block text-xs text-slate-500">{{ $doc->typeLabel() }}{{ $doc->notes ? ' · '.$doc->notes : '' }}</span></x-ui.table.td>
                        <x-ui.table.td muted>{{ $doc->number ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td>
                            @if ($doc->expires_at)
                                {{ Format::tanggal($doc->expires_at) }}
                                @if ($doc->daysLeft() < 0)
                                    <x-ui.badge color="danger" class="ml-1">Kedaluwarsa</x-ui.badge>
                                @elseif ($doc->daysLeft() <= $hariPengingat)
                                    <x-ui.badge color="warning" class="ml-1">{{ $doc->daysLeft() }} hari lagi</x-ui.badge>
                                @endif
                            @else
                                <span class="text-slate-500">Seumur hidup</span>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td>
                            @forelse ($doc->attachments as $att)
                                <a href="{{ route('attachments.download', $att) }}" class="text-sm text-primary-700 hover:underline">{{ $att->original_name ?? 'Unduh' }}</a>
                            @empty
                                <span class="text-sm text-slate-400">Tanpa berkas</span>
                            @endforelse
                        </x-ui.table.td>
                        <x-ui.table.td align="right">
                            @if ($bolehUbah)
                                <x-ui.button variant="ghost" size="sm" icon="trash" class="text-danger-600" aria-label="Hapus dokumen {{ $doc->name }}"
                                    x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('employees.documents.destroy', [$e, $doc])) }}, nama: {{ \Illuminate\Support\Js::from('dokumen '.$doc->name) }} })" />
                            @endif
                        </x-ui.table.td>
                    </tr>
                @empty
                    <x-ui.table.empty colspan="5" icon="folder-open" title="Belum ada dokumen" description="Unggah KTP, ijazah, SIM, sertifikat, atau dokumen lain beserta masa berlakunya." />
                @endforelse
            </x-ui.table>
        </x-ui.tab-panel>

        <x-ui.tab-panel name="aset" class="pt-6">
            <x-ui.table :sticky="false">
                <x-slot:head><x-ui.table.th>Aset</x-ui.table.th><x-ui.table.th>Lokasi</x-ui.table.th><x-ui.table.th>Status</x-ui.table.th></x-slot:head>
                @forelse ($e->fixedAssets as $a)
                    <tr>
                        <x-ui.table.td>
                            @can('accounting.assets.view')
                                <a href="{{ route('fixed-assets.show', $a) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $a->name }}</a>
                            @else
                                <span class="font-medium text-slate-900">{{ $a->name }}</span>
                            @endcan
                            <span class="block font-mono text-xs text-slate-500">{{ $a->code }}</span>
                        </x-ui.table.td>
                        <x-ui.table.td muted>{{ $a->location ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td>{{ $a->status === 'active' ? 'Aktif' : 'Dilepas' }}</x-ui.table.td>
                    </tr>
                @empty
                    <x-ui.table.empty colspan="3" icon="computer-desktop" title="Tidak memegang aset tetap" description="Penanggung jawab aset diisi di Akuntansi → Aset Tetap." />
                @endforelse
            </x-ui.table>
        </x-ui.tab-panel>

        <x-ui.tab-panel name="bawahan" class="pt-6">
            <x-ui.table :sticky="false">
                <x-slot:head><x-ui.table.th>Karyawan</x-ui.table.th><x-ui.table.th>Jabatan</x-ui.table.th></x-slot:head>
                @forelse ($e->subordinates as $s)
                    <tr @class(['opacity-60' => ! $s->is_active])>
                        <x-ui.table.td><a href="{{ route('employees.show', $s) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $s->name }}</a><span class="block font-mono text-xs text-slate-500">{{ $s->code }}</span></x-ui.table.td>
                        <x-ui.table.td muted>{{ $s->position?->name ?? '—' }}</x-ui.table.td>
                    </tr>
                @empty
                    <x-ui.table.empty colspan="2" icon="user-group" title="Tidak punya bawahan langsung" />
                @endforelse
            </x-ui.table>
        </x-ui.tab-panel>
    </x-ui.tabs>

    @can('sdm.offboarding.create')
        <x-ui.modal name="mulai-offboarding" title="Mulai offboarding" description="Checklist pengembalian dibuat dari daftar standar dan aset tetap yang dipegang karyawan ini." maxWidth="lg" :show="$errors->hasAny(['termination_type', 'notice_date', 'last_working_date'])">
            <form method="POST" action="{{ route('offboardings.store', $e) }}" id="form-offboarding" class="grid gap-4">
                @csrf
                <x-ui.select name="termination_type" label="Alasan berhenti" required :options="collect(TerminationType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])" selected="resign" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="notice_date" label="Tanggal pemberitahuan" type="date" required :value="now()->format('Y-m-d')" />
                    <x-ui.input name="last_working_date" label="Hari terakhir kerja" type="date" required :value="now()->addDays(30)->format('Y-m-d')" />
                </div>
                <x-ui.input name="reason" id="offboarding-reason" label="Keterangan" maxlength="255" />
                @if ($e->user)
                    <x-ui.checkbox name="deactivate_user" :checked="true" unchecked-value="0" label="Nonaktifkan akun login {{ $e->user->email }} setelah hari terakhir kerja" />
                @endif
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
                <x-ui.button type="submit" form="form-offboarding">Mulai offboarding</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endcan

    @if ($bolehUbah)
        <x-ui.modal name="tambah-dokumen" title="Unggah dokumen karyawan" maxWidth="lg" :show="$errors->hasAny(['type', 'name', 'expires_at', 'issued_at', 'file'])">
            <form method="POST" action="{{ route('employees.documents.store', $e) }}" id="form-dokumen" enctype="multipart/form-data" class="grid gap-4">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select name="type" id="dokumen-type" label="Jenis" required :options="config('sdm.document_types')" selected="ktp" />
                    <x-ui.input name="name" id="dokumen-name" label="Nama dokumen" maxlength="150" hint="Kosong = sama dengan jenisnya." />
                    <x-ui.input name="number" id="dokumen-number" label="Nomor dokumen" maxlength="60" />
                    <x-ui.input name="issued_at" label="Tanggal terbit" type="date" />
                    <x-ui.input name="expires_at" label="Berlaku sampai" type="date" hint="Kosong = seumur hidup." />
                    <x-ui.input name="file" label="Berkas" type="file" />
                </div>
                <x-ui.input name="notes" id="dokumen-notes" label="Catatan" maxlength="255" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
                <x-ui.button type="submit" form="form-dokumen">Simpan dokumen</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        <x-ui.modal name="tambah-kontrak" title="Tambah kontrak" description="Status kerja karyawan ikut berubah mengikuti kontrak ini." maxWidth="lg" :show="$errors->hasAny(['employment_type', 'start_date', 'end_date', 'number'])">
            <form method="POST" action="{{ route('employees.contracts.store', $e) }}" id="form-kontrak" class="grid gap-4" x-data="{ jenis: @js(old('employment_type', EmploymentType::Contract->value)) }">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select name="employment_type" label="Status kerja" required x-model="jenis" :options="collect(EmploymentType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])" :selected="EmploymentType::Contract->value" />
                    <x-ui.input name="number" label="Nomor perjanjian" maxlength="50" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="start_date" label="Mulai" type="date" required :value="($kontrak?->end_date?->copy()->addDay() ?? now())->format('Y-m-d')" />
                    <x-ui.input name="end_date" label="Berakhir" type="date" hint="Wajib untuk PKWT; kosong = tanpa batas." />
                </div>
                <x-ui.input name="notes" id="kontrak-notes" label="Catatan" maxlength="255" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
                <x-ui.button type="submit" form="form-kontrak">Simpan kontrak</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        <x-ui.modal name="catat-mutasi" title="Catat mutasi atau promosi" description="Isi hanya yang berubah. Posisi sekarang otomatis dicatat sebagai posisi sebelumnya." maxWidth="lg" :show="$errors->hasAny(['type', 'effective_date', 'to_branch_id', 'to_department_id', 'to_position_id', 'to_supervisor_id', 'to_grade'])">
            <form method="POST" action="{{ route('employees.movements.store', $e) }}" id="form-mutasi" class="grid gap-4">
                @csrf
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.select name="type" label="Jenis" required :options="$movementTypes" selected="transfer" />
                    <x-ui.input name="effective_date" label="Tanggal efektif" type="date" required :value="now()->format('Y-m-d')" />
                    <x-ui.input name="decree_number" label="Nomor SK" maxlength="50" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select name="to_branch_id" label="Cabang baru" :options="$branches->pluck('name', 'id')" :placeholder="'Tetap: '.($e->branch?->name ?? '—')" />
                    <x-ui.select name="to_department_id" label="Departemen baru" :options="$departments->pluck('name', 'id')" :placeholder="'Tetap: '.($e->department?->name ?? '—')" />
                    <x-ui.select name="to_position_id" label="Jabatan baru" :options="$positions->pluck('name', 'id')" :placeholder="'Tetap: '.($e->position?->name ?? '—')" />
                    <x-ui.select name="to_supervisor_id" label="Atasan baru" :options="$supervisors->mapWithKeys(fn ($s) => [$s->id => $s->name.' · '.$s->code])" :placeholder="'Tetap: '.($e->supervisor?->name ?? '—')" />
                    <x-ui.input name="to_grade" label="Grade baru" maxlength="20" :placeholder="'Tetap: '.($e->grade ?? '—')" />
                </div>
                <x-ui.input name="notes" id="mutasi-notes" label="Catatan" maxlength="255" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
                <x-ui.button type="submit" form="form-mutasi">Simpan</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</x-app-layout>
