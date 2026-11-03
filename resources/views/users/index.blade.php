<x-app-layout>
    <x-slot name="header">Pengguna</x-slot>

    <div class="kartu">
        <div class="flex flex-col gap-3 border-b border-stone-200 p-4 sm:flex-row sm:items-center sm:justify-between">
            <form method="GET" action="{{ route('users.index') }}" class="flex flex-1 flex-col gap-2 sm:flex-row">
                <input type="search" name="cari" value="{{ request('cari') }}" placeholder="Cari nama, email, atau nomor HP"
                       class="w-full rounded-md border-stone-300 text-sm focus:border-brand-600 focus:ring-brand-600 sm:max-w-xs">
                <select name="peran" class="rounded-md border-stone-300 text-sm focus:border-brand-600 focus:ring-brand-600" aria-label="Filter peran">
                    <option value="">Semua peran</option>
                    @foreach ($peran as $p)
                        <option value="{{ $p->value }}" @selected(request('peran') === $p->value)>{{ $p->value }}</option>
                    @endforeach
                </select>
                <select name="status" class="rounded-md border-stone-300 text-sm focus:border-brand-600 focus:ring-brand-600" aria-label="Filter status">
                    <option value="">Semua status</option>
                    <option value="aktif" @selected(request('status') === 'aktif')>Aktif</option>
                    <option value="nonaktif" @selected(request('status') === 'nonaktif')>Nonaktif</option>
                </select>
                <button type="submit" class="tombol-kedua">Terapkan</button>
            </form>

            <a href="{{ route('users.create') }}"
               class="rounded-md bg-brand-700 px-4 py-2 text-center text-sm font-medium text-white hover:bg-brand-800 focus:outline-none focus:ring-2 focus:ring-brand-600 focus:ring-offset-2">
                Tambah pengguna
            </a>
        </div>

        <div class="overflow-x-auto">
            <table class="tabel">
                <thead>
                    <tr>
                        <th class="font-medium">Nama</th>
                        <th class="font-medium">Nomor HP</th>
                        <th class="font-medium">Peran</th>
                        <th class="font-medium">Status</th>
                        <th class="font-medium">Login terakhir</th>
                        <th class="text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $u)
                        <tr>
                            <td >
                                <p class="font-medium text-stone-900">{{ $u->name }}</p>
                                <p class="text-stone-500">{{ $u->email }}</p>
                            </td>
                            <td class="text-stone-600">{{ $u->phone ?: '-' }}</td>
                            <td >
                                <span class="rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-medium text-stone-700">{{ $u->namaPeran() }}</span>
                            </td>
                            <td >
                                @if ($u->is_active)
                                    <span class="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-medium text-brand-800">Aktif</span>
                                @else
                                    <span class="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-medium text-brand-800">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-stone-600">{{ \App\Support\Format::tanggal($u->last_login_at, true) }}</td>
                            <td >
                                <div class="flex items-center justify-end gap-3">
                                    @can('update', $u)
                                        <a href="{{ route('users.edit', $u) }}" class="text-brand-700 hover:underline">Ubah</a>
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
                                            <button type="button" class="text-brand-600 hover:underline"
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
