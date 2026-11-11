{{--
    Pelunasan baru. Langkah 1: pilih pihak dan jenis (form GET). Langkah 2: uang (rekening/giro),
    uang muka yang dipakai, dan alokasi ke dokumen terbuka. Server memeriksa ulang (SettlementService).
--}}
@use('App\Support\Format')
@use('App\Support\Uang')
@use('App\Enums\SettlementType')
@php
    $prefix = $type->routePrefix();
    $pihakKey = $receivable ? 'pelanggan' : 'supplier';
    $tunai = $type->isCash();
    $alokasiLama = old('allocations', []);
    $dokumen = $documents->map(fn ($d) => [
        'key' => $d['key'],
        'number' => $d['number'],
        'label' => $d['label'],
        'date' => Format::tanggal($d['date']),
        'due' => $d['due_date'] ? Format::tanggal($d['due_date']) : null,
        'overdue' => $d['due_date'] && $d['due_date'] < now()->toDateString(),
        'total' => Uang::sen($d['total']),
        'balance' => Uang::sen($d['balance']),
        'alloc' => (string) ($alokasiLama[$d['key']] ?? ($preselect === $d['key'] ? $d['balance'] : '')),
    ])->values()->all();
    $state = [
        'docs' => $dokumen,
        'amount' => (string) old('amount', $preselect !== '' && $tunai ? (collect($dokumen)->firstWhere('key', $preselect)['alloc'] ?? '') : ''),
        'depositApplied' => (string) old('deposit_applied', ''),
        'deposit' => $deposit,
        'method' => old('method', 'transfer'),
        'tunai' => $tunai,
    ];
    $input = 'block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';
    $th = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500';
@endphp

<x-app-layout>
    <x-slot name="header">{{ $type->label() }}</x-slot>
    <x-slot name="description">{{ $tunai
        ? ($receivable ? 'Uang dari pelanggan untuk satu atau beberapa faktur. Kelebihan menjadi uang muka pelanggan.' : 'Uang ke supplier untuk satu atau beberapa faktur. Kelebihan menjadi uang muka pembelian.')
        : ($receivable ? 'Potongan tagihan ke pelanggan setelah faktur terbit, misal retur jasa atau kompensasi. Tanpa uang.' : 'Potongan hutang dari supplier setelah faktur terbit, misal klaim kurang kirim. Tanpa uang.') }}</x-slot>

    <x-ui.card class="mb-6">
        <form method="GET" action="{{ route($prefix.'.create') }}" class="grid gap-5 sm:grid-cols-4 sm:items-end">
            <x-ui.select name="jenis" label="Jenis" :options="collect($types)->mapWithKeys(fn ($t) => [$t->value => $t->label()])" :selected="$type->value" />
            <x-ui.select :name="$pihakKey" :label="$receivable ? 'Pelanggan' : 'Supplier'" required class="sm:col-span-2" :options="$parties" :selected="$party?->id" placeholder="Pilih" />
            <x-ui.button type="submit" variant="secondary" icon="magnifying-glass">Tampilkan faktur</x-ui.button>
        </form>
    </x-ui.card>

    @if ($party)
        <form method="POST" action="{{ route($prefix.'.store') }}" class="grid gap-6"
              x-data="{
                  ...@js($state),
                  kirim: false,
                  sen(v) { const n = parseFloat(v); return Number.isFinite(n) ? Math.round(n * 100) : 0 },
                  get dialokasikan() { return this.docs.reduce((s, d) => s + this.sen(d.alloc), 0) },
                  get tersedia() { return this.sen(this.amount) + this.sen(this.depositApplied) },
                  get sisa() { return this.tersedia - this.dialokasikan },
                  get lebih() { return this.docs.some(d => this.sen(d.alloc) > d.balance) },
                  get valid() { return this.dialokasikan > 0 || (this.tunai && this.sen(this.amount) > 0) },
                  lunasi(d) { d.alloc = this.sen(d.alloc) > 0 ? '' : (d.balance / 100).toFixed(2) },
                  bagi() {
                      let sisa = this.tersedia;
                      this.docs.forEach(d => { const n = Math.min(sisa, d.balance); d.alloc = n > 0 ? (n / 100).toFixed(2) : ''; sisa -= Math.max(0, n) });
                  },
                  rp(sen) { return 'Rp ' + new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(sen / 100) },
              }"
              x-on:submit="kirim = true">
            @csrf
            <input type="hidden" name="type" value="{{ $type->value }}">
            <input type="hidden" name="{{ $receivable ? 'customer_id' : 'supplier_id' }}" value="{{ $party->id }}">

            @if ($errors->has('allocations'))
                <x-ui.alert type="danger">{{ $errors->first('allocations') }}</x-ui.alert>
            @endif

            <x-ui.card>
                <div class="grid gap-5 sm:grid-cols-4">
                    <x-ui.input name="settlement_date" label="Tanggal" type="date" required :value="now()->format('Y-m-d')" :max="now()->format('Y-m-d')" />
                    <x-ui.input name="reference" label="Referensi" maxlength="50" placeholder="Misal no. bukti transfer" />
                    <x-ui.input name="description" label="Keterangan" maxlength="255" class="sm:col-span-2" />
                </div>

                @if ($tunai)
                    <div class="mt-5 grid gap-5 sm:grid-cols-4">
                        <x-ui.select name="method" label="Cara bayar" required x-model="method" :options="['cash' => 'Tunai', 'transfer' => 'Transfer / non-tunai', 'giro' => 'Giro / cek mundur']" :selected="$state['method']" />
                        <div class="sm:col-span-2" x-show="method !== 'giro'">
                            <x-ui.select name="cash_bank_account_id" :label="$receivable ? 'Masuk ke rekening' : 'Dari rekening'" :options="$rekening->mapWithKeys(fn ($r) => [$r->id => $r->label().' ('.$r->kind->label().')'])" placeholder="Pilih rekening" />
                        </div>
                        <x-ui.input name="amount" :label="$receivable ? 'Uang diterima (Rp)' : 'Uang dibayar (Rp)'" type="number" min="0" step="0.01" x-model="amount" />
                    </div>
                    <div class="mt-5 grid gap-5 sm:grid-cols-4" x-show="method === 'giro'" x-cloak>
                        <x-ui.input name="giro_number" label="Nomor giro/cek" maxlength="50" />
                        <x-ui.input name="giro_bank" label="Bank" maxlength="100" />
                        <x-ui.input name="giro_due_date" label="Jatuh tempo giro" type="date" />
                        <p class="text-xs text-slate-500 sm:pt-7">Faktur langsung berkurang; uangnya masuk/keluar rekening saat giro dicatat cair di Kas &amp; Bank → Giro &amp; Cek.</p>
                    </div>
                    @if ($deposit > 0)
                        <div class="mt-5 grid gap-5 sm:grid-cols-4">
                            <x-ui.input name="deposit_applied" label="Pakai uang muka (Rp)" type="number" min="0" step="0.01" x-model="depositApplied"
                                        :hint="'Saldo uang muka '.$party->name.': '.Format::rupiah(Uang::keDesimal($deposit))" />
                        </div>
                    @endif
                @else
                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <x-ui.select name="counter_account_id" label="Akun lawan nota" required :options="$counterAccounts->mapWithKeys(fn ($a) => [$a->id => $a->code.' '.$a->name])" :selected="$defaultCounter" placeholder="Pilih akun"
                                     :hint="$receivable ? 'Bawaan: Potongan Penjualan.' : 'Bawaan: Potongan Pembelian.'" />
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card :padding="false" :title="'Faktur terbuka '.$party->name" :description="$documents->isEmpty() ? null : 'Isi nilai yang dilunasi per faktur, atau klik Bagi otomatis (faktur paling lama dulu).'">
                @if ($tunai && $documents->isNotEmpty())
                    <x-slot:actions>
                        <x-ui.button type="button" variant="secondary" size="sm" icon="bars-arrow-down" x-on:click="bagi()">Bagi otomatis</x-ui.button>
                    </x-slot:actions>
                @endif
                @if ($documents->isEmpty())
                    <div class="px-5 py-6 text-sm text-slate-500">
                        Tidak ada faktur yang belum lunas.
                        @if ($tunai)
                            Uang yang dicatat seluruhnya menjadi uang muka {{ $receivable ? 'pelanggan' : 'ke supplier' }}.
                        @endif
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="{{ $th }}">Dokumen</th>
                                    <th class="{{ $th }}">Jatuh tempo</th>
                                    <th class="{{ $th }} text-right">Sisa</th>
                                    <th class="{{ $th }} w-48 text-right">Dilunasi (Rp)</th>
                                    <th class="w-24 px-2 py-3"><span class="sr-only">Aksi</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <template x-for="d in docs" x-bind:key="d.key">
                                    <tr class="align-top">
                                        <td class="px-4 py-3">
                                            <p class="font-medium text-slate-900" x-text="d.number"></p>
                                            <p class="text-xs text-slate-500" x-text="d.label + ' · ' + d.date"></p>
                                        </td>
                                        <td class="px-4 py-3" x-bind:class="d.overdue ? 'font-medium text-danger-700' : 'text-slate-500'" x-text="d.due ?? '—'"></td>
                                        <td class="px-4 py-3 text-right tabular-nums" x-text="rp(d.balance)"></td>
                                        <td class="px-4 py-3">
                                            <input type="number" min="0" step="0.01" x-bind:name="'allocations[' + d.key + ']'" x-model="d.alloc" class="{{ $input }} text-right" x-bind:aria-label="'Nilai dilunasi ' + d.number">
                                            <p class="mt-1 text-xs text-danger-600" x-show="sen(d.alloc) > d.balance">Melebihi sisa.</p>
                                        </td>
                                        <td class="px-2 py-3 text-right">
                                            <button type="button" class="text-xs font-medium text-primary-700 hover:underline" x-on:click="lunasi(d)" x-text="sen(d.alloc) > 0 ? 'Kosongkan' : 'Lunasi'"></button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card>
                <dl class="grid gap-4 text-sm sm:grid-cols-3">
                    <div><dt class="text-slate-500">Dialokasikan ke faktur</dt><dd class="mt-1 text-lg font-semibold tabular-nums text-slate-900" x-text="rp(dialokasikan)"></dd></div>
                    @if ($tunai)
                        <div><dt class="text-slate-500">Uang + uang muka dipakai</dt><dd class="mt-1 text-lg font-semibold tabular-nums text-slate-900" x-text="rp(tersedia)"></dd></div>
                        <div>
                            <dt class="text-slate-500" x-text="sisa >= 0 ? 'Menjadi uang muka baru' : 'Kurang'"></dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums" x-bind:class="sisa >= 0 ? 'text-slate-900' : 'text-danger-700'" x-text="rp(Math.abs(sisa))"></dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>

            <div class="flex justify-end gap-2">
                <x-ui.button :href="route($prefix.'.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit" icon="check" x-bind:disabled="kirim || !valid || lebih || (tunai && sisa < 0)">Simpan</x-ui.button>
            </div>
        </form>
    @else
        <x-ui.card>
            <x-ui.empty-state icon="user" :title="$receivable ? 'Pilih pelanggan' : 'Pilih supplier'" description="Faktur yang belum lunas dan saldo uang mukanya muncul setelah pihaknya dipilih." />
        </x-ui.card>
    @endif
</x-app-layout>
