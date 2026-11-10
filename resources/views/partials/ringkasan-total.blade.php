{{-- Ringkasan angka formulir dokumenBarang: subtotal (sebelum diskon baris), diskon, pajak, biaya, total. --}}
<dl class="grid gap-2 text-sm">
    <div class="flex justify-between"><dt class="text-slate-600">Subtotal</dt><dd class="font-medium tabular-nums" x-text="rp(subtotal)"></dd></div>
    <div class="flex justify-between" x-show="diskonBaris > 0"><dt class="text-slate-600">Diskon baris</dt><dd class="font-medium tabular-nums text-danger-600" x-text="'− ' + rp(diskonBaris)"></dd></div>
    <div class="flex justify-between"><dt class="text-slate-600">Diskon</dt><dd class="font-medium tabular-nums text-danger-600" x-text="'− ' + rp(diskon)"></dd></div>
    <div class="flex justify-between"><dt class="text-slate-600">Pajak</dt><dd class="font-medium tabular-nums" x-text="rp(pajak)"></dd></div>
    <div class="flex justify-between"><dt class="text-slate-600">Biaya lain</dt><dd class="font-medium tabular-nums" x-text="rp(biaya)"></dd></div>
    <div class="flex justify-between border-t border-slate-200 pt-2 text-base"><dt class="font-semibold text-slate-900">Total</dt><dd class="font-semibold tabular-nums text-slate-900" x-text="rp(total)"></dd></div>
</dl>
