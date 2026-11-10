{{--
    Tambah / ubah alur persetujuan. Kondisi (semua harus terpenuhi) dan tahap diatur dengan Alpine;
    server memvalidasi ulang (ApprovalFlowRequest). Tahap disimpan berurutan sesuai daftar.
--}}
@php
    $edit = $flow->exists;
    $kondisi = collect(old('conditions', $flow->conditions ?? []))->map(fn ($c) => [
        'field' => (string) ($c['field'] ?? 'total'),
        'operator' => (string) ($c['operator'] ?? '>'),
        'value' => (string) ($c['value'] ?? ''),
    ])->values();
    $tahap = collect(old('steps', $edit
        ? $flow->steps->map(fn ($s) => ['name' => $s->name, 'approver_type' => $s->approver_type->value, 'role_id' => $s->role_id, 'user_id' => $s->user_id])->all()
        : [['name' => 'Persetujuan atasan', 'approver_type' => 'role', 'role_id' => $roles->firstWhere('name', 'Admin')?->id, 'user_id' => null]]
    ))->map(fn ($s) => [
        'name' => (string) ($s['name'] ?? ''),
        'approver_type' => (string) ($s['approver_type'] ?? 'role'),
        'role_id' => (string) ($s['role_id'] ?? ''),
        'user_id' => (string) ($s['user_id'] ?? ''),
    ])->values();
    $awal = [
        'dokumen' => (string) old('document_type', $flow->document_type),
        'kolom' => collect($documents)->map(fn ($d) => $d['fields'])->all(),
        'kondisi' => $kondisi,
        'tahap' => $tahap,
        'galat' => collect($errors->getMessages())->filter(fn ($m, $k) => str_starts_with($k, 'steps.') || str_starts_with($k, 'conditions.'))->map(fn ($m) => $m[0]),
    ];
    $input = 'block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah alur persetujuan' : 'Tambah alur persetujuan' }}</x-slot>
    <x-slot name="description">Tentukan dokumen yang perlu disetujui, kondisinya, dan siapa penyetujunya di setiap tahap.</x-slot>

    <form method="POST" action="{{ $edit ? route('settings.approval-flows.update', $flow) : route('settings.approval-flows.store') }}" class="grid gap-8"
          x-data="{
              ...@js($awal),
              tambahKondisi() { this.kondisi.push({ field: Object.keys(this.kolom[this.dokumen] ?? { total: '' })[0], operator: '>', value: '' }) },
              tambahTahap() { this.tahap.push({ name: '', approver_type: 'role', role_id: '', user_id: '' }) },
              pindah(i, arah) { const j = i + arah; if (j < 0 || j >= this.tahap.length) return; const t = this.tahap[i]; this.tahap[i] = this.tahap[j]; this.tahap[j] = t; },
              err(k) { return this.galat[k] ?? '' },
          }">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <x-ui.form-section title="Alur" description="Nama tampil di kotak masuk persetujuan dan riwayat dokumen.">
            <x-ui.input name="name" label="Nama alur" :value="$flow->name" maxlength="100" required placeholder="Misal: PO besar" />
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.select name="document_type" label="Jenis dokumen" required x-model="dokumen"
                             :options="collect($documents)->map(fn ($d) => $d['label'])" :selected="$flow->document_type" />
                <x-ui.select name="branch_id" label="Cabang" :options="$branches->pluck('name', 'id')" :selected="$flow->branch_id" placeholder="Semua cabang" />
                <x-ui.input name="priority" label="Prioritas" type="number" min="1" max="999" :value="$flow->priority" required hint="Angka kecil dipakai lebih dulu bila beberapa alur cocok." />
            </div>
            <x-ui.textarea name="description" label="Keterangan" :value="$flow->description" rows="2" maxlength="255" />
            <x-ui.checkbox name="is_active" label="Alur aktif" :checked="$flow->is_active ?? true" unchecked-value="0" />
            <x-ui.checkbox name="allow_self_approval" label="Pengaju boleh menyetujui pengajuannya sendiri" :checked="$flow->allow_self_approval" unchecked-value="0"
                           hint="Bawaan tidak: persetujuan harus oleh orang lain, walau pengaju punya peran penyetuju." />
        </x-ui.form-section>

        <x-ui.form-section title="Kondisi" description="Semua kondisi harus terpenuhi. Tanpa kondisi, semua dokumen jenis ini perlu persetujuan.">
            <div class="grid gap-3">
                <template x-for="(k, i) in kondisi" x-bind:key="i">
                    <div class="grid gap-2 sm:grid-cols-[1fr_1fr_1.5fr_auto] sm:items-start">
                        <select x-bind:name="'conditions[' + i + '][field]'" x-model="k.field" class="{{ $input }}" x-bind:aria-label="'Kolom kondisi ' + (i + 1)">
                            <template x-for="(label, kunci) in (kolom[dokumen] ?? {})" x-bind:key="kunci">
                                <option x-bind:value="kunci" x-text="label" x-bind:selected="kunci === k.field"></option>
                            </template>
                        </select>
                        <select x-bind:name="'conditions[' + i + '][operator]'" x-model="k.operator" class="{{ $input }}" x-bind:aria-label="'Operator kondisi ' + (i + 1)">
                            @foreach ($operators as $op => $label)
                                <option value="{{ $op }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <div>
                            <div class="relative">
                                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400">Rp</span>
                                <input type="number" min="0" step="0.01" x-bind:name="'conditions[' + i + '][value]'" x-model="k.value" class="{{ $input }} pl-9" x-bind:aria-label="'Nilai kondisi ' + (i + 1)">
                            </div>
                            <p class="mt-1 text-xs text-danger-600" x-show="err('conditions.' + i + '.value')" x-text="err('conditions.' + i + '.value')"></p>
                        </div>
                        <x-ui.button type="button" variant="ghost" size="sm" icon="trash" class="text-danger-600 hover:text-danger-700" x-on:click="kondisi.splice(i, 1)" x-bind:aria-label="'Hapus kondisi ' + (i + 1)" />
                    </div>
                </template>
                <p x-show="kondisi.length === 0" class="text-sm text-slate-500">Tanpa kondisi: semua dokumen jenis ini perlu persetujuan.</p>
                <div><x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="tambahKondisi()">Tambah kondisi</x-ui.button></div>
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Tahap persetujuan" description="Disetujui berurutan dari atas. Penyetuju: siapa pun dengan peran tertentu, atau satu pengguna tertentu.">
            @error('steps')
                <x-ui.alert type="danger">{{ $message }}</x-ui.alert>
            @enderror
            <ol class="grid gap-3">
                <template x-for="(t, i) in tahap" x-bind:key="i">
                    <li class="rounded-lg border border-slate-200 p-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-slate-900" x-text="'Tahap ' + (i + 1)"></p>
                            <div class="flex gap-1">
                                <x-ui.button type="button" variant="ghost" size="sm" icon="arrow-up" x-on:click="pindah(i, -1)" x-bind:disabled="i === 0" x-bind:aria-label="'Naikkan tahap ' + (i + 1)" />
                                <x-ui.button type="button" variant="ghost" size="sm" icon="arrow-down" x-on:click="pindah(i, 1)" x-bind:disabled="i === tahap.length - 1" x-bind:aria-label="'Turunkan tahap ' + (i + 1)" />
                                <x-ui.button type="button" variant="ghost" size="sm" icon="trash" class="text-danger-600 hover:text-danger-700" x-show="tahap.length > 1" x-on:click="tahap.splice(i, 1)" x-bind:aria-label="'Hapus tahap ' + (i + 1)" />
                            </div>
                        </div>
                        <div class="mt-2 grid gap-2 sm:grid-cols-3">
                            <div>
                                <input type="text" maxlength="100" placeholder="Nama tahap, misal Kepala Gudang" x-bind:name="'steps[' + i + '][name]'" x-model="t.name" class="{{ $input }}" x-bind:aria-label="'Nama tahap ' + (i + 1)">
                                <p class="mt-1 text-xs text-danger-600" x-show="err('steps.' + i + '.name')" x-text="err('steps.' + i + '.name')"></p>
                            </div>
                            <select x-bind:name="'steps[' + i + '][approver_type]'" x-model="t.approver_type" class="{{ $input }}" x-bind:aria-label="'Jenis penyetuju tahap ' + (i + 1)">
                                <option value="role">Peran</option>
                                <option value="user">Pengguna tertentu</option>
                            </select>
                            <div>
                                <select x-show="t.approver_type === 'role'" x-bind:name="t.approver_type === 'role' ? 'steps[' + i + '][role_id]' : ''" x-model="t.role_id" class="{{ $input }}" x-bind:aria-label="'Peran penyetuju tahap ' + (i + 1)">
                                    <option value="">Pilih peran</option>
                                    @foreach ($roles as $role)
                                        <option value="{{ $role->id }}">{{ $role->name }}</option>
                                    @endforeach
                                </select>
                                <select x-show="t.approver_type === 'user'" x-cloak x-bind:name="t.approver_type === 'user' ? 'steps[' + i + '][user_id]' : ''" x-model="t.user_id" class="{{ $input }}" x-bind:aria-label="'Pengguna penyetuju tahap ' + (i + 1)">
                                    <option value="">Pilih pengguna</option>
                                    @foreach ($users as $u)
                                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-danger-600" x-show="err('steps.' + i + '.role_id') || err('steps.' + i + '.user_id')" x-text="err('steps.' + i + '.role_id') || err('steps.' + i + '.user_id')"></p>
                            </div>
                        </div>
                    </li>
                </template>
            </ol>
            <div><x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="tambahTahap()">Tambah tahap</x-ui.button></div>

            <x-slot:footer>
                <x-ui.button :href="route('settings.approval-flows.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit">{{ $edit ? 'Simpan perubahan' : 'Simpan alur' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
