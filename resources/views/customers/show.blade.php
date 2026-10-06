<x-app-layout>
    <x-slot name="header">Detail pelanggan</x-slot>

    <div class="max-w-3xl space-y-6">
        <div class="rounded-lg border border-stone-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-stone-900">{{ $customer->name }}</h2>
                    <p class="mt-1 text-sm text-stone-500">{{ $customer->code }}@if ($customer->is_default) · Bawaan sistem untuk transaksi tanpa pelanggan @endif</p>
                </div>
                <div class="flex gap-2">
                    @unless ($customer->is_default)
                        <a href="{{ route('customers.edit', $customer) }}" class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800">Ubah</a>
                    @endunless
                    <a href="{{ route('customers.index') }}" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Kembali</a>
                </div>
            </div>

            <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                <div><dt class="text-stone-500">Nomor HP</dt><dd class="font-medium text-stone-900">{{ $customer->phone ?: '-' }}</dd></div>
                <div><dt class="text-stone-500">Email</dt><dd class="font-medium text-stone-900">{{ $customer->email ?: '-' }}</dd></div>
                <div>
                    <dt class="text-stone-500">Saldo piutang</dt>
                    <dd class="font-medium {{ (float) $customer->receivable_balance > 0 ? 'text-red-700' : 'text-stone-900' }}">@rupiah($customer->receivable_balance)</dd>
                </div>
                <div class="sm:col-span-2"><dt class="text-stone-500">Alamat</dt><dd class="whitespace-pre-line font-medium text-stone-900">{{ $customer->address ?: '-' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-stone-500">Catatan</dt><dd class="whitespace-pre-line font-medium text-stone-900">{{ $customer->note ?: '-' }}</dd></div>
            </dl>
        </div>

        <div class="rounded-lg border border-dashed border-stone-300 p-6 text-sm text-stone-500">
            Riwayat pembelian dan rincian piutang pelanggan ini akan tampil di sini setelah modul Penjualan dan Piutang selesai dibangun.
        </div>

        @unless ($customer->is_default)
            <div class="flex justify-end">
                <button type="button" class="text-sm text-red-600 hover:underline"
                        @click="$dispatch('konfirmasi-hapus', { action: @js(route('customers.destroy', $customer)), nama: @js($customer->name) })">
                    Hapus pelanggan ini
                </button>
            </div>
        @endunless
    </div>
</x-app-layout>
