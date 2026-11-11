{{--
    Penerimaan / pengeluaran kas (rekening + baris akun lawan) atau transfer antar rekening (+ biaya admin).
    Server memeriksa ulang semua nilai (CashTransactionService). Pengeluaran dan transfer mengikuti
    alur persetujuan bila ada yang cocok (Pengaturan → Alur Persetujuan).
--}}
@use('App\Enums\CashTransactionType')
@php
    $edit = $transaction->exists;
    $type = $transaction->type;
    $transfer = $type === CashTransactionType::Transfer;
    $barisAwal = $edit
        ? $transaction->lines->map(fn ($l) => ['account_id' => (string) $l->account_id, 'cost_center_id' => (string) $l->cost_center_id, 'description' => (string) $l->description, 'amount' => (string) $l->amount])->all()
        : [['account_id' => '', 'cost_center_id' => '', 'description' => '', 'amount' => '']];
    $baris = collect(old('lines', $barisAwal))->map(fn ($l) => [
        'account_id' => (string) ($l['account_id'] ?? ''),
        'cost_center_id' => (string) ($l['cost_center_id'] ?? ''),
        'description' => (string) ($l['description'] ?? ''),
        'amount' => (string) ($l['amount'] ?? ''),
    ])->values()->all();
    $state = [
        'lines' => $baris,
        'amount' => (string) old('amount', $transaction->amount ?? ''),
        'fee' => (string) old('fee', (float) $transaction->fee > 0 ? $transaction->fee : ''),
        'errors' => collect($errors->getMessages())->filter(fn ($m, $key) => str_starts_with($key, 'lines.')),
    ];
    $rekeningLabel = match ($type) {
        CashTransactionType::In => 'Diterima di rekening',
        CashTransactionType::Out => 'Dibayar dari rekening',
        default => 'Dari rekening',
    };
    $input = 'block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';
    $th = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500';
    $pilihanRekening = $rekening->mapWithKeys(fn ($r) => [$r->id => $r->label().' ('.$r->kind->label().')']);
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah draf '.$transaction->number : $type->label() }}</x-slot>
    <x-slot name="description">{{ match ($type) {
        CashTransactionType::In => 'Uang masuk di luar penjualan, misal setoran modal, pinjaman, atau pendapatan lain.',
        CashTransactionType::Out => 'Uang keluar di luar pembelian barang, misal listrik, sewa, gaji harian, atau ATK.',
        default => 'Pindahkan uang antar rekening, misal setor kas ke bank atau isi ulang kas kecil.',
    } }}</x-slot>

    @if ($errors->has('lines'))
        <x-ui.alert type="danger" class="mb-6">{{ $errors->first('lines') }}</x-ui.alert>
    @endif
    @if ($rekening->isEmpty())
        <x-ui.alert type="warning" class="mb-6">Belum ada rekening kas/bank yang aktif di cabang ini. Tambahkan di Kas &amp; Bank → Rekening.</x-ui.alert>
    @endif

    <form method="POST" action="{{ $edit ? route('cash-transactions.update', $transaction) : route('cash-transactions.store', $type->slug()) }}" class="grid gap-6"
          x-data="{
              ...@js($state),
              kirim: false,
              sen(v) { const n = parseFloat(v); return Number.isFinite(n) ? Math.round(n * 100) : 0 },
              get total() { return {{ $transfer ? 'this.sen(this.amount) + this.sen(this.fee)' : 'this.lines.reduce((s, l) => s + this.sen(l.amount), 0)' }} },
              tambah() { this.lines.push({ account_id: '', cost_center_id: '', description: '', amount: '' }) },
              hapus(i) { if (this.lines.length > 1) this.lines.splice(i, 1) },
              galat(i, kolom) { return this.errors['lines.' + i + '.' + kolom]?.[0] ?? '' },
              rp(sen) { return 'Rp ' + new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(sen / 100) },
          }"
          x-on:submit="kirim = true">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <x-ui.card>
            <div class="grid gap-5 sm:grid-cols-4">
                <x-ui.input name="transaction_date" label="Tanggal" type="date" required :value="($transaction->transaction_date ?? now())->format('Y-m-d')" :max="now()->format('Y-m-d')" />
                <x-ui.select name="cash_bank_account_id" :label="$rekeningLabel" required class="sm:col-span-2" :options="$pilihanRekening" :selected="$transaction->cash_bank_account_id" placeholder="Pilih rekening" />
                <x-ui.input name="reference" label="No. bukti / referensi" :value="$transaction->reference" maxlength="50" />
            </div>
            <div class="mt-5 grid gap-5 sm:grid-cols-4">
                <x-ui.input name="description" label="Keterangan" required maxlength="255" class="sm:col-span-3" :value="$transaction->description"
                            :placeholder="$transfer ? 'Misal: Setor kas ke BCA' : ($type === CashTransactionType::In ? 'Misal: Setoran modal pemilik' : 'Misal: Bayar listrik November')" />
                @unless ($transfer)
                    <x-ui.input name="counterparty" :label="$type === CashTransactionType::In ? 'Diterima dari' : 'Dibayar kepada'" :value="$transaction->counterparty" maxlength="150" />
                @endunless
            </div>
        </x-ui.card>

        @if ($transfer)
            <x-ui.card title="Transfer">
                <div class="grid gap-5 sm:grid-cols-4">
                    <x-ui.select name="target_cash_bank_account_id" label="Ke rekening" required class="sm:col-span-2" :options="$pilihanRekening" :selected="$transaction->target_cash_bank_account_id" placeholder="Pilih rekening tujuan" />
                    <x-ui.input name="amount" label="Jumlah (Rp)" type="number" min="0" step="0.01" required x-model="amount" :value="$transaction->amount" />
                    <x-ui.input name="fee" label="Biaya admin (Rp)" type="number" min="0" step="0.01" x-model="fee" :value="(float) $transaction->fee > 0 ? $transaction->fee : ''" hint="Dipotong dari rekening asal." />
                </div>
                <div class="mt-5 grid gap-5 sm:grid-cols-2" x-show="sen(fee) > 0" x-cloak>
                    <x-ui.select name="fee_account_id" label="Akun biaya admin" :options="$expenseAccounts->mapWithKeys(fn ($a) => [$a->id => $a->code.' '.$a->name])"
                                 :selected="$transaction->fee_account_id ?? $feeAccount?->id" placeholder="Pilih akun beban" />
                </div>
            </x-ui.card>
        @else
            <x-ui.card :padding="false" :title="$type === CashTransactionType::In ? 'Sumber uang' : 'Untuk keperluan'"
                       :description="$type === CashTransactionType::In ? 'Akun lawan, misal Modal Pemilik, Pendapatan Lain-lain, atau Hutang Bank.' : 'Akun beban atau aset yang dibayar. Satu transaksi boleh untuk beberapa keperluan.'">
                <x-slot:actions>
                    <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="tambah()">Tambah baris</x-ui.button>
                </x-slot:actions>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="{{ $th }} w-72">Akun</th>
                                <th class="{{ $th }}">Keterangan</th>
                                @if ($costCenters->isNotEmpty())
                                    <th class="{{ $th }} w-44">Cost center</th>
                                @endif
                                <th class="{{ $th }} w-44 text-right">Jumlah (Rp)</th>
                                <th class="w-12 px-2 py-3"><span class="sr-only">Aksi</span></th>
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
                                    <td class="px-4 py-3"><input type="text" maxlength="255" x-bind:name="'lines[' + i + '][description]'" x-model="line.description" class="{{ $input }}" x-bind:aria-label="'Keterangan baris ' + (i + 1)"></td>
                                    @if ($costCenters->isNotEmpty())
                                        <td class="px-4 py-3">
                                            <select x-bind:name="'lines[' + i + '][cost_center_id]'" x-model="line.cost_center_id" class="{{ $input }}" x-bind:aria-label="'Cost center baris ' + (i + 1)">
                                                <option value="">Tanpa</option>
                                                @foreach ($costCenters as $c)
                                                    <option value="{{ $c->id }}">{{ $c->code }} {{ $c->name }}</option>
                                                @endforeach
                                            </select>
                                            <p class="mt-1 text-xs text-danger-600" x-show="galat(i, 'cost_center_id')" x-text="galat(i, 'cost_center_id')"></p>
                                        </td>
                                    @endif
                                    <td class="px-4 py-3">
                                        <input type="number" min="0" step="0.01" x-bind:name="'lines[' + i + '][amount]'" x-model="line.amount" class="{{ $input }} text-right" x-bind:aria-label="'Jumlah baris ' + (i + 1)">
                                        <p class="mt-1 text-xs text-danger-600" x-show="galat(i, 'amount')" x-text="galat(i, 'amount')"></p>
                                    </td>
                                    <td class="px-2 py-3 text-right">
                                        <x-ui.button type="button" variant="ghost" size="sm" icon="trash" class="text-danger-600 hover:text-danger-700" x-show="lines.length > 1" x-on:click="hapus(i)" x-bind:aria-label="'Hapus baris ' + (i + 1)" />
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif

        <x-ui.card>
            <div class="flex items-center justify-between text-sm">
                <span class="text-slate-500">{{ $transfer ? 'Total keluar dari rekening asal' : 'Total' }}</span>
                <span class="text-lg font-semibold tabular-nums text-slate-900" x-text="rp(total)"></span>
            </div>
        </x-ui.card>

        <div class="flex flex-wrap items-center justify-end gap-2">
            @unless ($type === CashTransactionType::In)
                <p class="mr-auto text-xs text-slate-500">Bila perlu persetujuan (Pengaturan → Alur Persetujuan), transaksi diajukan otomatis dan diposting setelah disetujui.</p>
            @endunless
            <x-ui.button :href="$edit ? route('cash-transactions.show', $transaction) : route('cash-transactions.index')" variant="secondary">Batal</x-ui.button>
            <x-ui.button type="submit" name="aksi" value="draft" variant="secondary" x-bind:disabled="kirim">Simpan draf</x-ui.button>
            <x-ui.button type="submit" name="aksi" value="post" icon="check" x-bind:disabled="kirim || total <= 0">Simpan &amp; posting</x-ui.button>
        </div>
    </form>
</x-app-layout>
