{{-- Detail produk: identitas & harga, stok per gudang, dan mutasi terakhir. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">{{ $produk->name }}</x-slot>
    <x-slot name="description">{{ $produk->sku }} · {{ $produk->category?->name }}</x-slot>
    <x-slot name="actions">
        @can('delete', $produk)
            <x-ui.button variant="ghost" icon="trash" class="text-danger-600 hover:text-danger-700"
                x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('products.destroy', $produk)) }}, nama: {{ \Illuminate\Support\Js::from($produk->name) }} })">Hapus</x-ui.button>
        @endcan
        @can('update', $produk)
            <x-ui.button :href="route('products.edit', $produk)" icon="pencil-square">Ubah produk</x-ui.button>
        @endcan
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2">
            <div class="flex flex-col gap-6 sm:flex-row">
                @if ($produk->photoUrl())
                    <img src="{{ $produk->photoUrl() }}" alt="Foto {{ $produk->name }}" class="h-36 w-36 shrink-0 rounded-xl object-cover">
                @else
                    <div class="flex h-36 w-36 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-300" aria-hidden="true">
                        <x-ui.icon name="cube" class="h-12 w-12" />
                    </div>
                @endif
                <dl class="grid flex-1 gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">SKU</dt><dd class="font-medium text-slate-900">{{ $produk->sku }}</dd></div>
                    <div><dt class="text-slate-500">Barcode</dt><dd class="font-medium text-slate-900">{{ $produk->barcode ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">Satuan</dt><dd class="font-medium text-slate-900">{{ $produk->unit?->name }}</dd></div>
                    <div><dt class="text-slate-500">Status</dt><dd><x-ui.badge :color="$produk->is_active ? 'success' : 'neutral'" dot>{{ $produk->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge></dd></div>
                    <div><dt class="text-slate-500">Harga jual</dt><dd class="font-medium tabular-nums text-slate-900">@rupiah($produk->selling_price)</dd></div>
                    <div>
                        <dt class="text-slate-500">Diskon produk</dt>
                        <dd class="font-medium text-slate-900">
                            {{ Format::jumlah($produk->discount_percent) }}%
                            @if ((float) $produk->discount_percent > 0)
                                <span class="font-normal text-slate-500">(menjadi @rupiah($produk->hargaSetelahDiskon()))</span>
                            @endif
                        </dd>
                    </div>
                    {{-- Harga beli dan laba hanya untuk yang berhak mengelola produk. --}}
                    @can('update', $produk)
                        <div><dt class="text-slate-500">Harga beli</dt><dd class="font-medium tabular-nums text-slate-900">@rupiah($produk->purchase_price)</dd></div>
                        <div><dt class="text-slate-500">Laba kotor per {{ $produk->unit?->name }}</dt><dd class="font-medium tabular-nums text-slate-900">@rupiah((float) $produk->selling_price - (float) $produk->purchase_price)</dd></div>
                    @endcan
                    @if ($produk->description)
                        <div class="sm:col-span-2"><dt class="text-slate-500">Deskripsi</dt><dd class="mt-1 whitespace-pre-line text-slate-700">{{ $produk->description }}</dd></div>
                    @endif
                </dl>
            </div>
        </x-ui.card>

        <x-ui.card title="Stok per gudang" :padding="false">
            <div class="flex items-end justify-between gap-3 px-5 pt-4">
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Total</p>
                    <p class="text-2xl font-semibold tabular-nums text-slate-900">{{ Format::jumlah($produk->stock) }} <span class="text-base font-normal text-slate-500">{{ $produk->unit?->name }}</span></p>
                </div>
                <div class="text-right">
                    @include('partials.badge-stok', ['status' => $produk->statusStok()])
                    <p class="mt-1 text-xs text-slate-500">min {{ Format::jumlah($produk->min_stock) }}</p>
                </div>
            </div>
            <ul class="mt-4 divide-y divide-slate-100 border-t border-slate-100">
                @foreach ($stokGudang as $row)
                    <li class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                        <span class="text-slate-700">{{ $row['gudang']->name }}@unless ($row['gudang']->is_active) <span class="text-xs text-slate-400">(nonaktif)</span>@endunless</span>
                        @can('inventory.stock-card.view')
                            <a href="{{ route('stock.movements', ['produk' => $produk->id, 'gudang' => $row['gudang']->id]) }}"
                               @class(['tabular-nums hover:underline', 'font-medium text-slate-900' => $row['jumlah'] > 0, 'text-slate-400' => $row['jumlah'] == 0, 'font-medium text-danger-600' => $row['jumlah'] < 0])>{{ Format::jumlah($row['jumlah']) }}</a>
                        @else
                            <span class="tabular-nums text-slate-900">{{ Format::jumlah($row['jumlah']) }}</span>
                        @endcan
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    </div>

    @can('inventory.stock-card.view')
        <x-ui.card title="Mutasi terakhir" class="mt-6" :padding="false">
            <x-slot:actions>
                <div class="flex gap-2">
                    @can('inventory.adjustments.create')
                        <x-ui.button variant="secondary" size="sm" :href="route('stock.adjust.create', ['produk' => $produk->id])">Sesuaikan stok</x-ui.button>
                    @endcan
                    <x-ui.button variant="link" size="sm" :href="route('stock.movements', ['produk' => $produk->id])">Kartu stok lengkap</x-ui.button>
                </div>
            </x-slot:actions>
            <ul class="divide-y divide-slate-100">
                @forelse ($riwayat as $m)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                        <div class="flex items-center gap-3">
                            @include('partials.badge-mutasi', ['jenis' => $m->type])
                            <span class="text-slate-500">{{ $m->warehouse?->name }} · {{ Format::tanggal($m->created_at, true) }}</span>
                        </div>
                        <div class="tabular-nums">
                            <span @class(['font-semibold', 'text-success-700' => (float) $m->quantity > 0, 'text-danger-600' => (float) $m->quantity < 0])>{{ (float) $m->quantity > 0 ? '+' : '−' }}{{ Format::jumlah(abs((float) $m->quantity)) }}</span>
                            <span class="text-slate-500">→ {{ Format::jumlah($m->stock_after) }}</span>
                        </div>
                    </li>
                @empty
                    <li class="px-5 py-6 text-center text-sm text-slate-500">Belum ada mutasi stok untuk produk ini.</li>
                @endforelse
            </ul>
        </x-ui.card>
    @endcan
    @if ($produk->track_batches)
        <x-ui.card title="Batch & kedaluwarsa" class="mt-6" :padding="false">
            <ul class="divide-y divide-slate-100">
                @forelse ($lots as $lot)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                        <div>
                            <span class="font-mono text-xs font-semibold text-slate-900">{{ $lot->batch_number ?? 'Tanpa batch' }}</span>
                            <span class="text-slate-500">· {{ $lot->warehouse?->name }}</span>
                            @if ($lot->expiry_date)
                                <x-ui.badge :color="$lot->isExpired() ? 'danger' : ($lot->expiry_date->lte(today()->addDays((int) setting('inventory.expiry_warning_days', 30))) ? 'warning' : 'neutral')" class="ml-1">
                                    exp {{ Format::tanggal($lot->expiry_date) }}
                                </x-ui.badge>
                            @endif
                        </div>
                        <span class="tabular-nums text-slate-900">{{ Format::jumlah($lot->quantity) }} {{ $produk->unit?->name }}</span>
                    </li>
                @empty
                    <li class="px-5 py-6 text-center text-sm text-slate-500">Belum ada batch yang bersisa.</li>
                @endforelse
            </ul>
        </x-ui.card>
    @endif

    @if ($produk->track_serials)
        <x-ui.card title="Nomor seri di stok" class="mt-6">
            @if ($serials->isEmpty())
                <p class="text-sm text-slate-500">Belum ada nomor seri di stok.</p>
            @else
                <div class="flex flex-wrap gap-2">
                    @foreach ($serials as $s)
                        <span class="rounded bg-slate-100 px-2 py-1 font-mono text-xs text-slate-700" title="{{ $s->warehouse?->name }}">{{ $s->serial_number }}</span>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    @endif
    @if ($produk->parent_id)
        <x-ui.alert type="info" class="mt-6">Varian dari <a href="{{ route('products.show', $produk->parent_id) }}" class="font-medium underline">{{ $produk->parent?->name }}</a>: {{ $produk->variantLabel() }}.</x-ui.alert>
    @endif

    @if ($produk->isVariantParent())
        <x-ui.card title="Varian" class="mt-6" :padding="false">
            <x-slot:actions><span class="text-xs text-slate-500">{{ collect($produk->variant_options)->map(fn ($o) => $o['name'].': '.implode(', ', $o['values']))->join(' · ') }}</span></x-slot:actions>
            <ul class="divide-y divide-slate-100">
                @foreach ($produk->variants as $v)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                        <div>
                            <a href="{{ route('products.show', $v) }}" class="font-medium text-slate-900 hover:underline">{{ $v->variantLabel() }}</a>
                            <span class="text-slate-500">· {{ $v->sku }}{{ $v->barcode ? ' · '.$v->barcode : '' }}</span>
                            @unless ($v->is_active)<x-ui.badge class="ml-1">Nonaktif</x-ui.badge>@endunless
                        </div>
                        <span class="tabular-nums text-slate-700">@rupiah($v->selling_price) · stok {{ Format::jumlah($v->stock) }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    @if ($produk->units->isNotEmpty())
        <x-ui.card title="Satuan lain" class="mt-6" :padding="false">
            <ul class="divide-y divide-slate-100">
                @foreach ($produk->units as $u)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                        <span><strong class="text-slate-900">{{ $u->unit?->name }}</strong> = {{ Format::jumlah($u->factor) }} {{ $produk->unit?->name }}{{ $u->barcode ? ' · barcode '.$u->barcode : '' }}</span>
                        <span class="tabular-nums text-slate-700">@rupiah($u->priceSen() / 100)</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    @if ($produk->is_bundle)
        <x-ui.card title="Isi paket" class="mt-6" :padding="false">
            <ul class="divide-y divide-slate-100">
                @forelse ($produk->recipe?->items ?? [] as $k)
                    <li class="flex items-center justify-between px-5 py-3 text-sm">
                        <span class="text-slate-900">{{ $k->product?->name }}</span>
                        <span class="tabular-nums text-slate-700">{{ Format::jumlah($k->quantity) }} {{ $k->product?->unit?->name }}</span>
                    </li>
                @empty
                    <li class="px-5 py-6 text-center text-sm text-warning-700">Komponen paket belum diatur. Paket tidak bisa dijual sebelum komponennya diisi di menu Resep/BOM.</li>
                @endforelse
            </ul>
        </x-ui.card>
    @endif
</x-app-layout>
