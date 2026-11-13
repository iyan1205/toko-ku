{{-- Stok minimum & maksimum per produk di satu gudang (dasar saran pesan ulang). --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Stok Min/Maks per Gudang</x-slot>
    <x-slot name="description">Kosong = tidak dipantau di gudang ini (gudang utama memakai stok minimum produk). Maksimum kosong = 2 × minimum.</x-slot>
    <x-slot name="actions">
        <x-ui.button variant="secondary" icon="arrow-left" :href="route('reorder.index')">Saran pesan ulang</x-ui.button>
    </x-slot>

    <form method="POST" action="{{ route('reorder.levels.save') }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="warehouse_id" value="{{ $warehouse?->id }}">
        <x-ui.table :paginator="$products" :sticky="false">
            <x-slot:toolbar>
                <div class="flex flex-wrap items-center gap-2">
                    @foreach ($gudang as $g)
                        <a href="{{ route('reorder.levels', ['gudang' => $g->id] + array_filter(['cari' => $filter['cari'] ?? null])) }}" @class(['rounded-full px-3 py-1 text-xs font-medium', 'bg-primary-600 text-white' => $warehouse?->is($g), 'bg-slate-100 text-slate-700' => ! $warehouse?->is($g)])>{{ $g->name }}</a>
                    @endforeach
                </div>
            </x-slot:toolbar>
            <x-slot:head>
                <x-ui.table.th>Produk</x-ui.table.th>
                <x-ui.table.th align="right">Stok di gudang ini</x-ui.table.th>
                <x-ui.table.th class="w-36">Minimum</x-ui.table.th>
                <x-ui.table.th class="w-36">Maksimum</x-ui.table.th>
            </x-slot:head>
            @foreach ($products as $p)
                @php
                    $l = $levels->get($p->id);
                @endphp
                <tr>
                    <x-ui.table.td>
                        <p class="font-medium text-slate-900">{{ $p->name }}</p>
                        <p class="text-xs text-slate-500">{{ $p->sku }} · min produk {{ Format::jumlah($p->min_stock) }} {{ $p->unit?->name }}</p>
                    </x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ Format::jumlah($l?->quantity ?? 0) }}</x-ui.table.td>
                    <x-ui.table.td><input type="number" min="0" step="0.001" name="levels[{{ $p->id }}][min]" value="{{ $l?->min_stock !== null ? (float) $l->min_stock : '' }}" aria-label="Minimum {{ $p->name }}" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm"></x-ui.table.td>
                    <x-ui.table.td><input type="number" min="0" step="0.001" name="levels[{{ $p->id }}][max]" value="{{ $l?->max_stock !== null ? (float) $l->max_stock : '' }}" aria-label="Maksimum {{ $p->name }}" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm"></x-ui.table.td>
                </tr>
            @endforeach
            <x-slot:footer>
                <div class="flex justify-end"><x-ui.button type="submit">Simpan halaman ini</x-ui.button></div>
            </x-slot:footer>
        </x-ui.table>
    </form>
</x-app-layout>
