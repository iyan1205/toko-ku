<x-app-layout>
    <x-slot name="header">Detail penjualan</x-slot>

    <div x-data x-on:keydown.window="if ($event.key === 'F10') { $event.preventDefault(); window.print(); }"
         class="max-w-4xl space-y-6">

        @if ($penjualan->dibatalkan())
            <div class="rounded-lg border border-red-200 bg-red-50 p-5 print:hidden" role="status">
                <p class="font-semibold text-red-900">Penjualan ini dibatalkan</p>
                <p class="mt-1 text-sm text-red-800">
                    Oleh {{ $penjualan->voidedBy?->name }} pada @tanggal($penjualan->voided_at, true).
                    Alasan: {{ $penjualan->void_reason }}
                </p>
                <p class="mt-1 text-sm text-red-800">Stok sudah dikembalikan dan pembayarannya ditandai batal. Dokumen tetap tersimpan sebagai riwayat.</p>
            </div>
        @endif

        <div class="rounded-lg border border-stone-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-stone-900">{{ $penjualan->number }}</h2>
                    <p class="mt-1 text-sm text-stone-500">@tanggal($penjualan->sold_at, true) · kasir {{ $penjualan->user?->name }}</p>
                </div>
                <div class="flex items-center gap-2 print:hidden">
                    @if ($penjualan->dibatalkan())
                        <span class="rounded-full bg-stone-200 px-2.5 py-0.5 text-xs font-medium text-stone-700">Dibatalkan</span>
                    @else
                        @include('partials.badge-pembayaran', ['status' => $penjualan->payment_status, 'penjualan' => true])
                    @endif
                    <button type="button" onclick="window.print()" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Cetak (F10)</button>
                    <a href="{{ route('sales.index') }}" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Kembali</a>
                </div>
            </div>

            <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-stone-500">Pelanggan</dt><dd class="font-medium text-stone-900">{{ $penjualan->customer?->name }}</dd></div>
                <div><dt class="text-stone-500">Metode pembayaran</dt><dd class="font-medium text-stone-900">{{ $penjualan->payment_method->label() }}</dd></div>
                @can('view-sales')
                    <div><dt class="text-stone-500">Dibuat oleh</dt><dd class="font-medium text-stone-900">{{ $penjualan->user?->name }}</dd></div>
                @endcan
                <div><dt class="text-stone-500">Catatan</dt><dd class="font-medium text-stone-900">{{ $penjualan->note ?: '-' }}</dd></div>
            </dl>
        </div>

        <div class="rounded-lg border border-stone-200 bg-white">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead class="bg-stone-50 text-left text-stone-600">
                        <tr>
                            <th class="px-4 py-3 font-medium">Produk</th>
                            <th class="px-4 py-3 text-right font-medium">Jumlah</th>
                            <th class="px-4 py-3 text-right font-medium">Harga</th>
                            <th class="px-4 py-3 text-right font-medium">Diskon</th>
                            <th class="px-4 py-3 text-right font-medium">Subtotal</th>
                            @can('view-sales')
                                <th class="px-4 py-3 text-right font-medium print:hidden">Laba</th>
                            @endcan
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($penjualan->items as $i)
                            <tr>
                                <td class="px-4 py-3">
                                    <p class="font-medium text-stone-900">{{ $i->product_name }}</p>
                                    <p class="text-xs text-stone-500">{{ $i->sku }}</p>
                                </td>
                                <td class="px-4 py-3 text-right">{{ \App\Support\Format::jumlah($i->quantity) }} <span class="text-stone-500">{{ $i->unit_name }}</span></td>
                                <td class="px-4 py-3 text-right">@rupiah($i->unit_price)</td>
                                <td class="px-4 py-3 text-right">@rupiah($i->discount)</td>
                                <td class="px-4 py-3 text-right font-medium text-stone-900">@rupiah($i->line_total)</td>
                                @can('view-sales')
                                    <td class="px-4 py-3 text-right text-teal-700 print:hidden">@rupiah($i->line_total - $i->cost_price * $i->quantity)</td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <dl class="ml-auto w-full max-w-sm space-y-2 border-t border-stone-200 p-4 text-sm">
                <div class="flex justify-between"><dt class="text-stone-600">Subtotal</dt><dd>@rupiah($penjualan->subtotal)</dd></div>
                <div class="flex justify-between"><dt class="text-stone-600">Diskon item</dt><dd class="text-red-700">- @rupiah($penjualan->item_discount)</dd></div>
                <div class="flex justify-between"><dt class="text-stone-600">Diskon transaksi</dt><dd class="text-red-700">- @rupiah($penjualan->discount)</dd></div>
                <div class="flex justify-between border-t border-stone-200 pt-2 text-base font-semibold text-stone-900"><dt>Grand total</dt><dd>@rupiah($penjualan->grand_total)</dd></div>
                @if ($penjualan->payment_method->value === 'cash')
                    <div class="flex justify-between"><dt class="text-stone-600">Bayar</dt><dd>@rupiah($penjualan->tendered_amount)</dd></div>
                    <div class="flex justify-between font-medium text-stone-900"><dt>Kembalian</dt><dd>@rupiah($penjualan->change_amount)</dd></div>
                @endif
                @if ((float) $penjualan->balance > 0 && ! $penjualan->dibatalkan())
                    <div class="flex justify-between font-medium text-red-700"><dt>Sisa piutang</dt><dd>@rupiah($penjualan->balance)</dd></div>
                @endif
            </dl>
        </div>

        @can('view-sales')
            <div class="rounded-lg border border-stone-200 bg-white print:hidden">
                <h3 class="border-b border-stone-200 px-4 py-3 text-sm font-medium text-stone-600">Riwayat pembayaran</h3>
                @if ($penjualan->payments->isEmpty())
                    <p class="px-4 py-6 text-sm text-stone-500">Belum ada pembayaran untuk penjualan ini.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-stone-200 text-sm">
                            <thead class="bg-stone-50 text-left text-stone-600">
                                <tr>
                                    <th class="px-4 py-3 font-medium">Nomor</th>
                                    <th class="px-4 py-3 font-medium">Tanggal</th>
                                    <th class="px-4 py-3 font-medium">Metode</th>
                                    <th class="px-4 py-3 text-right font-medium">Jumlah</th>
                                    <th class="px-4 py-3 font-medium">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach ($penjualan->payments as $b)
                                    <tr @class(['bg-stone-50 text-stone-400' => $b->dibatalkan()])>
                                        <td class="px-4 py-3 {{ $b->dibatalkan() ? 'line-through' : 'font-medium text-stone-900' }}">{{ $b->number }}</td>
                                        <td class="px-4 py-3">@tanggal($b->paid_at)</td>
                                        <td class="px-4 py-3">{{ $b->method->label() }}</td>
                                        <td class="px-4 py-3 text-right">@rupiah($b->amount)</td>
                                        <td class="px-4 py-3">
                                            {{ $b->note ?: '-' }} · {{ $b->user?->name }}
                                            @if ($b->dibatalkan()) <span class="ml-1 rounded-full bg-stone-200 px-2 py-0.5 text-xs text-stone-700">Dibatalkan</span> @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endcan

        @can('void-sale')
            @unless ($penjualan->dibatalkan())
                <div x-data="{ buka: @js($errors->has('alasan')) }" x-on:keydown.escape.window="buka = false" class="flex justify-end print:hidden">
                    <button type="button" @click="buka = true" class="text-sm text-red-600 hover:underline">Batalkan penjualan ini</button>

                    <div x-show="buka" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/60 p-4" role="dialog" aria-modal="true" aria-labelledby="judul-batal">
                        <div @click.outside="buka = false" class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                            <h2 id="judul-batal" class="text-lg font-semibold text-stone-900">Batalkan {{ $penjualan->number }}?</h2>
                            <p class="mt-2 text-sm text-stone-600">
                                Stok seluruh barang dikurangi kembali, pembayarannya ditandai batal, dan piutang pelanggan (bila ada) dikoreksi.
                                Dokumen tidak dihapus. Bila sudah ada pelunasan piutang susulan, pembatalan ditolak; gunakan Retur Penjualan.
                            </p>
                            <form method="POST" action="{{ route('sales.void', $penjualan) }}" class="mt-4 space-y-4">
                                @csrf
                                <div>
                                    <label for="alasan" class="block text-sm font-medium text-stone-700">Alasan pembatalan</label>
                                    <textarea id="alasan" name="alasan" rows="3" required minlength="5" maxlength="255"
                                              class="mt-1 block w-full rounded-md border-stone-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">{{ old('alasan') }}</textarea>
                                    @error('alasan') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="flex justify-end gap-2">
                                    <button type="button" @click="buka = false" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Kembali</button>
                                    <button type="submit" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500">Ya, batalkan</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endunless
        @endcan
    </div>
</x-app-layout>
