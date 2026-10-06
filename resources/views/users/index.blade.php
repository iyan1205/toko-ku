<x-app-layout>
    <x-slot name="header">Pengguna</x-slot>

    <div class="rounded-lg border border-stone-200 bg-white">
        <div class="flex flex-col gap-3 border-b border-stone-200 p-4 sm:flex-row sm:items-center sm:justify-between">
            <form method="GET" action="{{ route('users.index') }}" class="flex flex-1 flex-col gap-2 sm:flex-row">
                <input type="search" name="cari" value="{{ request('cari') }}" placeholder="Cari nama, email, atau nomor HP"
                       class="w-full rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600 sm:max-w-xs">
                <select name="peran" class="rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600" aria-label="Filter peran">
                    <option value="">Semua peran</option>
                    @foreach ($peran as $p)
                        <option value="{{ $p->value }}" @selected(request('peran') === $p->value)>{{ $p->value }}</option>
                    @endforeach
                </select>
                <select name="status" class="rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600" aria-label="Filter status">
                    <option value="">Semua status</option>
                    <option value="aktif" @selected(request('status') === 'aktif')>Aktif</option>
                    <option value="nonaktif" @selected(request('status') === 'nonaktif')>Nonaktif</option>
                </select>
                <button type="submit" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Terapkan</button>
            </form>

            <a href="{{ route('users.create') }}"
               class="rounded-md bg-teal-700 px-4 py-2 text-center text-sm font-medium text-white hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">
                Tambah pengguna
            </a>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-stone-50 text-left text-stone-600">
                    <tr>
                        <th class="px-4 py-3 font-medium">Nama</th>
                        <th class="px-4 py-3 font-medium">Nomor HP</th>
                        <th class="px-4 py-3 font-medium">Peran</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Login terakhir</th>
                        <th class="px-4 py-3 text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($users as $u)
                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-medium text-stone-900">{{ $u->name }}</p>
                                <p class="text-stone-500">{{ $u->email }}</p>
                            </td>
                            <td class="px-4 py-3 text-stone-600">{{ $u->phone ?: '-' }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-medium text-stone-700">{{ $u->namaPeran() }}</span>
                            </td>
                            <td class="px-4 py-3">
                                @if ($u->is_active)
                                    <span class="rounded-full bg-teal-50 px-2.5 py-0.5 text-xs font-medium text-teal-800">Aktif</span>
                                @else
                                    <span class="rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-800">Nonaktif</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-stone-600">{{ \App\Support\Format::tanggal($u->last_login_at, true) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-3">
                                    @can('update', $u)
                                        <a href="{{ route('users.edit', $u) }}" class="text-teal-700 hover:underline">Ubah</a>
                                    @endcan

                                    @if ($u->isNot(auth()->user()))
                                        @can('update', $u)
                                            <form method="POST" action="{{ route('users.toggle', $u) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="text-stone-600 hover:underline">
                                                    {{ $u->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                                </button>
                                            </form>
                                        @endcan

                                        @can('delete', $u)
                                            <button type="button" class="text-red-600 hover:underline"
                                                    @click="$dispatch('konfirmasi-hapus', { action: '{{ route('users.destroy', $u) }}', nama: @js($u->name) })">
                                                Hapus
                                            </button>
                                        @endcan
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-stone-500">
                                Tidak ada pengguna yang cocok dengan pencarian ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->hasPages())
            <div class="border-t border-stone-200 p-4">{{ $users->links() }}</div>
        @endif
    </div>
</x-app-layout>
