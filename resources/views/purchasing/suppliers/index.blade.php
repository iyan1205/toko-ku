{{-- Daftar supplier: cari, filter yang masih punya hutang, urutkan, ekspor. --}}
<x-app-layout>
    <x-slot name="header">Supplier</x-slot>
    <x-slot name="description">Pemasok barang beserta total pembelian dan sisa hutangnya.</x-slot>
    <x-slot name="actions">
        @can('purchasing.suppliers.import')
            <x-ui.button variant="secondary" icon="arrow-up-tray" :href="route('imports.suppliers.create')">Impor</x-ui.button>
        @endcan
        @can('purchasing.suppliers.export')
            <x-ui.export-menu :href="route('suppliers.export')" />
        @endcan
        @can('purchasing.suppliers.create')
            <x-ui.button :href="route('suppliers.create')" icon="plus">Tambah supplier</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :paginator="$suppliers">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari kode, nama, kontak, atau telepon">
                <x-ui.select name="hutang" label="Hutang" hide-label :options="['ada' => 'Masih punya hutang']" :selected="request('hutang')" placeholder="Semua supplier" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="name">Supplier</x-ui.table.th>
            <x-ui.table.th>Kontak</x-ui.table.th>
            <x-ui.table.th align="right" sort="purchases">Total pembelian</x-ui.table.th>
            <x-ui.table.th align="right" sort="debt">Sisa hutang</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>

        @forelse ($suppliers as $s)
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('suppliers.show', $s) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $s->name }}</a>
                    <p class="font-mono text-xs text-slate-500">{{ $s->code }}</p>
                </x-ui.table.td>
                <x-ui.table.td>
                    <p class="text-slate-700">{{ $s->contact_name ?: '—' }}</p>
                    @if ($s->phone)
                        <p class="text-xs text-slate-500">{{ $s->phone }}</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric muted>@rupiah($s->purchase_total ?? 0)</x-ui.table.td>
                <x-ui.table.td align="right" numeric :class="(float) $s->debt_total > 0 ? 'font-medium text-warning-700' : 'text-slate-400'">@rupiah($s->debt_total ?? 0)</x-ui.table.td>
                <x-ui.table.td align="right">
                    <div class="flex items-center justify-end gap-1">
                        @can('purchasing.suppliers.update')
                            <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('suppliers.edit', $s)" aria-label="Ubah {{ $s->name }}" />
                        @endcan
                        @can('purchasing.suppliers.delete')
                            <x-ui.button variant="ghost" size="sm" icon="trash" aria-label="Hapus {{ $s->name }}" class="text-danger-600 hover:text-danger-700"
                                x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('suppliers.destroy', $s)) }}, nama: {{ \Illuminate\Support\Js::from($s->name) }} })" />
                        @endcan
                    </div>
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="5" icon="truck" title="Tidak ada supplier yang cocok" description="Ubah kata kunci, atau tambah supplier baru." />
        @endforelse
    </x-ui.table>
</x-app-layout>
