<x-app-layout>
    <x-slot name="header">Pembelian</x-slot>

    <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg border border-stone-200 bg-white p-4">
            <p class="text-sm text-stone-500">Total pembelian (sesuai filter, tanpa yang dibatalkan)</p>
            <p class="mt-1 text-2xl font-semibold text-stone-900">@rupiah($ringkasan['total'])</p>
        </div>
        <div class="rounded-lg border border-stone-200 bg-white p-4">
            <p class="text-sm text-stone-500">Sisa hutang (sesuai filter)</p>
            <p class="mt-1 text-2xl font-semibold {{ $ringkasan['hutang'] > 0 ? 'text-red-700' : 'text-stone-900' }}">@rupiah($ringkasan['hutang'])</p>
        </div>
    </div>

    <div class="rounded-lg border border-stone-200 bg-white">
        <div class="space-y-3 border-b border-stone-200 p-4">
            <form method="GET" action="{{ route('purchases.index') }}" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-6">
                <input type="search" name="cari" value="{{ request('cari') }}" placeholder="Cari nomor, faktur, supplier"
                       class="rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600 lg:col-span-2">
                <select name="supplier" aria-label="Filter supplier" class="rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                    <option value="">Semua supplier</option>
                    @foreach ($suppliers as $s)
                        <option value="{{ $s->id }}" @selected((string) request('supplier') === (string) $s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
                <select name="pembayaran" aria-label="Filter pembayaran" class="rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                    <option value="">Semua pembayaran</option>
                    <option value="paid" @selected(request('pembayaran') === 'paid')>Lunas</option>
                    <option value="partial" @selected(request('pembayaran') === 'partial')>Sebagian</option>
                    <option value="unpaid" @selected(request('pembayaran') === 'unpaid')>Hutang</option>
                </select>
                <select name="status" aria-label="Filter status" class="rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                    <option value="">Semua status</option>
                    <option value="completed" @selected(request('status') === 'completed')>Selesai</option>
                    <option value="voided" @selected(request('status') === 'voided')>Dibatalkan</option>
                </select>
                <div class="flex gap-2">
                    <input type="date" name="dari" value="{{ request('dari') }}" aria-label="Dari tanggal" class="w-full rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                    <input type="date" name="sampai" value="{{ request('sampai') }}" aria-label="Sampai tanggal" class="w-full rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                </div>
                <button type="submit" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50 lg:col-span-6 lg:w-40">Terapkan filter</button>
            </form>
            @error('sampai') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            @can('create-purchase')
                <a href="{{ route('purchases.create') }}"
                   class="inline-block rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">Pembelian baru</a>
            @endcan
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-stone-50 text-left text-stone-600">
                    <tr>
                        <th class="px-4 py-3 font-medium">Nomor</th>
                        <th class="px-4 py-3 font-medium">Tanggal</th>
                        <th class="px-4 py-3 font-medium">Supplier</th>
                        <th class="px-4 py-3 text-right font-medium">Total</th>
                        <th class="px-4 py-3 text-right font-medium">Dibayar</th>
                        <th class="px-4 py-3 text-right font-medium">Sisa hutang</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($pembelian as $p)
                        <tr @class(['bg-stone-50 text-stone-400' => $p->dibatalkan()])>
                            <td class="px-4 py-3 font-medium {{ $p->dibatalkan() ? 'line-through' : 'text-stone-900' }}">{{ $p->number }}</td>
                            <td class="whitespace-nowrap px-4 py-3">@tanggal($p->purchase_date)</td>
                            <td class="px-4 py-3">
                                {{ $p->supplier?->name }}
                                @if ($p->supplier_invoice_number) <p class="text-xs text-stone-500">Faktur {{ $p->supplier_invoice_number }}</p> @endif
                            </td>
                            <td class="px-4 py-3 text-right">@rupiah($p->grand_total)</td>
                            <td class="px-4 py-3 text-right">@rupiah($p->paid_amount)</td>
                            <td class="px-4 py-3 text-right {{ ! $p->dibatalkan() && (float) $p->balance > 0 ? 'font-medium text-red-700' : '' }}">@rupiah($p->balance)</td>
                            <td class="px-4 py-3">
                                @if ($p->dibatalkan())
                                    <span class="rounded-full bg-stone-200 px-2.5 py-0.5 text-xs font-medium text-stone-700">Dibatalkan</span>
                                @else
                                    @include('partials.badge-pembayaran', ['status' => $p->payment_status])
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right"><a href="{{ route('purchases.show', $p) }}" class="text-teal-700 hover:underline">Detail</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-10 text-center text-stone-500">Belum ada pembelian yang cocok dengan filter ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($pembelian->hasPages())
            <div class="border-t border-stone-200 p-4">{{ $pembelian->links() }}</div>
        @endif
    </div>
</x-app-layout>
