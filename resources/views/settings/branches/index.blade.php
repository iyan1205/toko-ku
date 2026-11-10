{{-- Daftar cabang/outlet. Cabang aktif dipilih pengguna dari topbar. --}}
<x-app-layout>
    <x-slot name="header">Cabang</x-slot>
    <x-slot name="description">Cabang atau outlet toko. Pengguna memilih cabang aktif dari topbar.</x-slot>
    @can('settings.branches.create')
        <x-slot name="actions">
            <x-ui.button :href="route('settings.branches.create')" icon="plus">Tambah cabang</x-ui.button>
        </x-slot>
    @endcan

    <x-ui.alert class="mb-6">
        Transaksi dicatat per cabang mengikuti gudangnya (atur di Inventori → Gudang/Lokasi). Pengguna melihat data cabang aktif di topbar;
        cabang yang boleh diakses tiap pengguna diatur di Pengguna &amp; Hak Akses → Pengguna.
    </x-ui.alert>

    <x-ui.table :sticky="false">
        <x-slot:head>
            <x-ui.table.th>Cabang</x-ui.table.th>
            <x-ui.table.th>Kontak</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>

        @forelse ($branches as $branch)
            <tr>
                <x-ui.table.td>
                    <p class="flex flex-wrap items-center gap-2 font-medium text-slate-900">
                        {{ $branch->name }}
                        @if ($branch->is_default)
                            <x-ui.badge color="primary">Utama</x-ui.badge>
                        @endif
                    </p>
                    <p class="mt-0.5 font-mono text-xs text-slate-500">{{ $branch->code }}</p>
                </x-ui.table.td>
                <x-ui.table.td>
                    <p class="max-w-xs truncate text-slate-700">{{ $branch->address ?: '—' }}</p>
                    @if ($branch->phone)
                        <p class="mt-0.5 text-xs text-slate-500">{{ $branch->phone }}</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td>
                    @if ($branch->is_active)
                        <x-ui.badge color="success" dot>Aktif</x-ui.badge>
                    @else
                        <x-ui.badge dot>Nonaktif</x-ui.badge>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td align="right">
                    <div class="flex items-center justify-end gap-1">
                        @can('settings.branches.update')
                            <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('settings.branches.edit', $branch)" aria-label="Ubah {{ $branch->name }}" />
                        @endcan
                        @if (! $branch->is_default && auth()->user()->can('settings.branches.delete'))
                            <x-ui.button variant="ghost" size="sm" icon="trash" aria-label="Hapus {{ $branch->name }}" class="text-danger-600 hover:text-danger-700"
                                x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('settings.branches.destroy', $branch)) }}, nama: {{ \Illuminate\Support\Js::from('Cabang '.$branch->name) }}, pesan: 'akan dihapus dan tidak bisa dipilih lagi.' })" />
                        @endif
                    </div>
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="4" title="Belum ada cabang" />
        @endforelse
    </x-ui.table>
</x-app-layout>
