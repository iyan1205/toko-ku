{{--
    Dialog "Bayar hutang" untuk satu faktur pembelian. Buka dengan:
        target = { url: route('payables.store', $faktur), nomor: '...', sisa: 12345 }; $dispatch('open-modal', 'bayar-hutang')
    Butuh x-data berisi `target` di elemen pembungkus (lihat purchasing/payables/index dan purchases/show).
    Bila validasi gagal, dialog terbuka lagi dengan isian dan faktur yang sama (_bayar_*).

    @include('partials.bayar-hutang', ['methods' => PaymentMethod::untukPembelian()])
--}}

<x-ui.modal name="bayar-hutang" title="Bayar hutang" :show="$errors->hasAny(['amount', 'method', 'paid_at', 'note']) && filled(old('_bayar_url'))">
    <form id="form-bayar-hutang" method="POST" x-bind:action="target.url" class="grid gap-4">
        @csrf
        <input type="hidden" name="_bayar_url" x-bind:value="target.url">
        <input type="hidden" name="_bayar_nomor" x-bind:value="target.nomor">
        <input type="hidden" name="_bayar_sisa" x-bind:value="target.sisa">

        <p class="text-sm text-slate-600">
            Faktur <span class="font-medium text-slate-900" x-text="target.nomor"></span> ·
            sisa hutang <span class="font-medium tabular-nums text-slate-900" x-text="'Rp ' + Math.round(target.sisa).toLocaleString('id-ID')"></span>
        </p>

        <x-ui.input name="amount" label="Jumlah bayar" type="number" prefix="Rp" min="0.01" step="0.01" required
                    x-bind:max="target.sisa" x-model="target.jumlah" hint="Boleh dicicil. Tidak boleh melebihi sisa hutang." />
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.select name="method" label="Metode" required :options="collect($methods)->mapWithKeys(fn ($m) => [$m->value => $m->label()])" :selected="old('method', 'transfer')" />
            <x-ui.input name="paid_at" label="Tanggal bayar" type="date" required :value="old('paid_at', now()->format('Y-m-d'))" :max="now()->format('Y-m-d')" />
        </div>
        <x-ui.input name="note" label="Catatan" maxlength="255" placeholder="Misal nomor bukti transfer" />
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
        <x-ui.button type="submit" form="form-bayar-hutang" icon="banknotes">Simpan pembayaran</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
