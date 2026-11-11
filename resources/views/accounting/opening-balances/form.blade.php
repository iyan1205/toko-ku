{{--
    Saldo awal baru / ubah draf. Empat bagian: saldo akun, persediaan per gudang, piutang per
    pelanggan, hutang per supplier. Selisih debit-kredit otomatis masuk Ekuitas Saldo Awal saat
    diposting. Server memeriksa ulang semua baris (OpeningBalanceService).
--}}
@use('App\Enums\OpeningBalanceKind')
@php
    $edit = $document->exists;
    $baris = fn (OpeningBalanceKind $kind) => $edit ? $document->linesOf($kind) : collect();
    $nilai = fn ($v) => (float) $v > 0 ? (string) $v : '';
    $awal = [
        'accounts' => old('accounts', $baris(OpeningBalanceKind::Account)->map(fn ($l) => ['account_id' => (string) $l->account_id, 'description' => (string) $l->description, 'debit' => $nilai($l->debit), 'credit' => $nilai($l->credit)])->all() ?: [['account_id' => '', 'description' => '', 'debit' => '', 'credit' => '']]),
        'inventory' => old('inventory', $baris(OpeningBalanceKind::Inventory)->map(fn ($l) => ['warehouse_id' => (string) $l->warehouse_id, 'amount' => $nilai($l->debit)])->all()),
        'receivables' => old('receivables', $baris(OpeningBalanceKind::Receivable)->map(fn ($l) => ['customer_id' => (string) $l->customer_id, 'reference' => (string) $l->reference, 'due_date' => $l->due_date?->format('Y-m-d') ?? '', 'amount' => $nilai($l->debit)])->all()),
        'payables' => old('payables', $baris(OpeningBalanceKind::Payable)->map(fn ($l) => ['supplier_id' => (string) $l->supplier_id, 'reference' => (string) $l->reference, 'due_date' => $l->due_date?->format('Y-m-d') ?? '', 'amount' => $nilai($l->credit)])->all()),
    ];
    $state = $awal + [
        'stok' => $stockValues,
        'errors' => collect($errors->getMessages())->filter(fn ($m, $key) => str_contains($key, '.')),
    ];
    $input = 'block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';
    $th = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500';
    $bolehPosting = auth()->user()->can('accounting.opening-balances.approve');
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah draf '.$document->number : 'Saldo awal' }}</x-slot>
    <x-slot name="description">Saldo pada tanggal mulai pembukuan di aplikasi ini, untuk cabang {{ $branchName }}. Selisih debit dan kredit masuk {{ $equity?->label() ?? 'Ekuitas Saldo Awal' }}.</x-slot>

    @if ($errors->has('accounts'))
        <x-ui.alert type="danger" class="mb-6">{{ $errors->first('accounts') }}</x-ui.alert>
    @endif
    @if ($existingPosted->isNotEmpty())
        <x-ui.alert type="warning" class="mb-6" title="Cabang ini sudah punya saldo awal yang diposting">
            {{ $existingPosted->join(', ') }}. Saldo awal baru menambah saldo tersebut. Untuk mengganti, batalkan saldo awal lama lalu buat yang baru.
        </x-ui.alert>
    @endif
    @unless ($equity)
        <x-ui.alert type="warning" class="mb-6">Akun Ekuitas Saldo Awal belum ada, jadi saldo awal yang tidak seimbang belum bisa diposting. Tambahkan lewat Bagan Akun → Tambahkan akun standar, atau pilih akun di Pengaturan → Akuntansi.</x-ui.alert>
    @endunless

    <form method="POST" action="{{ $edit ? route('opening-balances.update', $document) : route('opening-balances.store') }}" class="grid gap-6"
          x-data="{
              ...@js($state),
              kirim: false,
              sen(v) { const n = parseFloat(v); return Number.isFinite(n) ? Math.round(n * 100) : 0 },
              jumlah(daftar, kolom) { return daftar.reduce((s, b) => s + this.sen(b[kolom]), 0) },
              get debit() { return this.jumlah(this.accounts, 'debit') + this.jumlah(this.inventory, 'amount') + this.jumlah(this.receivables, 'amount') },
              get kredit() { return this.jumlah(this.accounts, 'credit') + this.jumlah(this.payables, 'amount') },
              get selisih() { return this.debit - this.kredit },
              tambah(bagian, kosong) { this[bagian].push(kosong) },
              hapus(bagian, i) { this[bagian].splice(i, 1) },
              isiStok() { this.inventory = Object.entries(this.stok).map(([id, nilai]) => ({ warehouse_id: String(id), amount: String(nilai) })) },
              galat(bagian, i, kolom) { return this.errors[bagian + '.' + i + '.' + kolom]?.[0] ?? '' },
              rp(sen) { return 'Rp ' + new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(sen / 100) },
          }"
          x-on:submit="kirim = true">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <x-ui.card>
            <div class="grid gap-5 sm:grid-cols-4">
                <x-ui.input name="balance_date" label="Saldo per tanggal" type="date" required :value="$document->balance_date?->format('Y-m-d')" :max="now()->format('Y-m-d')"
                            hint="Biasanya sehari sebelum mulai mencatat di aplikasi." />
                <x-ui.input name="description" label="Keterangan" maxlength="255" class="sm:col-span-3" :value="$document->description" placeholder="Misal: Saldo awal per tutup buku Oktober" />
            </div>
        </x-ui.card>

        {{-- Saldo akun --}}
        <x-ui.card :padding="false" title="Saldo akun" description="Kas, bank, aset tetap, modal, hutang bank, dan akun lain. Persediaan, piutang usaha, dan hutang usaha diisi di bagian masing-masing.">
            <x-slot:actions>
                <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="tambah('accounts', { account_id: '', description: '', debit: '', credit: '' })">Tambah baris</x-ui.button>
            </x-slot:actions>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="{{ $th }} w-72">Akun</th>
                            <th class="{{ $th }}">Keterangan</th>
                            <th class="{{ $th }} w-40 text-right">Debit (Rp)</th>
                            <th class="{{ $th }} w-40 text-right">Kredit (Rp)</th>
                            <th class="w-12 px-2 py-3"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="(baris, i) in accounts" x-bind:key="i">
                            <tr class="align-top">
                                <td class="px-4 py-3">
                                    <select x-bind:name="'accounts[' + i + '][account_id]'" x-model="baris.account_id" class="{{ $input }}" x-bind:aria-label="'Akun baris ' + (i + 1)">
                                        <option value="">Pilih akun</option>
                                        @foreach ($accounts->groupBy(fn ($a) => $a->type->label()) as $kelompok => $daftar)
                                            <optgroup label="{{ $kelompok }}">
                                                @foreach ($daftar as $a)
                                                    <option value="{{ $a->id }}">{{ $a->code }} {{ $a->name }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-danger-600" x-show="galat('accounts', i, 'account_id')" x-text="galat('accounts', i, 'account_id')"></p>
                                </td>
                                <td class="px-4 py-3"><input type="text" maxlength="255" x-bind:name="'accounts[' + i + '][description]'" x-model="baris.description" class="{{ $input }}" x-bind:aria-label="'Keterangan baris ' + (i + 1)"></td>
                                <td class="px-4 py-3">
                                    <input type="number" min="0" step="0.01" x-bind:name="'accounts[' + i + '][debit]'" x-model="baris.debit" x-on:input="if (baris.debit !== '') baris.credit = ''" class="{{ $input }} text-right" x-bind:aria-label="'Debit baris ' + (i + 1)">
                                    <p class="mt-1 text-xs text-danger-600" x-show="galat('accounts', i, 'debit')" x-text="galat('accounts', i, 'debit')"></p>
                                </td>
                                <td class="px-4 py-3"><input type="number" min="0" step="0.01" x-bind:name="'accounts[' + i + '][credit]'" x-model="baris.credit" x-on:input="if (baris.credit !== '') baris.debit = ''" class="{{ $input }} text-right" x-bind:aria-label="'Kredit baris ' + (i + 1)"></td>
                                <td class="px-2 py-3 text-right"><x-ui.button type="button" variant="ghost" size="sm" icon="trash" class="text-danger-600 hover:text-danger-700" x-on:click="hapus('accounts', i)" x-bind:aria-label="'Hapus baris ' + (i + 1)" /></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        {{-- Persediaan --}}
        <x-ui.card :padding="false" title="Persediaan" description="Nilai rupiah barang di tiap gudang. Jumlah barangnya tetap diatur di Inventori (stok opname atau penyesuaian).">
            <x-slot:actions>
                @if ($stockValues !== [])
                    <x-ui.button type="button" variant="ghost" size="sm" icon="arrow-path" x-on:click="isiStok()">Isi dari stok saat ini</x-ui.button>
                @endif
                <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="tambah('inventory', { warehouse_id: '', amount: '' })">Tambah gudang</x-ui.button>
            </x-slot:actions>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="{{ $th }}">Gudang</th>
                            <th class="{{ $th }} w-48 text-right">Nilai (Rp)</th>
                            <th class="w-12 px-2 py-3"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="(baris, i) in inventory" x-bind:key="i">
                            <tr class="align-top">
                                <td class="px-4 py-3">
                                    <select x-bind:name="'inventory[' + i + '][warehouse_id]'" x-model="baris.warehouse_id" class="{{ $input }}" x-bind:aria-label="'Gudang baris ' + (i + 1)">
                                        <option value="">Pilih gudang</option>
                                        @foreach ($warehouses as $w)
                                            <option value="{{ $w->id }}">{{ $w->name }}</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-danger-600" x-show="galat('inventory', i, 'warehouse_id')" x-text="galat('inventory', i, 'warehouse_id')"></p>
                                </td>
                                <td class="px-4 py-3">
                                    <input type="number" min="0" step="0.01" x-bind:name="'inventory[' + i + '][amount]'" x-model="baris.amount" class="{{ $input }} text-right" x-bind:aria-label="'Nilai persediaan baris ' + (i + 1)">
                                    <p class="mt-1 text-xs text-danger-600" x-show="galat('inventory', i, 'amount')" x-text="galat('inventory', i, 'amount')"></p>
                                </td>
                                <td class="px-2 py-3 text-right"><x-ui.button type="button" variant="ghost" size="sm" icon="trash" class="text-danger-600 hover:text-danger-700" x-on:click="hapus('inventory', i)" x-bind:aria-label="'Hapus baris ' + (i + 1)" /></td>
                            </tr>
                        </template>
                        <tr x-show="inventory.length === 0"><td colspan="3" class="px-4 py-4 text-sm text-slate-500">Belum ada nilai persediaan.</td></tr>
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        {{-- Piutang dan hutang per pihak --}}
        @foreach ([
            ['bagian' => 'receivables', 'judul' => 'Piutang pelanggan', 'kolom' => 'customer_id', 'pihak' => $customers, 'label' => 'Pelanggan', 'ket' => 'Tagihan ke pelanggan yang belum dibayar per tanggal saldo awal. Satu baris per faktur lama atau per pelanggan.'],
            ['bagian' => 'payables', 'judul' => 'Hutang supplier', 'kolom' => 'supplier_id', 'pihak' => $suppliers, 'label' => 'Supplier', 'ket' => 'Tagihan dari supplier yang belum dibayar per tanggal saldo awal.'],
        ] as $s)
            <x-ui.card :padding="false" :title="$s['judul']" :description="$s['ket']">
                <x-slot:actions>
                    <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="tambah('{{ $s['bagian'] }}', { {{ $s['kolom'] }}: '', reference: '', due_date: '', amount: '' })">Tambah baris</x-ui.button>
                </x-slot:actions>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="{{ $th }} w-72">{{ $s['label'] }}</th>
                                <th class="{{ $th }}">No. faktur lama</th>
                                <th class="{{ $th }} w-44">Jatuh tempo</th>
                                <th class="{{ $th }} w-44 text-right">Nilai (Rp)</th>
                                <th class="w-12 px-2 py-3"><span class="sr-only">Aksi</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="(baris, i) in {{ $s['bagian'] }}" x-bind:key="i">
                                <tr class="align-top">
                                    <td class="px-4 py-3">
                                        <select x-bind:name="'{{ $s['bagian'] }}[' + i + '][{{ $s['kolom'] }}]'" x-model="baris.{{ $s['kolom'] }}" class="{{ $input }}" x-bind:aria-label="'{{ $s['label'] }} baris ' + (i + 1)">
                                            <option value="">Pilih {{ mb_strtolower($s['label']) }}</option>
                                            @foreach ($s['pihak'] as $p)
                                                <option value="{{ $p->id }}">{{ $p->name }}{{ $p->code ? ' ('.$p->code.')' : '' }}</option>
                                            @endforeach
                                        </select>
                                        <p class="mt-1 text-xs text-danger-600" x-show="galat('{{ $s['bagian'] }}', i, '{{ $s['kolom'] }}')" x-text="galat('{{ $s['bagian'] }}', i, '{{ $s['kolom'] }}')"></p>
                                    </td>
                                    <td class="px-4 py-3"><input type="text" maxlength="50" x-bind:name="'{{ $s['bagian'] }}[' + i + '][reference]'" x-model="baris.reference" class="{{ $input }}" x-bind:aria-label="'Nomor faktur baris ' + (i + 1)"></td>
                                    <td class="px-4 py-3"><input type="date" x-bind:name="'{{ $s['bagian'] }}[' + i + '][due_date]'" x-model="baris.due_date" class="{{ $input }}" x-bind:aria-label="'Jatuh tempo baris ' + (i + 1)"></td>
                                    <td class="px-4 py-3">
                                        <input type="number" min="0" step="0.01" x-bind:name="'{{ $s['bagian'] }}[' + i + '][amount]'" x-model="baris.amount" class="{{ $input }} text-right" x-bind:aria-label="'Nilai baris ' + (i + 1)">
                                        <p class="mt-1 text-xs text-danger-600" x-show="galat('{{ $s['bagian'] }}', i, 'amount')" x-text="galat('{{ $s['bagian'] }}', i, 'amount')"></p>
                                    </td>
                                    <td class="px-2 py-3 text-right"><x-ui.button type="button" variant="ghost" size="sm" icon="trash" class="text-danger-600 hover:text-danger-700" x-on:click="hapus('{{ $s['bagian'] }}', i)" x-bind:aria-label="'Hapus baris ' + (i + 1)" /></td>
                                </tr>
                            </template>
                            <tr x-show="{{ $s['bagian'] }}.length === 0"><td colspan="5" class="px-4 py-4 text-sm text-slate-500">Belum ada {{ mb_strtolower($s['judul']) }}.</td></tr>
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endforeach

        <x-ui.card>
            <dl class="grid gap-4 text-sm sm:grid-cols-3">
                <div><dt class="text-slate-500">Total debit</dt><dd class="mt-1 text-lg font-semibold tabular-nums text-slate-900" x-text="rp(debit)"></dd></div>
                <div><dt class="text-slate-500">Total kredit</dt><dd class="mt-1 text-lg font-semibold tabular-nums text-slate-900" x-text="rp(kredit)"></dd></div>
                <div>
                    <dt class="text-slate-500">Ke {{ $equity?->name ?? 'Ekuitas Saldo Awal' }}</dt>
                    <dd class="mt-1 text-lg font-semibold tabular-nums" x-bind:class="selisih === 0 ? 'text-slate-900' : 'text-primary-700'" x-text="selisih === 0 ? 'Seimbang' : rp(Math.abs(selisih)) + (selisih > 0 ? ' (kredit)' : ' (debit)')"></dd>
                </div>
            </dl>
        </x-ui.card>

        <div class="flex flex-wrap items-center justify-end gap-2">
            <p class="mr-auto text-xs text-slate-500">Draf belum memengaruhi saldo akun. Saldo awal yang sudah diposting tidak bisa diubah; batalkan lalu buat yang baru.</p>
            <x-ui.button :href="$edit ? route('opening-balances.show', $document) : route('opening-balances.index')" variant="secondary">Batal</x-ui.button>
            <x-ui.button type="submit" name="aksi" value="draft" variant="secondary" x-bind:disabled="kirim">Simpan draf</x-ui.button>
            @if ($bolehPosting)
                <x-ui.button type="submit" name="aksi" value="post" icon="check" x-bind:disabled="kirim">Simpan &amp; posting</x-ui.button>
            @endif
        </div>
    </form>
</x-app-layout>
