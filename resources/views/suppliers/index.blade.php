<x-app-layout>
    <x-slot name="header">Supplier</x-slot>

    <div class="kartu">
        <div class="flex flex-col gap-3 border-b border-stone-200 p-4 sm:flex-row sm:items-center sm:justify-between">
            <form method="GET" action="{{ route('suppliers.index') }}" class="flex flex-1 gap-2">
                <input type="search" name="cari" value="{{ request('cari') }}" placeholder="Cari nama, kode, kontak, atau telepon"
                       class="w-full rounded-md border-stone-300 text-sm focus:border-brand-600 focus:ring-brand-600 sm:max-w-sm">
                <button type="submit" class="tombol-kedua">Cari</button>
            </form>
            <a href="{{ route('suppliers.create') }}"
               class="rounded-md bg-brand-700 px-4 py-2 text-center text-sm font-medium text-white hover:bg-brand-800 focus:outline-none focus:ring-2 focus:ring-brand-600 focus:ring-offset-2">Tambah supplier</a>
        </div>

        <div class="overflow-x-auto">
            <table class="tabel">
                <thead>
                    <tr>
                        <th class="font-medium">Kode</th>
                        <th class="font-medium">Supplier</th>
                        <th class="font-medium">Kontak</th>
                        <th class="font-medium">Telepon</th>
                        <th class="text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($suppliers as $s)
                        <tr>
                            <td class="text-stone-500">{{ $s->code }}</td>
                            <td >
                                <a href="{{ route('suppliers.show', $s) }}" class="font-medium text-stone-900 hover:underline">{{ $s->name }}</a>
                                @if ($s->email) <p class="text-xs text-stone-500">{{ $s->email }}</p> @endif
                            </td>
                            <td class="text-stone-600">{{ $s->contact_name ?: '-' }}</td>
                            <td class="text-stone-600">{{ $s->phone ?: '-' }}</td>
                            <td >
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('suppliers.show', $s) }}" class="text-stone-600 hover:underline">Detail</a>
                                    <a href="{{ route('suppliers.edit', $s) }}" class="text-brand-700 hover:underline">Ubah</a>
                                    <button type="button" class="text-brand-600 hover:underline"
                                            @click="$dispatch('konfirmasi-hapus', { action: @js(route('suppliers.destroy', $s)), nama: @js($s->name) })">Hapus</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-stone-500">Belum ada supplier yang cocok. Klik "Tambah supplier" untuk menambahkan.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($suppliers->hasPages())
            <div class="border-t border-stone-200 p-4">{{ $suppliers->links() }}</div>
        @endif
    </div>
</x-app-layout>
