{{-- Resep (BOM) per produk jual: biaya bahan mengikuti harga beli bahan saat ini. --}}
<x-app-layout>
    <x-slot name="header">Resep (BOM)</x-slot>
    <x-slot name="description">Komposisi bahan baku per produk jual. Biaya bahan otomatis mengikuti harga beli terbaru.</x-slot>
    <x-slot name="actions">
        @can('inventory.recipes.export')
            <x-ui.export-menu :href="route('recipes.export')" />
        @endcan
        @can('inventory.recipes.create')
            <x-ui.button :href="route('recipes.create')" icon="plus">Buat resep</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :paginator="$recipes">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nama atau SKU produk" />
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="product">Produk</x-ui.table.th>
            <x-ui.table.th align="right" sort="items">Bahan</x-ui.table.th>
            <x-ui.table.th align="right">Biaya bahan</x-ui.table.th>
            <x-ui.table.th align="right">Harga jual</x-ui.table.th>
            <x-ui.table.th align="right">Margin</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>

        @forelse ($recipes as $r)
            @php($margin = $list->margin($r))
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('recipes.show', $r) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $r->product?->name ?? 'Produk dihapus' }}</a>
                    <p class="text-xs text-slate-500">{{ $r->product?->sku }}</p>
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ $r->items_count }} bahan</x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($list->cost($r))</x-ui.table.td>
                <x-ui.table.td align="right" numeric muted>@rupiah($r->product?->selling_price ?? 0)</x-ui.table.td>
                <x-ui.table.td align="right" numeric :class="$margin >= 0 ? 'font-medium text-success-700' : 'font-medium text-danger-600'">@rupiah($margin)</x-ui.table.td>
                <x-ui.table.td align="right">
                    @can('inventory.recipes.update')
                        <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('recipes.edit', $r)" aria-label="Ubah resep {{ $r->product?->name }}" />
                    @endcan
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="6" icon="beaker" title="Belum ada resep yang cocok" description="Ubah kata kunci, atau buat resep baru." />
        @endforelse
    </x-ui.table>
</x-app-layout>
