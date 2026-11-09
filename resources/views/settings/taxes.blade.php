{{-- Pajak penjualan (PPN). Contoh hitungan dihitung di browser dari isian form. --}}
@php
    $bolehUbah = auth()->user()->can('settings.taxes.update');
    $awal = [
        'enabled' => (bool) old('enabled', $tax['enabled']),
        'name' => (string) old('name', $tax['name']),
        'rate' => (string) old('rate', rtrim(rtrim(number_format($tax['rate'], 2, '.', ''), '0'), '.')),
        'inclusive' => (bool) old('inclusive', $tax['inclusive']),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">Pajak</x-slot>
    <x-slot name="description">Pajak penjualan yang dikenakan di kasir.</x-slot>

    <x-ui.alert type="warning" class="mb-6">
        Pengaturan ini belum dipakai di transaksi kasir. Total penjualan belum berubah sampai perhitungan pajak di kasir diaktifkan.
    </x-ui.alert>

    <form method="POST" action="{{ route('settings.taxes.update') }}" class="grid gap-8"
          x-data="{
              ...@js($awal),
              contoh: 100000,
              get tarif() { const t = parseFloat(String(this.rate).replace(',', '.')); return isNaN(t) ? 0 : t; },
              get dasar() { return this.inclusive ? this.contoh / (1 + this.tarif / 100) : this.contoh; },
              get pajak() { return this.inclusive ? this.contoh - this.dasar : this.contoh * this.tarif / 100; },
              get total() { return this.inclusive ? this.contoh : this.contoh + this.pajak; },
              rp(n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); },
          }">
        @csrf
        @method('PUT')

        <fieldset @disabled(! $bolehUbah) class="grid gap-8">
            <x-ui.form-section title="Pajak penjualan" description="Toko yang belum menjadi Pengusaha Kena Pajak (PKP) biasanya tidak memungut PPN.">
                <x-ui.checkbox name="enabled" label="Pungut pajak di setiap penjualan" :checked="$tax['enabled']" unchecked-value="0" x-model="enabled" />

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input name="name" label="Nama pajak" :value="$tax['name']" maxlength="20" required x-model="name" hint="Tercetak di struk, misal PPN." />
                    <x-ui.input name="rate" label="Tarif" :value="$awal['rate']" inputmode="decimal" suffix="%" required x-model="rate" hint="Contoh: 11 atau 11,5" />
                </div>

                <fieldset>
                    <legend class="text-sm font-medium text-slate-700">Harga jual produk</legend>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach ([1 => ['Sudah termasuk pajak', 'Harga di kasir tetap; pajak dihitung dari dalam harga.'], 0 => ['Belum termasuk pajak', 'Pajak ditambahkan di atas harga saat pembayaran.']] as $nilai => [$judul, $keterangan])
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 transition-colors hover:bg-slate-50 has-[:checked]:border-primary-500 has-[:checked]:bg-primary-50/60">
                                <input type="radio" name="inclusive" value="{{ $nilai }}" @checked($awal['inclusive'] === (bool) $nilai)
                                       x-on:change="inclusive = {{ $nilai ? 'true' : 'false' }}"
                                       class="mt-0.5 border-slate-300 text-primary-600 focus:ring-primary-500">
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-slate-900">{{ $judul }}</span>
                                    <span class="block text-xs text-slate-500">{{ $keterangan }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="rounded-lg bg-slate-50 p-4 text-sm" aria-live="polite">
                    <p class="font-medium text-slate-700">Contoh untuk harga produk Rp 100.000</p>
                    <dl class="mt-2 grid gap-1 tabular-nums">
                        <div class="flex justify-between"><dt class="text-slate-500">Dasar pengenaan pajak</dt><dd class="text-slate-900" x-text="rp(dasar)"></dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500" x-text="(name || 'Pajak') + ' ' + tarif.toLocaleString('id-ID') + '%'"></dt><dd class="text-slate-900" x-text="rp(pajak)"></dd></div>
                        <div class="flex justify-between border-t border-slate-200 pt-1 font-semibold"><dt class="text-slate-700">Dibayar pelanggan</dt><dd class="text-slate-900" x-text="rp(total)"></dd></div>
                    </dl>
                </div>

                @if ($bolehUbah)
                    <x-slot:footer>
                        <x-ui.button type="submit">Simpan pengaturan pajak</x-ui.button>
                    </x-slot:footer>
                @endif
            </x-ui.form-section>
        </fieldset>
    </form>
</x-app-layout>
