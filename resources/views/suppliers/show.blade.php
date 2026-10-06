<x-app-layout>
    <x-slot name="header">Detail supplier</x-slot>

    <div class="max-w-3xl space-y-6">
        <div class="rounded-lg border border-stone-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-stone-900">{{ $supplier->name }}</h2>
                    <p class="mt-1 text-sm text-stone-500">{{ $supplier->code }}</p>
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('suppliers.edit', $supplier) }}" class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800">Ubah</a>
                    <a href="{{ route('suppliers.index') }}" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Kembali</a>
                </div>
            </div>

            <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                <div><dt class="text-stone-500">Nama kontak</dt><dd class="font-medium text-stone-900">{{ $supplier->contact_name ?: '-' }}</dd></div>
                <div><dt class="text-stone-500">Nomor telepon</dt><dd class="font-medium text-stone-900">{{ $supplier->phone ?: '-' }}</dd></div>
                <div><dt class="text-stone-500">Email</dt><dd class="font-medium text-stone-900">{{ $supplier->email ?: '-' }}</dd></div>
                <div><dt class="text-stone-500">NPWP</dt><dd class="font-medium text-stone-900">{{ $supplier->npwp ?: '-' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-stone-500">Alamat</dt><dd class="whitespace-pre-line font-medium text-stone-900">{{ $supplier->address ?: '-' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-stone-500">Catatan</dt><dd class="whitespace-pre-line font-medium text-stone-900">{{ $supplier->note ?: '-' }}</dd></div>
            </dl>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <p class="text-sm text-stone-500">Jumlah pembelian</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">{{ $ringkasan['jumlah'] }}</p>
            </div>
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <p class="text-sm text-stone-500">Total pembelian</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">@rupiah($ringkasan['total'])</p>
            </div>
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <p class="text-sm text-stone-500">Hutang supplier</p>
                <p class="mt-1 text-2xl font-semibold {{ $ringkasan['hutang'] > 0 ? 'text-red-700' : 'text-stone-900' }}">@rupiah($ringkasan['hutang'])</p>
            </div>
        </div>

        <div class="rounded-lg border border-stone-200 bg-white">
            <div class="flex items-center justify-between border-b border-stone-200 px-4 py-3">
                <h3 class="text-sm font-medium text-stone-600">Riwayat pembelian</h3>
                <div class="flex gap-3 text-sm">
                    @can('create-purchase')
                        <a href="{{ route('purchases.create', ['supplier' => $supplier->id]) }}" class="text-teal-700 hover:underline">Pembelian baru</a>
                    @endcan
                    @can('view-purchases')
                        <a href="{{ route('purchases.index', ['supplier' => $supplier->id]) }}" class="text-stone-600 hover:underline">Semua</a>
                    @endcan
                </div>
            </div>
            @forelse ($pembelian as $p)
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-stone-100 px-4 py-3 text-sm last:border-0">
                    <div>
                        @can('view-purchases')
                            <a href="{{ route('purchases.show', $p) }}" class="font-medium {{ $p->dibatalkan() ? 'text-stone-400 line-through' : 'text-stone-900 hover:underline' }}">{{ $p->number }}</a>
                        @else
                            <span class="font-medium text-stone-900">{{ $p->number }}</span>
                        @endcan
                        <span class="ml-2 text-stone-500">@tanggal($p->purchase_date)</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span>@rupiah($p->grand_total)</span>
                        @if ($p->dibatalkan())
                            <span class="rounded-full bg-stone-200 px-2.5 py-0.5 text-xs font-medium text-stone-700">Dibatalkan</span>
                        @else
                            @include('partials.badge-pembayaran', ['status' => $p->payment_status])
                        @endif
                    </div>
                </div>
            @empty
                <p class="px-4 py-6 text-sm text-stone-500">Belum ada pembelian dari supplier ini.</p>
            @endforelse
        </div>

        <div class="flex justify-end">
            <button type="button" class="text-sm text-red-600 hover:underline"
                    @click="$dispatch('konfirmasi-hapus', { action: @js(route('suppliers.destroy', $supplier)), nama: @js($supplier->name) })">
                Hapus supplier ini
            </button>
        </div>
    </div>
</x-app-layout>
