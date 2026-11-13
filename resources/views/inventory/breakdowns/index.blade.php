{{-- Pecah bahan: satu bahan dipecah menjadi beberapa produk sekaligus (misal 1 pack karkas → 9 potong ayam). --}}
@use('App\Models\ProductBreakdown')
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Pecah Bahan</x-slot>
    <x-slot name="description">Satu bahan menghasilkan beberapa produk sekaligus. Saat dipecah, stok bahan berkurang dan semua hasilnya bertambah; biaya bahan dibagi ke hasil.</x-slot>
    <x-slot name="actions">
        @can('inventory.recipes.create')
            <x-ui.button icon="plus" :href="route('breakdowns.create')">Pemecahan baru</x-ui.button>
        @endcan
    </x-slot>

    <div class="grid gap-4" x-data="{ pilih: null, aksi: @js(route('breakdowns.run', '__ID__')), nama: '' }">
        @forelse ($breakdowns as $b)
            <x-ui.card>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-900">
                            {{ Format::jumlah($b->source_quantity) }} {{ $b->source?->unit?->name }} {{ $b->source?->name }}
                            @unless ($b->is_active)<x-ui.badge class="ml-1">Nonaktif</x-ui.badge>@endunless
                        </p>
                        <ul class="mt-2 flex flex-wrap gap-2 text-sm">
                            @foreach ($b->outputs as $o)
                                <li class="rounded-lg bg-slate-100 px-2 py-1 text-slate-700">{{ Format::jumlah($o->quantity) }} {{ $o->product?->unit?->name }} {{ $o->product?->name }}</li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs text-slate-500">
                            Total {{ Format::jumlah($b->outputs->sum(fn ($o) => (float) $o->quantity)) }} hasil · biaya {{ mb_strtolower(ProductBreakdown::COST_METHODS[$b->cost_method] ?? $b->cost_method) }}
                            · stok bahan sekarang {{ Format::jumlah($b->source?->stock) }} {{ $b->source?->unit?->name }}{{ $b->notes ? ' · '.$b->notes : '' }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        @if ($b->is_active)
                            @can('inventory.adjustments.create')
                                <x-ui.button size="sm" icon="scissors" x-on:click="pilih = {{ $b->id }}; nama = {{ \Illuminate\Support\Js::from($b->source?->name.' ('.$b->source?->unit?->name.')') }}; $dispatch('open-modal', 'pecah')">Pecah</x-ui.button>
                            @endcan
                        @endif
                        @can('inventory.recipes.update')
                            <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('breakdowns.edit', $b)" aria-label="Ubah pemecahan {{ $b->source?->name }}" />
                        @endcan
                        @can('inventory.recipes.delete')
                            <x-ui.button variant="ghost" size="sm" icon="trash" class="text-danger-600" aria-label="Hapus pemecahan {{ $b->source?->name }}"
                                x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('breakdowns.destroy', $b)) }}, nama: {{ \Illuminate\Support\Js::from('Pemecahan '.$b->source?->name) }} })" />
                        @endcan
                    </div>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card><x-ui.empty-state icon="scissors" title="Belum ada pemecahan bahan" description="Contoh: 1 pack Karkas 1 menjadi 3 dada, 2 paha atas, 2 paha bawah, 2 sayap." /></x-ui.card>
        @endforelse

        @can('inventory.adjustments.create')
            <x-ui.modal name="pecah" title="Pecah bahan" :show="$errors->hasAny(['times', 'warehouse_id'])">
                <form id="form-pecah" method="POST" x-bind:action="aksi.replace('__ID__', pilih)" class="grid gap-4">
                    @csrf
                    <p class="text-sm text-slate-600">Berapa kemasan <strong class="text-slate-900" x-text="nama"></strong> yang dipecah?</p>
                    <x-ui.select name="warehouse_id" label="Gudang" required :options="$gudang->pluck('name', 'id')" :selected="$gudang->firstWhere('is_default', true)?->id" />
                    <x-ui.input name="times" label="Jumlah dipecah" type="number" min="1" step="1" required :value="1" />
                    <x-ui.input name="note" label="Catatan" maxlength="200" placeholder="Opsional, misal potong pagi" />
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
                    <x-ui.button type="submit" form="form-pecah" icon="scissors">Pecah</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endcan
    </div>
</x-app-layout>
