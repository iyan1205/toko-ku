{{-- Pengaturan Inventori: metode HPP dan aturan batch kedaluwarsa. --}}
@php
    $bolehUbah = auth()->user()->can('settings.inventory.update');
@endphp

<x-app-layout>
    <x-slot name="header">Inventori</x-slot>
    <x-slot name="description">Metode harga pokok persediaan dan aturan barang berbatch.</x-slot>

    <form method="POST" action="{{ route('settings.inventory.update') }}" class="grid gap-8">
        @csrf
        @method('PUT')

        <fieldset @disabled(! $bolehUbah) class="grid gap-8">
            <x-ui.form-section title="Metode HPP" description="Dipakai untuk biaya barang keluar (penjualan, retur ke supplier, penyesuaian) dan nilai persediaan. Perubahan berlaku untuk mutasi berikutnya.">
                <x-ui.select name="costing_method" label="Metode" required :selected="$values['costing_method']"
                             :options="['average' => 'Rata-rata tertimbang (rata-rata per gudang)', 'fifo' => 'FIFO (masuk pertama, keluar pertama)']"
                             hint="Barang berbatch selalu keluar dari batch dengan kedaluwarsa terdekat; metode ini menentukan biayanya." />
            </x-ui.form-section>

            <x-ui.form-section title="Kedaluwarsa" description="Untuk produk yang melacak batch & tanggal kedaluwarsa.">
                <x-ui.checkbox name="block_expired_sales" label="Jangan jual batch yang sudah kedaluwarsa" :checked="$values['block_expired_sales']" unchecked-value="0"
                               hint="Kasir menolak penjualan bila sisa stok yang belum kedaluwarsa tidak cukup." />
                <x-ui.input name="expiry_warning_days" label="Ingatkan sebelum kedaluwarsa" type="number" min="1" max="365" suffix="hari" :value="$values['expiry_warning_days']" required
                            hint="Notifikasi harian dan laporan Mendekati Kedaluwarsa." />
            </x-ui.form-section>

            <x-ui.form-section title="Laporan" description="Batas untuk laporan barang lambat laku (slow moving).">
                <x-ui.input name="slow_moving_days" label="Barang dianggap lambat laku bila tidak terjual" type="number" min="7" max="730" suffix="hari" :value="$values['slow_moving_days']" required
                            hint="Tidak terjual sama sekali dua kali lipat dari angka ini = barang mati (dead stock)." />

                @if ($bolehUbah)
                    <x-slot:footer>
                        <x-ui.button type="submit">Simpan pengaturan inventori</x-ui.button>
                    </x-slot:footer>
                @endif
            </x-ui.form-section>
        </fieldset>
    </form>
</x-app-layout>
