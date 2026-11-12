{{-- Anggaran: satu baris per akun, 12 kolom bulan. "Rata" membagi total tahunan ke 12 bulan. --}}
@php
    $edit = $budget->exists;
    $bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $awal = collect(old('lines') ? collect(old('lines'))->map(fn ($m, $id) => ['account_id' => (string) $id, 'months' => array_map('strval', array_replace(array_fill(1, 12, ''), (array) $m))])->values()
        : $budget->lines->groupBy('account_id')->map(fn ($ls, $id) => ['account_id' => (string) $id, 'months' => array_replace(array_fill(1, 12, ''), $ls->mapWithKeys(fn ($l) => [$l->month => (string) $l->amount])->all())])->values());
    $state = [
        'rows' => $awal->all(),
        'accounts' => $accounts->mapWithKeys(fn ($a) => [$a->id => $a->code.' '.$a->name])->all(),
        'pilih' => '',
    ];
    $input = 'block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah anggaran '.$budget->name : 'Anggaran baru' }}</x-slot>
    <x-slot name="description">Isi rencana per akun per bulan. Kosong = tidak dianggarkan.</x-slot>

    @if ($errors->has('lines'))
        <x-ui.alert type="danger" class="mb-6">{{ $errors->first('lines') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ $edit ? route('budgets.update', $budget) : route('budgets.store') }}" class="grid gap-6"
          x-data="{
              ...@js($state),
              sen(v) { const n = parseFloat(v); return Number.isFinite(n) ? Math.round(n * 100) : 0 },
              total(r) { return Object.values(r.months).reduce((s, v) => s + this.sen(v), 0) },
              get semua() { return this.rows.reduce((s, r) => s + this.total(r), 0) },
              tambah() { if (this.pilih && ! this.rows.some(r => r.account_id === this.pilih)) { const m = {}; for (let i = 1; i <= 12; i++) m[i] = ''; this.rows.push({ account_id: this.pilih, months: m }) } this.pilih = '' },
              rata(r) { const t = prompt('Total setahun (Rp)', (this.total(r) / 100).toFixed(0)); const s = this.sen(t); if (s > 0) { const per = Math.floor(s / 12); for (let i = 1; i <= 12; i++) r.months[i] = ((i === 12 ? s - per * 11 : per) / 100).toFixed(2) } },
              hapus(i) { this.rows.splice(i, 1) },
              rp(sen) { return 'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(sen / 100) },
          }">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <x-ui.card>
            <div class="grid gap-5 sm:grid-cols-4">
                <x-ui.input name="name" label="Nama anggaran" maxlength="100" required class="sm:col-span-2" :value="$budget->name" />
                <x-ui.input name="fiscal_year" label="Tahun" type="number" min="2000" max="2100" required :value="$budget->fiscal_year" />
                <x-ui.select name="branch_id" label="Cabang" :options="$branches" :selected="$budget->branch_id" placeholder="Semua cabang" />
            </div>
            <div class="mt-5 grid gap-5 sm:grid-cols-4">
                <x-ui.select name="cost_center_id" label="Cost center" :options="$costCenters" :selected="$budget->cost_center_id" placeholder="Semua cost center" />
                <x-ui.input name="notes" label="Catatan" maxlength="255" class="sm:col-span-3" :value="$budget->notes" />
            </div>
        </x-ui.card>

        <x-ui.card :padding="false" title="Nilai per bulan (Rp)">
            <x-slot:actions>
                <div class="flex items-center gap-2">
                    <select x-model="pilih" class="{{ $input }} w-64" aria-label="Pilih akun untuk ditambahkan">
                        <option value="">Tambah akun…</option>
                        @foreach ($accounts->groupBy(fn ($a) => $a->type->label()) as $kelompok => $daftar)
                            <optgroup label="{{ $kelompok }}">
                                @foreach ($daftar as $a)
                                    <option value="{{ $a->id }}">{{ $a->code }} {{ $a->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <x-ui.button type="button" size="sm" variant="secondary" icon="plus" x-on:click="tambah()">Tambah</x-ui.button>
                </div>
            </x-slot:actions>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="sticky left-0 bg-slate-50 px-3 py-2 text-left text-xs font-semibold uppercase text-slate-500">Akun</th>
                            @foreach ($bulan as $b)
                                <th class="px-2 py-2 text-right text-xs font-semibold uppercase text-slate-500">{{ $b }}</th>
                            @endforeach
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-slate-500">Setahun</th>
                            <th class="px-2 py-2"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="(r, i) in rows" x-bind:key="r.account_id">
                            <tr>
                                <td class="sticky left-0 min-w-[14rem] bg-white px-3 py-2 text-slate-900" x-text="accounts[r.account_id] ?? r.account_id"></td>
                                @for ($m = 1; $m <= 12; $m++)
                                    <td class="px-1 py-2"><input type="number" min="0" step="0.01" x-bind:name="'lines[' + r.account_id + '][{{ $m }}]'" x-model="r.months[{{ $m }}]" class="{{ $input }} w-28 text-right" x-bind:aria-label="(accounts[r.account_id] ?? '') + ' bulan {{ $m }}'"></td>
                                @endfor
                                <td class="px-3 py-2 text-right font-medium tabular-nums" x-text="rp(total(r))"></td>
                                <td class="whitespace-nowrap px-2 py-2 text-right">
                                    <button type="button" class="text-xs font-medium text-primary-700 hover:underline" x-on:click="rata(r)">Rata</button>
                                    <button type="button" class="ml-2 text-xs text-danger-600 hover:underline" x-on:click="hapus(i)">Hapus</button>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="rows.length === 0"><td colspan="15" class="px-4 py-6 text-sm text-slate-500">Pilih akun pendapatan, HPP, atau beban di kanan atas.</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="flex justify-end border-t border-slate-200 px-5 py-3 text-sm font-semibold text-slate-900">Total <span class="ml-3 tabular-nums" x-text="rp(semua)"></span></div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button :href="$edit ? route('budgets.show', $budget) : route('budgets.index')" variant="secondary">Batal</x-ui.button>
            <x-ui.button type="submit" icon="check">Simpan anggaran</x-ui.button>
        </div>
    </form>
</x-app-layout>
