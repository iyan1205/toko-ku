<x-app-layout>
    <x-slot name="header">Penjualan</x-slot>

    <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg border border-stone-200 bg-white p-4">
            <p class="text-sm text-stone-500">Omzet (sesuai filter, tanpa yang dibatalkan)</p>
            <p class="mt-1 text-2xl font-semibold text-stone-900">@rupiah($ringkasan['omzet'])</p>
        </div>
        <div class="rounded-lg border border-stone-200 bg-white p-4">
            <p class="text-sm text-stone-500">Piutang (sesuai filter)</p>
            <p class="mt-1 text-2xl font-semibold {{ $ringkasan['piutang'] > 0 ? 'text-red-700' : 'text-stone-900' }}">@rupiah($ringkasan['piutang'])</p>
        </div>
    </div>

    <div class="rounded-lg border border-stone-200 bg-white">
        <div class="space-y-3 border-b border-stone-200 p-4">
            <form method="GET" action="{{ route('sales.index') }}" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-6">
                <input type="search" name="cari" value="{{ request('cari') }}" placeholder="Cari nomor atau nama pelanggan"
                       class="rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600 lg:col-span-2">
                <select name="pelanggan" aria-label="Filter pelanggan" class="rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                    <option value="">Semua pelanggan</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}" @selected((string) request('pelanggan') === (string) $c->id)>{{ $c->name }}</option>
                    @endforeach
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

            @can('create-sale')
                <a href="{{ route('pos.index') }}"
                   class="inline-block rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">Buka Kasir</a>
            @endcan
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-stone-50 text-left text-stone-600">
                    <tr>
                        <th class="px-4 py-3 font-medium">Nomor</th>
                        <th class="px-4 py-3 font-medium">Waktu</th>
                        @unless ($hanyaMilikSendiri)
                            <th class="px-4 py-3 font-medium">Kasir</th>
                        @endunless
                        <th class="px-4 py-3 font-medium">Pelanggan</th>
                        <th class="px-4 py-3 font-medium">Metode</th>
                        <th class="px-4 py-3 text-right font-medium">Total</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($penjualan as $p)
                        <tr @class(['bg-stone-50 text-stone-400' => $p->dibatalkan()])>
                            <td class="px-4 py-3 font-medium {{ $p->dibatalkan() ? 'line-through' : 'text-stone-900' }}">{{ $p->number }}</td>
                            <td class="whitespace-nowrap px-4 py-3">@tanggal($p->sold_at, true)</td>
                            @unless ($hanyaMilikSendiri)
                                <td class="px-4 py-3 text-stone-600">{{ $p->user?->name }}</td>
                            @endunless
                            <td class="px-4 py-3">{{ $p->customer?->name }}</td>
                            <td class="px-4 py-3 text-stone-600">{{ $p->payment_method->label() }}</td>
                            <td class="px-4 py-3 text-right">@rupiah($p->grand_total)</td>
                            <td class="px-4 py-3">
                                @if ($p->dibatalkan())
                                    <span class="rounded-full bg-stone-200 px-2.5 py-0.5 text-xs font-medium text-stone-700">Dibatalkan</span>
                                @else
                                    @include('partials.badge-pembayaran', ['status' => $p->payment_status, 'penjualan' => true])
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right"><a href="{{ route('sales.show', $p) }}" class="text-teal-700 hover:underline">Detail</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $hanyaMilikSendiri ? 7 : 8 }}" class="px-4 py-10 text-center text-stone-500">Belum ada penjualan yang cocok dengan filter ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($penjualan->hasPages())
            <div class="border-t border-stone-200 p-4">{{ $penjualan->links() }}</div>
        @endif
    </div>
</x-app-layout>
