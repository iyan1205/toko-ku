{{--
    Dialog "Terima pelunasan" untuk satu penjualan piutang. Buka dengan:
        target = { url: route('receivables.store', $penjualan), nomor: '...', pelanggan: '...', sisa: 12345, jumlah: 12345 }; $dispatch('open-modal', 'terima-piutang')
    Butuh x-data berisi `target` di elemen pembungkus (lihat pos/receivables/index dan sales/show).
    Bila validasi gagal, dialog terbuka lagi dengan isian dan penjualan yang sama (_piutang_*).

    @include('partials.terima-piutang', ['methods' => PaymentMethod::untukPembelian()])
--}}

<x-ui.modal name="terima-piutang" title="Terima pelunasan piutang" :show="$errors->hasAny(['amount', 'method', 'paid_at', 'note']) && filled(old('_piutang_url'))">
    <form id="form-terima-piutang" method="POST" x-bind:action="target.url" class="grid gap-4">
        @csrf
        <input type="hidden" name="_piutang_url" x-bind:value="target.url">
        <input type="hidden" name="_piutang_nomor" x-bind:value="target.nomor">
        <input type="hidden" name="_piutang_pelanggan" x-bind:value="target.pelanggan">
        <input type="hidden" name="_piutang_sisa" x-bind:value="target.sisa">

        <p class="text-sm text-slate-600">
            <span class="font-medium text-slate-900" x-text="target.pelanggan"></span> ·
            penjualan <span class="font-medium text-slate-900" x-text="target.nomor"></span> ·
            sisa piutang <span class="font-medium tabular-nums text-slate-900" x-text="'Rp ' + Number(target.sisa).toLocaleString('id-ID')"></span>
        </p>

        <x-ui.input name="amount" label="Jumlah diterima" type="number" prefix="Rp" min="0.01" step="0.01" required
                    x-bind:max="target.sisa" x-model="target.jumlah" hint="Boleh dicicil. Tidak boleh melebihi sisa piutang." />
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.select name="method" label="Metode" required :options="collect($methods)->mapWithKeys(fn ($m) => [$m->value => $m->label()])" :selected="old('method', 'cash')" />
            <x-ui.input name="paid_at" label="Tanggal terima" type="date" required :value="old('paid_at', now()->format('Y-m-d'))" :max="now()->format('Y-m-d')" />
        </div>
        <x-ui.input name="note" label="Catatan" maxlength="255" placeholder="Misal nomor bukti transfer" />
        <p class="text-xs text-slate-500">Uang tunai yang diterima saat sesi kasir Anda terbuka masuk ke rekap setoran sesi itu.</p>
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
        <x-ui.button type="submit" form="form-terima-piutang" icon="banknotes">Simpan pelunasan</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
