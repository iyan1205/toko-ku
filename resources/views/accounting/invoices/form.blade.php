{{-- Faktur penjualan / tagihan pembelian manual: baris keterangan + akun + jumlah x harga, pajak opsional. Dihitung ulang di server (InvoiceService). --}}
@use('App\Enums\InvoiceType')
@php
    $edit = $invoice->exists;
    $penjualan = $invoice->type === InvoiceType::Sales;
    $prefix = $invoice->type->routePrefix();
    $kosong = ['description' => '', 'account_id' => (string) ($defaultAccount ?? ''), 'cost_center_id' => '', 'quantity' => '1', 'unit_price' => ''];
    $barisAwal = $edit
        ? $invoice->lines->map(fn ($l) => ['description' => $l->description, 'account_id' => (string) $l->account_id, 'cost_center_id' => (string) $l->cost_center_id, 'quantity' => rtrim(rtrim((string) $l->quantity, '0'), '.'), 'unit_price' => (string) $l->unit_price])->all()
        : [$kosong];
    $state = [
        'lines' => collect(old('lines', $barisAwal))->map(fn ($l) => array_map(fn ($v) => (string) ($v ?? ''), array_merge($kosong, $l)))->values()->all(),
        'kosong' => $kosong,
        'tarif' => (string) old('tax_rate', (float) $invoice->tax_rate),
        'pph' => (string) old('withholding_code', $invoice->withholding_code ?? ''),
        'tarifPph' => $withholdings->mapWithKeys(fn ($r) => [$r->code => (float) $r->rate])->all(),
        'errors' => collect($errors->getMessages())->filter(fn ($m, $key) => str_starts_with($key, 'lines.')),
    ];
    $input = 'block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';
    $th = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500';
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah draf '.$invoice->number : ($penjualan ? 'Faktur penjualan baru' : 'Tagihan pembelian baru') }}</x-slot>
    <x-slot name="description">{{ $penjualan ? 'Tagihan non-stok ke pelanggan. Setelah diposting, sisa tagihan masuk piutang pelanggan.' : 'Tagihan non-stok dari supplier. Setelah diposting, sisa tagihan masuk hutang supplier.' }}</x-slot>

    @if ($errors->has('lines'))
        <x-ui.alert type="danger" class="mb-6">{{ $errors->first('lines') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ $edit ? route($prefix.'.update', $invoice) : route($prefix.'.store') }}" class="grid gap-6"
          x-data="{
              ...@js($state),
              kirim: false,
              sen(v) { const n = parseFloat(v); return Number.isFinite(n) ? Math.round(n * 100) : 0 },
              baris(l) { const q = Math.round((parseFloat(l.quantity) || 0) * 1000); return Math.round(q * this.sen(l.unit_price) / 1000) },
              get subtotal() { return this.lines.reduce((s, l) => s + this.baris(l), 0) },
              get pajak() { return Math.round(this.subtotal * (parseFloat(this.tarif) || 0) / 100) },
              tambah() { this.lines.push({ ...this.kosong }) },
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
                @if ($penjualan)
                    <x-ui.select name="customer_id" label="Pelanggan" required class="sm:col-span-2" :options="$parties" :selected="$invoice->customer_id" placeholder="Pilih pelanggan" />
                @else
                    <x-ui.select name="supplier_id" label="Supplier" required class="sm:col-span-2" :options="$parties" :selected="$invoice->supplier_id" placeholder="Pilih supplier" />
                @endif
                <x-ui.input name="invoice_date" label="Tanggal" type="date" required :value="($invoice->invoice_date ?? now())->format('Y-m-d')" :max="now()->format('Y-m-d')" />
                <x-ui.input name="due_date" label="Jatuh tempo" type="date" :value="$invoice->due_date?->format('Y-m-d')" hint="Kosong = ikut termin pembayaran pihaknya." />
            </div>
            <div class="mt-5 grid gap-5 sm:grid-cols-4">
                <x-ui.input name="reference" :label="$penjualan ? 'No. PO / referensi' : 'No. faktur supplier'" :value="$invoice->reference" maxlength="50" />
                <x-ui.input name="description" label="Keterangan" :value="$invoice->description" maxlength="255" class="sm:col-span-3" />
            </div>
        </x-ui.card>

        <x-ui.card :padding="false" title="Rincian">
            <x-slot:actions>
                <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="tambah()">Tambah baris</x-ui.button>
            </x-slot:actions>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="{{ $th }}">Keterangan</th>
                            <th class="{{ $th }} w-64">{{ $penjualan ? 'Akun pendapatan' : 'Akun beban/aset' }}</th>
                            @if ($costCenters->isNotEmpty())
                                <th class="{{ $th }} w-40">Cost center</th>
                            @endif
                            <th class="{{ $th }} w-24 text-right">Jumlah</th>
                            <th class="{{ $th }} w-40 text-right">Harga (Rp)</th>
                            <th class="{{ $th }} w-36 text-right">Subtotal</th>
                            <th class="w-12 px-2 py-3"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="(line, i) in lines" x-bind:key="i">
                            <tr class="align-top">
                                <td class="px-4 py-3">
                                    <input type="text" maxlength="255" x-bind:name="'lines[' + i + '][description]'" x-model="line.description" class="{{ $input }}" x-bind:aria-label="'Keterangan baris ' + (i + 1)">
                                    <p class="mt-1 text-xs text-danger-600" x-show="galat(i, 'description')" x-text="galat(i, 'description')"></p>
                                </td>
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
                                @if ($costCenters->isNotEmpty())
                                    <td class="px-4 py-3">
                                        <select x-bind:name="'lines[' + i + '][cost_center_id]'" x-model="line.cost_center_id" class="{{ $input }}" x-bind:aria-label="'Cost center baris ' + (i + 1)">
                                            <option value="">Tanpa</option>
                                            @foreach ($costCenters as $c)
                                                <option value="{{ $c->id }}">{{ $c->code }} {{ $c->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                @endif
                                <td class="px-4 py-3"><input type="number" min="0" step="0.001" x-bind:name="'lines[' + i + '][quantity]'" x-model="line.quantity" class="{{ $input }} text-right" x-bind:aria-label="'Jumlah baris ' + (i + 1)"></td>
                                <td class="px-4 py-3">
                                    <input type="number" min="0" step="0.01" x-bind:name="'lines[' + i + '][unit_price]'" x-model="line.unit_price" class="{{ $input }} text-right" x-bind:aria-label="'Harga baris ' + (i + 1)">
                                    <p class="mt-1 text-xs text-danger-600" x-show="galat(i, 'unit_price')" x-text="galat(i, 'unit_price')"></p>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-900" x-text="rp(baris(line))"></td>
                                <td class="px-2 py-3 text-right">
                                    <x-ui.button type="button" variant="ghost" size="sm" icon="trash" class="text-danger-600 hover:text-danger-700" x-show="lines.length > 1" x-on:click="hapus(i)" x-bind:aria-label="'Hapus baris ' + (i + 1)" />
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="grid gap-2 border-t border-slate-200 px-5 py-4 text-sm sm:ml-auto sm:w-96">
                <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span class="tabular-nums" x-text="rp(subtotal)"></span></div>
                <div class="flex items-center justify-between gap-3">
                    <label for="f-tax_rate" class="text-slate-500">Pajak (%)</label>
                    <input id="f-tax_rate" type="number" name="tax_rate" min="0" max="100" step="0.01" x-model="tarif" class="{{ $input }} w-24 text-right">
                    <span class="w-32 text-right tabular-nums" x-text="rp(pajak)"></span>
                </div>
                @error('tax_rate')
                    <p class="text-sm text-danger-600">{{ $message }}</p>
                @enderror
                <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold text-slate-900"><span>Total</span><span class="tabular-nums" x-text="rp(subtotal + pajak)"></span></div>
                @if ($withholdings->isNotEmpty())
                    <div class="mt-2 grid gap-2 border-t border-slate-200 pt-2">
                        <label for="f-withholding_code" class="text-slate-500">PPh dipotong</label>
                        <select id="f-withholding_code" name="withholding_code" x-model="pph" class="{{ $input }}">
                            <option value="">Tidak ada</option>
                            @foreach ($withholdings as $r)
                                <option value="{{ $r->code }}">{{ $r->name }} ({{ (float) $r->rate }}%)</option>
                            @endforeach
                        </select>
                        @error('withholding_code')
                            <p class="text-sm text-danger-600">{{ $message }}</p>
                        @enderror
                        <div class="flex justify-between" x-show="pph"><span class="text-slate-500">Dipotong (disetor sendiri)</span><span class="tabular-nums" x-text="'− ' + rp(Math.round(subtotal * (tarifPph[pph] ?? 0) / 100))"></span></div>
                        <div class="flex justify-between font-semibold text-slate-900" x-show="pph"><span>Dibayar ke supplier</span><span class="tabular-nums" x-text="rp(subtotal + pajak - Math.round(subtotal * (tarifPph[pph] ?? 0) / 100))"></span></div>
                    </div>
                @endif
            </div>
        </x-ui.card>

        <div class="flex flex-wrap items-center justify-end gap-2">
            <x-ui.button :href="$edit ? route($prefix.'.show', $invoice) : route($prefix.'.index')" variant="secondary">Batal</x-ui.button>
            <x-ui.button type="submit" name="aksi" value="draft" variant="secondary" x-bind:disabled="kirim">Simpan draf</x-ui.button>
            <x-ui.button type="submit" name="aksi" value="post" icon="check" x-bind:disabled="kirim || subtotal <= 0">Simpan &amp; posting</x-ui.button>
        </div>
    </form>
</x-app-layout>
