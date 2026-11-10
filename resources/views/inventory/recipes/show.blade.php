{{-- Detail resep (BOM): bahan per porsi, biaya bahan dari harga beli terbaru, dan margin per porsi. --}}
@use('App\Support\Format')
@php
    $produk = $recipe->product;
    $hargaJual = (float) ($produk->selling_price ?? 0);
    $margin = $hargaJual - $totalBiaya;
@endphp

<x-app-layout>
    <x-slot name="header">Resep {{ $produk?->name ?? 'produk dihapus' }}</x-slot>
    <x-slot name="description">SKU {{ $produk?->sku ?? '—' }} · jumlah bahan dihitung per 1 porsi produk jual.</x-slot>
    <x-slot name="actions">
        @can('inventory.recipes.update')
            <x-ui.button variant="secondary" icon="pencil-square" :href="route('recipes.edit', $recipe)">Ubah resep</x-ui.button>
            <x-ui.button variant="danger" icon="trash" x-data
                x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('recipes.destroy', $recipe)) }}, nama: {{ \Illuminate\Support\Js::from('Resep '.($produk?->name ?? '')) }} })">Hapus</x-ui.button>
        @endcan
    </x-slot>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Harga jual per porsi" :value="Format::rupiah($hargaJual)" icon="tag" color="neutral" />
        <x-ui.stat-card label="Biaya bahan per porsi" :value="Format::rupiah($totalBiaya)" icon="beaker" />
        <x-ui.stat-card label="Margin per porsi" :value="Format::rupiah($margin)" icon="banknotes" :color="$margin >= 0 ? 'success' : 'danger'"
                        :hint="$hargaJual > 0 ? Format::jumlah(round($margin / $hargaJual * 100, 1)).'% dari harga jual' : null" />
    </div>

    @if ($recipe->notes)
        <x-ui.alert type="info" class="mb-6">{{ $recipe->notes }}</x-ui.alert>
    @endif

    <x-ui.table :sticky="false">
        <x-slot:head>
            <x-ui.table.th>Bahan</x-ui.table.th>
            <x-ui.table.th align="right">Jumlah</x-ui.table.th>
            <x-ui.table.th>Satuan</x-ui.table.th>
            <x-ui.table.th align="right">Harga beli</x-ui.table.th>
            <x-ui.table.th align="right">Subtotal</x-ui.table.th>
        </x-slot:head>
        @forelse ($recipe->items as $item)
            @php
                $bahan = $item->product;
                $subtotal = $bahan ? (float) $bahan->purchase_price * (float) $item->quantity : 0;
            @endphp
            <tr>
                <x-ui.table.td>
                    <p class="font-medium text-slate-900">{{ $bahan?->name ?? 'Bahan dihapus' }}</p>
                    @if ($item->notes)
                        <p class="text-xs text-slate-500">{{ $item->notes }}</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ Format::jumlah($item->quantity) }}</x-ui.table.td>
                <x-ui.table.td muted>{{ $item->unit?->name ?? $bahan?->unit?->name ?? '—' }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric muted>@rupiah($bahan->purchase_price ?? 0)</x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($subtotal)</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="5" icon="beaker" title="Belum ada bahan" description="Ubah resep untuk menambahkan bahan baku." />
        @endforelse
        <x-slot:footer>
            <div class="flex justify-between text-sm font-semibold text-slate-900">
                <span>Total biaya bahan per porsi</span>
                <span class="tabular-nums">@rupiah($totalBiaya)</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Harga bahan mengikuti harga beli terbaru di menu Produk.</p>
        </x-slot:footer>
    </x-ui.table>
</x-app-layout>
