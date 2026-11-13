{{-- Saran pemesanan ulang: barang di bawah stok minimum per gudang, dibuat menjadi draft PO per supplier. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Saran Pesan Ulang</x-slot>
    <x-slot name="description">Barang yang stoknya (ditambah pesanan yang belum datang) sudah di bawah atau sama dengan stok minimum gudang. Jumlah saran = stok maksimum − stok − dipesan.</x-slot>
    <x-slot name="actions">
        @can('inventory.reorder.update')
            <x-ui.button variant="secondary" icon="adjustments-horizontal" :href="route('reorder.levels', array_filter(['gudang' => $dipilih]))">Atur min/maks</x-ui.button>
        @endcan
    </x-slot>

    <form method="POST" action="{{ route('reorder.store') }}">
        @csrf
        <x-ui.table :sticky="false">
            <x-slot:toolbar>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-slate-500">Gudang:</span>
                    <a href="{{ route('reorder.index') }}" @class(['rounded-full px-3 py-1 text-xs font-medium', 'bg-primary-600 text-white' => ! $dipilih, 'bg-slate-100 text-slate-700' => $dipilih])>Semua</a>
                    @foreach ($gudang as $g)
                        <a href="{{ route('reorder.index', ['gudang' => $g->id]) }}" @class(['rounded-full px-3 py-1 text-xs font-medium', 'bg-primary-600 text-white' => (int) $dipilih === $g->id, 'bg-slate-100 text-slate-700' => (int) $dipilih !== $g->id])>{{ $g->name }}</a>
                    @endforeach
                </div>
                <p class="text-sm text-slate-500">{{ $rows->count() }} barang perlu dipesan</p>
            </x-slot:toolbar>
            <x-slot:head>
                <x-ui.table.th><span class="sr-only">Pilih</span></x-ui.table.th>
                <x-ui.table.th>Produk</x-ui.table.th>
                <x-ui.table.th>Gudang</x-ui.table.th>
                <x-ui.table.th align="right">Stok</x-ui.table.th>
                <x-ui.table.th align="right">Dipesan</x-ui.table.th>
                <x-ui.table.th align="right">Min / maks</x-ui.table.th>
                <x-ui.table.th class="w-32">Pesan</x-ui.table.th>
                <x-ui.table.th class="w-52">Supplier</x-ui.table.th>
            </x-slot:head>
            @forelse ($rows as $i => $r)
                <tr>
                    <x-ui.table.td>
                        <input type="hidden" name="lines[{{ $i }}][product_id]" value="{{ $r['product']->id }}">
                        <input type="hidden" name="lines[{{ $i }}][warehouse_id]" value="{{ $r['warehouse']->id }}">
                        <input type="checkbox" name="lines[{{ $i }}][selected]" value="1" @checked(old("lines.{$i}.selected", true)) aria-label="Pilih {{ $r['product']->name }}" class="rounded border-slate-300 text-primary-600">
                    </x-ui.table.td>
                    <x-ui.table.td>
                        <p class="font-medium text-slate-900">{{ $r['product']->name }}</p>
                        <p class="text-xs text-slate-500">{{ $r['product']->sku }}</p>
                    </x-ui.table.td>
                    <x-ui.table.td muted>{{ $r['warehouse']->name }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric @class(['text-danger-600 font-medium' => $r['stock'] <= 0])>{{ Format::jumlah($r['stock']) }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric muted>{{ Format::jumlah($r['on_order']) }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric muted>{{ Format::jumlah($r['min']) }} / {{ Format::jumlah($r['max']) }}</x-ui.table.td>
                    <x-ui.table.td>
                        <input type="number" name="lines[{{ $i }}][quantity]" min="0" step="0.001" value="{{ old("lines.{$i}.quantity", $r['suggested']) }}"
                               aria-label="Jumlah pesan {{ $r['product']->name }}" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <span class="text-xs text-slate-500">{{ $r['product']->unit?->name }}</span>
                    </x-ui.table.td>
                    <x-ui.table.td>
                        <select name="lines[{{ $i }}][supplier_id]" aria-label="Supplier {{ $r['product']->name }}" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="">Pilih supplier</option>
                            @foreach ($suppliers as $id => $nama)
                                <option value="{{ $id }}" @selected((string) old("lines.{$i}.supplier_id", $r['supplier_id']) === (string) $id)>{{ $nama }}</option>
                            @endforeach
                        </select>
                    </x-ui.table.td>
                </tr>
            @empty
                <x-ui.table.empty colspan="8" icon="check-circle" title="Semua stok di atas minimum" description="Atur stok minimum/maksimum per gudang lewat tombol Atur min/maks." />
            @endforelse
            @if ($rows->isNotEmpty())
                <x-slot:footer>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-slate-500">Satu draft PO dibuat per supplier dan gudang, dengan harga beli terakhir produk.</p>
                        @can('inventory.reorder.create')
                            <x-ui.button type="submit" icon="document-plus">Buat draft PO</x-ui.button>
                        @endcan
                    </div>
                </x-slot:footer>
            @endif
        </x-ui.table>
    </form>
</x-app-layout>
