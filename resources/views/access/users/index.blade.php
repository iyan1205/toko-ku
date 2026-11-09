{{-- Daftar pengguna: cari/filter, ubah, aktif/nonaktif, reset kata sandi, hapus. --}}
<x-app-layout>
    <x-slot name="header">Pengguna</x-slot>
    @can('create', \App\Models\User::class)
        <x-slot name="actions">
            <x-ui.button :href="route('users.create')" icon="plus">Tambah pengguna</x-ui.button>
        </x-slot>
    @endcan

    {{-- target = pengguna yang kata sandinya akan direset (diisi tombol di tiap baris). --}}
    <div x-data="{ target: { url: @js(old('_reset_url', '')), name: @js(old('_reset_name', '')) } }">
        <x-ui.table :paginator="$users">
            <x-slot:toolbar>
                <x-ui.filter-bar placeholder="Cari nama, email, atau nomor HP">
                    <x-ui.select name="peran" label="Peran" hide-label :options="$peran->pluck('name', 'name')" :selected="request('peran')" placeholder="Semua peran" />
                    <x-ui.select name="status" label="Status" hide-label :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" :selected="request('status')" placeholder="Semua status" />
                </x-ui.filter-bar>
            </x-slot:toolbar>

            <x-slot:head>
                <x-ui.table.th>Nama</x-ui.table.th>
                <x-ui.table.th>Nomor HP</x-ui.table.th>
                <x-ui.table.th>Peran</x-ui.table.th>
                <x-ui.table.th>Status</x-ui.table.th>
                <x-ui.table.th>Login terakhir</x-ui.table.th>
                <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
            </x-slot:head>

            @forelse ($users as $u)
                <tr>
                    <x-ui.table.td>
                        <p class="font-medium text-slate-900">
                            {{ $u->name }}
                            @if ($u->is(auth()->user()))
                                <span class="ml-1 text-xs font-normal text-slate-500">(Anda)</span>
                            @endif
                        </p>
                        <p class="text-xs text-slate-500">{{ $u->email }}</p>
                    </x-ui.table.td>
                    <x-ui.table.td muted>{{ $u->phone ?: '-' }}</x-ui.table.td>
                    <x-ui.table.td><x-ui.badge :color="$u->isSuperAdmin() ? 'primary' : 'neutral'">{{ $u->namaPeran() }}</x-ui.badge></x-ui.table.td>
                    <x-ui.table.td>
                        <x-ui.badge :color="$u->is_active ? 'success' : 'neutral'" dot>{{ $u->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge>
                    </x-ui.table.td>
                    <x-ui.table.td muted>{{ \App\Support\Format::tanggal($u->last_login_at, true) }}</x-ui.table.td>
                    <x-ui.table.td align="right">
                        @can('update', $u)
                            <x-ui.dropdown>
                                <x-slot:trigger>
                                    <x-ui.button variant="ghost" size="sm" icon="ellipsis-vertical" x-bind:aria-expanded="open" aria-label="Aksi untuk {{ $u->name }}" />
                                </x-slot:trigger>
                                <x-ui.dropdown-item :href="route('users.edit', $u)" icon="pencil-square">Ubah</x-ui.dropdown-item>
                                <x-ui.dropdown-item icon="key"
                                    x-on:click="target = { url: {{ \Illuminate\Support\Js::from(route('users.password', $u)) }}, name: {{ \Illuminate\Support\Js::from($u->name) }} }; $dispatch('open-modal', 'reset-sandi')">
                                    Reset kata sandi
                                </x-ui.dropdown-item>
                                @if ($u->isNot(auth()->user()))
                                    <form method="POST" action="{{ route('users.toggle', $u) }}">
                                        @csrf
                                        @method('PATCH')
                                        <x-ui.dropdown-item type="submit" :icon="$u->is_active ? 'no-symbol' : 'check-circle'">
                                            {{ $u->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                        </x-ui.dropdown-item>
                                    </form>
                                    @can('delete', $u)
                                        <div class="my-1 border-t border-slate-100"></div>
                                        <x-ui.dropdown-item danger icon="trash"
                                            x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('users.destroy', $u)) }}, nama: {{ \Illuminate\Support\Js::from($u->name) }}, pesan: 'akan dihapus. Riwayat transaksi pengguna ini tetap tersimpan.' })">
                                            Hapus
                                        </x-ui.dropdown-item>
                                    @endcan
                                @endif
                            </x-ui.dropdown>
                        @endcan
                    </x-ui.table.td>
                </tr>
            @empty
                <x-ui.table.empty colspan="6" icon="users" title="Tidak ada pengguna yang cocok" description="Ubah kata kunci atau filter pencarian." />
            @endforelse
        </x-ui.table>

        <x-ui.modal name="reset-sandi" title="Reset kata sandi" :show="$errors->has('password') && filled(old('_reset_url'))">
            <form id="form-reset-sandi" method="POST" x-bind:action="target.url" class="grid gap-4">
                @csrf
                @method('PUT')
                <input type="hidden" name="_reset_url" x-bind:value="target.url">
                <input type="hidden" name="_reset_name" x-bind:value="target.name">
                <p class="text-sm text-slate-600">
                    Kata sandi baru untuk <span class="font-medium text-slate-900" x-text="target.name"></span>.
                    Sesi login yang sedang berjalan di perangkat lain akan diakhiri.
                </p>
                <x-ui.input name="password" label="Kata sandi baru" type="password" autocomplete="new-password" hint="Minimal 8 karakter, mengandung huruf dan angka." required />
                <x-ui.input name="password_confirmation" label="Ulangi kata sandi baru" type="password" autocomplete="new-password" required />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
                <x-ui.button type="submit" form="form-reset-sandi">Simpan kata sandi</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</x-app-layout>
