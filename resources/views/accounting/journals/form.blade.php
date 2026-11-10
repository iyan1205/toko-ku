{{--
    Jurnal manual. Total debit harus sama dengan total kredit; server memeriksa ulang dengan
    bilangan bulat (JournalService). Baris tanpa nilai diabaikan.
--}}
@php
    $baris = collect(old('lines', [
        ['account_id' => '', 'description' => '', 'debit' => '', 'credit' => ''],
        ['account_id' => '', 'description' => '', 'debit' => '', 'credit' => ''],
    ]))->map(fn ($l) => [
        'account_id' => (string) ($l['account_id'] ?? ''),
        'description' => (string) ($l['description'] ?? ''),
        'debit' => (string) ($l['debit'] ?? ''),
        'credit' => (string) ($l['credit'] ?? ''),
    ])->values();
    $awal = [
        'lines' => $baris,
        'errors' => collect($errors->getMessages())->filter(fn ($m, $key) => str_starts_with($key, 'lines.')),
    ];
    $input = 'block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';
    $th = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500';
@endphp

<x-app-layout>
    <x-slot name="header">Jurnal manual</x-slot>
    <x-slot name="description">Misal setoran modal, pembayaran beban, atau koreksi. Penjualan dan pembelian sudah dijurnal otomatis.</x-slot>

    @if ($errors->has('lines'))
        <x-ui.alert type="danger" class="mb-6">{{ $errors->first('lines') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('journals.store') }}" class="grid gap-6"
          x-data="{
              ...@js($awal),
              kirim: false,
              sen(v) { const n = parseFloat(v); return Number.isFinite(n) ? Math.round(n * 100) : 0 },
              get debit() { return this.lines.reduce((s, l) => s + this.sen(l.debit), 0) },
              get kredit() { return this.lines.reduce((s, l) => s + this.sen(l.credit), 0) },
              get selisih() { return this.debit - this.kredit },
              get seimbang() { return this.debit > 0 && this.selisih === 0 },
              tambah() { this.lines.push({ account_id: '', description: '', debit: '', credit: '' }) },
              hapus(i) { if (this.lines.length > 2) this.lines.splice(i, 1) },
              isiSisa(i) { const l = this.lines[i]; const s = this.selisih + this.sen(l.credit) - this.sen(l.debit); if (s > 0) { l.credit = (s / 100).toFixed(2); l.debit = '' } else if (s < 0) { l.debit = (-s / 100).toFixed(2); l.credit = '' } },
              galat(i, kolom) { return this.errors['lines.' + i + '.' + kolom]?.[0] ?? '' },
              rp(sen) { return 'Rp ' + new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(sen / 100) },
          }"
          x-on:submit="kirim = true">
        @csrf

        <x-ui.card>
            <div class="grid gap-5 sm:grid-cols-4">
                <x-ui.input name="journal_date" label="Tanggal" type="date" required :value="now()->format('Y-m-d')" :max="now()->format('Y-m-d')" />
                <x-ui.input name="description" label="Keterangan" required maxlength="255" class="sm:col-span-3" placeholder="Misal: Setoran modal awal pemilik" />
            </div>
        </x-ui.card>

        <x-ui.card :padding="false" title="Baris jurnal">
            <x-slot:actions>
                <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="tambah()">Tambah baris</x-ui.button>
            </x-slot:actions>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="{{ $th }} w-72">Akun</th>
                            <th class="{{ $th }}">Keterangan baris</th>
                            <th class="{{ $th }} w-40 text-right">Debit (Rp)</th>
                            <th class="{{ $th }} w-40 text-right">Kredit (Rp)</th>
                            <th class="w-20 px-2 py-3"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="(line, i) in lines" x-bind:key="i">
                            <tr class="align-top">
                                <td class="px-4 py-3">
                                    <select x-bind:name="'lines[' + i + '][account_id]'" x-model="line.account_id" class="{{ $input }}" x-bind:aria-label="'Akun baris ' + (i + 1)">
                                        <option value="">Pilih akun</option>
                                        @foreach ($accounts->groupBy(fn ($a) => $a->type->label()) as $kelompok => $daftar)
                                            <optgroup label="{{ $kelompok }}">
                                                @foreach ($daftar as $a)
                                                    <option value="{{ $a->id }}">{{ $a->code }} {{ $a->name }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-danger-600" x-show="galat(i, 'account_id')" x-text="galat(i, 'account_id')"></p>
                                </td>
                                <td class="px-4 py-3">
                                    <input type="text" maxlength="255" x-bind:name="'lines[' + i + '][description]'" x-model="line.description" class="{{ $input }}" x-bind:aria-label="'Keterangan baris ' + (i + 1)">
                                </td>
                                <td class="px-4 py-3">
                                    <input type="number" min="0" step="0.01" x-bind:name="'lines[' + i + '][debit]'" x-model="line.debit" x-on:input="if (line.debit !== '') line.credit = ''"
                                           class="{{ $input }} text-right" x-bind:aria-label="'Debit baris ' + (i + 1)">
                                    <p class="mt-1 text-xs text-danger-600" x-show="galat(i, 'debit')" x-text="galat(i, 'debit')"></p>
                                </td>
                                <td class="px-4 py-3">
                                    <input type="number" min="0" step="0.01" x-bind:name="'lines[' + i + '][credit]'" x-model="line.credit" x-on:input="if (line.credit !== '') line.debit = ''"
                                           class="{{ $input }} text-right" x-bind:aria-label="'Kredit baris ' + (i + 1)">
                                </td>
                                <td class="px-2 py-3 text-right">
                                    <div class="flex justify-end gap-1">
                                        <x-ui.button type="button" variant="ghost" size="sm" icon="scale" x-show="selisih !== 0" x-on:click="isiSisa(i)" x-bind:aria-label="'Isi selisih ke baris ' + (i + 1)" title="Isi selisih" />
                                        <x-ui.button type="button" variant="ghost" size="sm" icon="trash" class="text-danger-600 hover:text-danger-700" x-show="lines.length > 2" x-on:click="hapus(i)" x-bind:aria-label="'Hapus baris ' + (i + 1)" />
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="bg-slate-50 text-sm font-semibold text-slate-900">
                        <tr>
                            <td colspan="2" class="px-4 py-3">Total</td>
                            <td class="px-4 py-3 text-right tabular-nums" x-text="rp(debit)"></td>
                            <td class="px-4 py-3 text-right tabular-nums" x-text="rp(kredit)"></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-3 text-sm">
                <p x-show="seimbang" class="font-medium text-success-700">Seimbang: debit sama dengan kredit.</p>
                <p x-show="!seimbang && selisih !== 0" class="font-medium text-danger-600" x-text="'Belum seimbang, selisih ' + rp(Math.abs(selisih)) + (selisih > 0 ? ' (debit lebih besar)' : ' (kredit lebih besar)')"></p>
                <p x-show="debit === 0 && kredit === 0" class="text-slate-500">Isi debit atau kredit di setiap baris. Satu baris hanya boleh salah satunya.</p>
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button :href="route('journals.index')" variant="secondary">Batal</x-ui.button>
            <x-ui.button type="submit" icon="check" x-bind:disabled="kirim || !seimbang">Simpan jurnal</x-ui.button>
        </div>
    </form>
</x-app-layout>
