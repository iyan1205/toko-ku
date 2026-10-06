<x-app-layout>
    <x-slot name="header">Detail pembelian</x-slot>

    <div class="max-w-5xl space-y-6">
        @if ($pembelian->dibatalkan())
            <div class="rounded-lg border border-red-200 bg-red-50 p-5" role="status">
                <p class="font-semibold text-red-900">Pembelian ini dibatalkan</p>
                <p class="mt-1 text-sm text-red-800">
                    Oleh {{ $pembelian->voidedBy?->name }} pada @tanggal($pembelian->voided_at, true).
                    Alasan: {{ $pembelian->void_reason }}
                </p>
                <p class="mt-1 text-sm text-red-800">Stok sudah dikembalikan dan pembayarannya ditandai batal. Dokumen tetap tersimpan sebagai riwayat.</p>
            </div>
        @endif

        <div class="rounded-lg border border-stone-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-stone-900">{{ $pembelian->number }}</h2>
                    <p class="mt-1 text-sm text-stone-500">Tanggal @tanggal($pembelian->purchase_date) · dicatat oleh {{ $pembelian->user?->name }}</p>
                </div>
                <div class="flex items-center gap-2">
                    @if ($pembelian->dibatalkan())
                        <span class="rounded-full bg-stone-200 px-2.5 py-0.5 text-xs font-medium text-stone-700">Dibatalkan</span>
                    @else
                        @include('partials.badge-pembayaran', ['status' => $pembelian->payment_status])
                    @endif
                    <a href="{{ route('purchases.index') }}" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Kembali</a>
                </div>
            </div>

            <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-stone-500">Supplier</dt>
                    <dd class="font-medium text-stone-900">
                        @if ($pembelian->supplier && ! $pembelian->supplier->trashed())
                            <a href="{{ route('suppliers.show', $pembelian->supplier) }}" class="hover:underline">{{ $pembelian->supplier->name }}</a>
                        @else
                            {{ $pembelian->supplier?->name }}
                        @endif
                    </dd>
                </div>
                <div><dt class="text-stone-500">Nomor faktur supplier</dt><dd class="font-medium text-stone-900">{{ $pembelian->supplier_invoice_number ?: '-' }}</dd></div>
                <div><dt class="text-stone-500">Jatuh tempo</dt><dd class="font-medium text-stone-900">@tanggal($pembelian->due_date)</dd></div>
                <div><dt class="text-stone-500">Catatan</dt><dd class="font-medium text-stone-900">{{ $pembelian->note ?: '-' }}</dd></div>
            </dl>
        </div>

        <div class="rounded-lg border border-stone-200 bg-white">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead class="bg-stone-50 text-left text-stone-600">
                        <tr>
                            <th class="px-4 py-3 font-medium">Produk</th>
                            <th class="px-4 py-3 text-right font-medium">Jumlah</th>
                            <th class="px-4 py-3 text-right font-medium">Harga beli</th>
                            <th class="px-4 py-3 text-right font-medium">Diskon</th>
                            <th class="px-4 py-3 text-right font-medium">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($pembelian->items as $i)
                            <tr>
                                <td class="px-4 py-3">
                                    <p class="font-medium text-stone-900">{{ $i->product_name }}</p>
                                    <p class="text-xs text-stone-500">{{ $i->sku }}</p>
                                </td>
                                <td class="px-4 py-3 text-right">{{ \App\Support\Format::jumlah($i->quantity) }} <span class="text-stone-500">{{ $i->unit_name }}</span></td>
                                <td class="px-4 py-3 text-right">@rupiah($i->unit_price)</td>
                                <td class="px-4 py-3 text-right">@rupiah($i->discount)</td>
                                <td class="px-4 py-3 text-right font-medium text-stone-900">@rupiah($i->line_total)</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <dl class="ml-auto w-full max-w-sm space-y-2 border-t border-stone-200 p-4 text-sm">
                <div class="flex justify-between"><dt class="text-stone-600">Subtotal</dt><dd>@rupiah($pembelian->subtotal)</dd></div>
                <div class="flex justify-between"><dt class="text-stone-600">Diskon</dt><dd class="text-red-700">- @rupiah($pembelian->discount)</dd></div>
                <div class="flex justify-between"><dt class="text-stone-600">Pajak</dt><dd>@rupiah($pembelian->tax)</dd></div>
                <div class="flex justify-between"><dt class="text-stone-600">Biaya lain</dt><dd>@rupiah($pembelian->other_cost)</dd></div>
                <div class="flex justify-between border-t border-stone-200 pt-2 text-base font-semibold text-stone-900"><dt>Grand total</dt><dd>@rupiah($pembelian->grand_total)</dd></div>
                <div class="flex justify-between"><dt class="text-stone-600">Dibayar</dt><dd>@rupiah($pembelian->paid_amount)</dd></div>
                <div class="flex justify-between font-medium {{ ! $pembelian->dibatalkan() && (float) $pembelian->balance > 0 ? 'text-red-700' : 'text-stone-900' }}"><dt>Sisa hutang</dt><dd>@rupiah($pembelian->balance)</dd></div>
            </dl>
        </div>

        <div class="rounded-lg border border-stone-200 bg-white">
            <h3 class="border-b border-stone-200 px-4 py-3 text-sm font-medium text-stone-600">Riwayat pembayaran</h3>
            @if ($pembelian->payments->isEmpty())
                <p class="px-4 py-6 text-sm text-stone-500">Belum ada pembayaran untuk pembelian ini.</p>
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
                            @foreach ($pembelian->payments as $b)
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

        @can('void-purchase')
            @unless ($pembelian->dibatalkan())
                <div x-data="{ buka: @js($errors->has('alasan')) }" x-on:keydown.escape.window="buka = false" class="flex justify-end">
                    <button type="button" @click="buka = true" class="text-sm text-red-600 hover:underline">Batalkan pembelian ini</button>

                    <div x-show="buka" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/60 p-4" role="dialog" aria-modal="true" aria-labelledby="judul-batal">
                        <div @click.outside="buka = false" class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                            <h2 id="judul-batal" class="text-lg font-semibold text-stone-900">Batalkan {{ $pembelian->number }}?</h2>
                            <p class="mt-2 text-sm text-stone-600">
                                Stok seluruh barang dikurangi kembali dan pembayarannya ditandai batal. Dokumen tidak dihapus.
                                Bila sebagian barang sudah terjual, pembatalan ditolak; gunakan Retur Pembelian.
                            </p>
                            <form method="POST" action="{{ route('purchases.void', $pembelian) }}" class="mt-4 space-y-4">
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
