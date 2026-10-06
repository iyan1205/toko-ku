<x-app-layout>
    <x-slot name="header">Pelanggan</x-slot>

    <div class="rounded-lg border border-stone-200 bg-white">
        <div class="flex flex-col gap-3 border-b border-stone-200 p-4 sm:flex-row sm:items-center sm:justify-between">
            <form method="GET" action="{{ route('customers.index') }}" class="flex flex-1 gap-2">
                <input type="search" name="cari" value="{{ request('cari') }}" placeholder="Cari nama, kode, atau nomor HP"
                       class="w-full rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600 sm:max-w-sm">
                <button type="submit" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Cari</button>
            </form>
            <a href="{{ route('customers.create') }}"
               class="rounded-md bg-teal-700 px-4 py-2 text-center text-sm font-medium text-white hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">Tambah pelanggan</a>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-stone-50 text-left text-stone-600">
                    <tr>
                        <th class="px-4 py-3 font-medium">Kode</th>
                        <th class="px-4 py-3 font-medium">Pelanggan</th>
                        <th class="px-4 py-3 font-medium">Nomor HP</th>
                        <th class="px-4 py-3 text-right font-medium">Saldo piutang</th>
                        <th class="px-4 py-3 text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($customers as $c)
                        <tr>
                            <td class="px-4 py-3 text-stone-500">{{ $c->code }}</td>
                            <td class="px-4 py-3">
                                <a href="{{ route('customers.show', $c) }}" class="font-medium text-stone-900 hover:underline">{{ $c->name }}</a>
                                @if ($c->is_default)
                                    <span class="ml-2 rounded-full bg-stone-100 px-2 py-0.5 text-xs text-stone-600">Bawaan sistem</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-stone-600">{{ $c->phone ?: '-' }}</td>
                            <td class="px-4 py-3 text-right {{ (float) $c->receivable_balance > 0 ? 'font-medium text-red-700' : 'text-stone-600' }}">@rupiah($c->receivable_balance)</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('customers.show', $c) }}" class="text-stone-600 hover:underline">Detail</a>
                                    @unless ($c->is_default)
                                        <a href="{{ route('customers.edit', $c) }}" class="text-teal-700 hover:underline">Ubah</a>
                                        <button type="button" class="text-red-600 hover:underline"
                                                @click="$dispatch('konfirmasi-hapus', { action: @js(route('customers.destroy', $c)), nama: @js($c->name) })">Hapus</button>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-stone-500">Belum ada pelanggan yang cocok. Klik "Tambah pelanggan" untuk menambahkan.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($customers->hasPages())
            <div class="border-t border-stone-200 p-4">{{ $customers->links() }}</div>
        @endif
    </div>
</x-app-layout>
