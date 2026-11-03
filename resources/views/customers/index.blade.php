<x-app-layout>
    <x-slot name="header">Pelanggan</x-slot>

    <div class="kartu">
        <div class="flex flex-col gap-3 border-b border-stone-200 p-4 sm:flex-row sm:items-center sm:justify-between">
            <form method="GET" action="{{ route('customers.index') }}" class="flex flex-1 gap-2">
                <input type="search" name="cari" value="{{ request('cari') }}" placeholder="Cari nama, kode, atau nomor HP"
                       class="w-full rounded-md border-stone-300 text-sm focus:border-brand-600 focus:ring-brand-600 sm:max-w-sm">
                <button type="submit" class="tombol-kedua">Cari</button>
            </form>
            <a href="{{ route('customers.create') }}"
               class="rounded-md bg-brand-700 px-4 py-2 text-center text-sm font-medium text-white hover:bg-brand-800 focus:outline-none focus:ring-2 focus:ring-brand-600 focus:ring-offset-2">Tambah pelanggan</a>
        </div>

        <div class="overflow-x-auto">
            <table class="tabel">
                <thead>
                    <tr>
                        <th class="font-medium">Kode</th>
                        <th class="font-medium">Pelanggan</th>
                        <th class="font-medium">Nomor HP</th>
                        <th class="text-right font-medium">Saldo piutang</th>
                        <th class="text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $c)
                        <tr>
                            <td class="text-stone-500">{{ $c->code }}</td>
                            <td >
                                <a href="{{ route('customers.show', $c) }}" class="font-medium text-stone-900 hover:underline">{{ $c->name }}</a>
                                @if ($c->is_default)
                                    <span class="ml-2 rounded-full bg-stone-100 px-2 py-0.5 text-xs text-stone-600">Bawaan sistem</span>
                                @endif
                            </td>
                            <td class="text-stone-600">{{ $c->phone ?: '-' }}</td>
                            <td class="px-4 py-3 text-right {{ (float) $c->receivable_balance > 0 ? 'font-medium text-brand-700' : 'text-stone-600' }}">@rupiah($c->receivable_balance)</td>
                            <td >
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('customers.show', $c) }}" class="text-stone-600 hover:underline">Detail</a>
                                    @unless ($c->is_default)
                                        <a href="{{ route('customers.edit', $c) }}" class="text-brand-700 hover:underline">Ubah</a>
                                        <button type="button" class="text-brand-600 hover:underline"
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
