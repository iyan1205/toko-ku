{{-- SDM → Shift Kerja → Pola rotasi: urutan shift per hari yang berulang, lalu diterapkan ke jadwal karyawan. --}}
@php
    $user = auth()->user();
    $bolehSimpan = $editing ? $user->can('sdm.shifts.update') : $user->can('sdm.shifts.create');
    $bolehTerapkan = $user->can('sdm.schedules.update');
    $polaAwal = collect(old('pattern', $editing?->pattern ?? [null]))->map(fn ($v) => $v === null || $v === '' ? 'libur' : (string) $v)->values();
    $panjang = $rotations->mapWithKeys(fn ($r) => [$r->id => count($r->pattern ?? [])]);
    $namaRotasi = $rotations->pluck('name', 'id');
    $terapkanGagal = $errors->hasAny(['employee_ids', 'start_date', 'end_date', 'offset']);
@endphp

<x-app-layout>
    <x-slot name="header">Pola Rotasi Shift</x-slot>
    <x-slot name="description">Urutan shift per hari yang berulang, misal Pagi, Pagi, Siang, Siang, Libur. Terapkan ke beberapa karyawan sekaligus untuk mengisi jadwal kerja.</x-slot>
    <x-slot name="actions">
        <x-ui.button variant="secondary" icon="clock" :href="route('shifts.index')">Shift kerja</x-ui.button>
        <x-ui.button variant="secondary" icon="calendar-days" :href="route('schedules.month')">Jadwal bulanan</x-ui.button>
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-3"
         x-data="{ rotasi: @js(old('rotation_id') ? (int) old('rotation_id') : null), panjang: @js($panjang), nama: @js($namaRotasi), cari: '', dept: '',
                   aksi: @js(route('shift-rotations.apply', '__ID__')),
                   terapkan(id) { this.rotasi = id; $dispatch('open-modal', 'terapkan-rotasi') } }">
        <div class="lg:col-span-2">
            <x-ui.table :sticky="false">
                <x-slot:head>
                    <x-ui.table.th>Pola</x-ui.table.th>
                    <x-ui.table.th>Urutan</x-ui.table.th>
                    <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
                </x-slot:head>
                @forelse ($rotations as $r)
                    <tr @class(['bg-primary-50/50' => $editing?->id === $r->id])>
                        <x-ui.table.td>
                            <span class="font-medium text-slate-900">{{ $r->name }}</span>
                            @unless ($r->is_active)
                                <x-ui.badge class="ml-1">Nonaktif</x-ui.badge>
                            @endunless
                            <span class="block text-xs text-slate-500">{{ count($r->pattern ?? []) }} hari per siklus{{ $r->description ? ' · '.$r->description : '' }}</span>
                        </x-ui.table.td>
                        <x-ui.table.td>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($r->days() as $s)
                                    <x-ui.badge :color="$s ? ($s->color ?? 'primary') : 'neutral'">{{ $s?->nama ?? 'Libur' }}</x-ui.badge>
                                @endforeach
                            </div>
                        </x-ui.table.td>
                        <x-ui.table.td align="right" class="whitespace-nowrap">
                            @if ($bolehTerapkan && $r->is_active)
                                <x-ui.button variant="secondary" size="sm" icon="calendar-days" x-on:click="terapkan({{ $r->id }})">Terapkan</x-ui.button>
                            @endif
                            @can('sdm.shifts.update')
                                <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('shift-rotations.index', ['ubah' => $r->id])" aria-label="Ubah {{ $r->name }}" />
                            @endcan
                            @can('sdm.shifts.delete')
                                <x-ui.button variant="ghost" size="sm" icon="trash" class="text-danger-600" aria-label="Hapus {{ $r->name }}"
                                    x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('shift-rotations.destroy', $r)) }}, nama: {{ \Illuminate\Support\Js::from('Pola '.$r->name) }} })" />
                            @endcan
                        </x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3"><x-ui.empty-state icon="arrow-path" title="Belum ada pola rotasi" description="Buat pola di sebelah kanan, misal 2 hari Pagi, 2 hari Siang, 1 hari Libur." /></td>
                    </tr>
                @endforelse
            </x-ui.table>
        </div>

        @if ($bolehSimpan)
            <form method="POST" action="{{ $editing ? route('shift-rotations.update', $editing) : route('shift-rotations.store') }}"
                  x-data="{ pola: @js($polaAwal) }">
                @csrf
                @if ($editing)
                    @method('PUT')
                @endif
                <x-ui.form-section :title="$editing ? 'Ubah pola' : 'Pola baru'" description="Hari pertama pola dipakai pada tanggal mulai saat diterapkan.">
                    <x-ui.input name="name" label="Nama pola" required maxlength="50" :value="$editing?->name" placeholder="Misal Rotasi 2-2-1" />
                    <div>
                        <p class="text-sm font-medium text-slate-700">Urutan hari</p>
                        <ol class="mt-2 grid gap-2">
                            <template x-for="(v, i) in pola" :key="i">
                                <li class="flex items-center gap-2">
                                    <span class="w-14 shrink-0 text-sm text-slate-500" x-text="'Hari ' + (i + 1)"></span>
                                    <select x-bind:name="'pattern[' + i + ']'" x-model="pola[i]" x-bind:aria-label="'Shift hari ' + (i + 1)"
                                            class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                        <option value="libur">Libur</option>
                                        @foreach ($shifts as $s)
                                            <option value="{{ $s->id }}">{{ $s->nama }} ({{ $s->rentang() }})</option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="rounded p-1 text-slate-400 hover:text-danger-600 disabled:opacity-30" x-on:click="pola.splice(i, 1)" x-bind:disabled="pola.length === 1" aria-label="Hapus hari">
                                        <x-ui.icon name="x-mark" variant="m" class="h-4 w-4" />
                                    </button>
                                </li>
                            </template>
                        </ol>
                        <x-ui.button type="button" variant="ghost" size="sm" icon="plus" class="mt-2" x-on:click="pola.push(pola[pola.length - 1] ?? 'libur')" x-bind:disabled="pola.length >= 62">Tambah hari</x-ui.button>
                        @error('pattern') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                        @error('pattern.*') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                    </div>
                    <x-ui.input name="description" label="Keterangan" maxlength="255" :value="$editing?->description" placeholder="Opsional" />
                    <x-ui.checkbox name="is_active" label="Aktif" :checked="$editing?->is_active ?? true" unchecked-value="0" />
                    <x-slot:footer>
                        @if ($editing)
                            <x-ui.button variant="secondary" :href="route('shift-rotations.index')">Batal</x-ui.button>
                        @endif
                        <x-ui.button type="submit">{{ $editing ? 'Simpan perubahan' : 'Simpan pola' }}</x-ui.button>
                    </x-slot:footer>
                </x-ui.form-section>
            </form>
        @endif

        @if ($bolehTerapkan)
            <x-ui.modal name="terapkan-rotasi" title="Terapkan rotasi" maxWidth="lg" :show="$terapkanGagal">
                <form id="form-terapkan-rotasi" method="POST" x-bind:action="aksi.replace('__ID__', rotasi)" class="grid gap-4">
                    @csrf
                    <input type="hidden" name="rotation_id" x-bind:value="rotasi">
                    <p class="text-sm text-slate-600">Pola <strong class="text-slate-900" x-text="nama[rotasi] ?? ''"></strong> diisikan ke jadwal karyawan terpilih, hari demi hari.</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input name="start_date" label="Tanggal mulai" type="date" required :value="old('start_date', today()->startOfWeek()->addWeek()->toDateString())" />
                        <x-ui.input name="end_date" label="Tanggal selesai" type="date" required :value="old('end_date', today()->startOfWeek()->addWeek()->addDays(27)->toDateString())" hint="Paling panjang 92 hari." />
                    </div>
                    <div>
                        <label for="f-offset" class="block text-sm font-medium text-slate-700">Tanggal mulai memakai</label>
                        <select id="f-offset" name="offset" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <template x-for="n in (panjang[rotasi] ?? 1)" :key="n">
                                <option x-bind:value="n - 1" x-text="'Hari ' + n + ' pola'" x-bind:selected="{{ (int) old('offset', 0) }} === n - 1"></option>
                            </template>
                        </select>
                        <p class="mt-1 text-xs text-slate-500">Untuk melanjutkan rotasi yang sudah berjalan, pilih hari pola yang jatuh pada tanggal mulai.</p>
                    </div>
                    <div>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-medium text-slate-700">Karyawan</p>
                            <div class="flex gap-2">
                                <input type="search" x-model="cari" placeholder="Cari nama" aria-label="Cari karyawan" class="block w-36 rounded-lg border-slate-300 py-1 text-sm">
                                <select x-model="dept" aria-label="Departemen" class="block rounded-lg border-slate-300 py-1 text-sm">
                                    <option value="">Semua departemen</option>
                                    @foreach ($departments as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="mt-2 max-h-56 overflow-y-auto rounded-lg border border-slate-200 p-2" x-ref="daftar">
                            @forelse ($employees as $e)
                                <label class="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-slate-50"
                                       x-show="(dept === '' || dept === '{{ $e->department_id }}') && (cari === '' || {{ \Illuminate\Support\Js::from(mb_strtolower($e->name.' '.$e->code)) }}.includes(cari.toLowerCase()))">
                                    <input type="checkbox" name="employee_ids[]" value="{{ $e->id }}" @checked(in_array($e->id, array_map('intval', (array) old('employee_ids', [])), true)) class="rounded border-slate-300 text-primary-600">
                                    <span>{{ $e->name }}</span><span class="text-xs text-slate-400">{{ $e->code }}</span>
                                </label>
                            @empty
                                <p class="p-2 text-sm text-slate-500">Belum ada karyawan aktif.</p>
                            @endforelse
                        </div>
                        <button type="button" class="mt-1 text-xs font-medium text-primary-700 hover:underline"
                                x-on:click="$refs.daftar.querySelectorAll('label').forEach(l => { if (l.style.display !== 'none') l.querySelector('input').checked = true })">Pilih semua yang tampil</button>
                        @error('employee_ids') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                    </div>
                    <x-ui.checkbox name="overwrite" label="Timpa jadwal yang sudah ada" :checked="(bool) old('overwrite')" hint="Tanpa centang, tanggal yang sudah berjadwal dilewati." />
                    <x-ui.checkbox name="skip_holidays" label="Kosongkan hari libur nasional/cabang" :checked="(bool) old('skip_holidays')" hint="Tanggal libur tidak diisi, jadi karyawan dianggap libur pada hari itu." />
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
                    <x-ui.button type="submit" form="form-terapkan-rotasi" icon="calendar-days">Terapkan ke jadwal</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    </div>
</x-app-layout>
