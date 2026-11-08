{{--
    Tabel data dalam kartu. Header menempel (sticky) saat isi tabel di-scroll.
    Slot: head (baris <th>), toolbar (opsional, di atas tabel), isi = baris <tr>.
    Pagination otomatis tampil bila diberi :paginator dan halamannya lebih dari satu.

    <x-ui.table :paginator="$produk">
        <x-slot:toolbar><x-ui.filter-bar ... /></x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th sort="name">Produk</x-ui.table.th>
            <x-ui.table.th align="right" sort="selling_price">Harga</x-ui.table.th>
        </x-slot:head>
        @forelse ($produk as $p)
            <tr>
                <x-ui.table.td>{{ $p->name }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($p->selling_price)</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="2" title="Belum ada produk" />
        @endforelse
    </x-ui.table>
--}}
@props(['paginator' => null, 'sticky' => true])

<div {{ $attributes->class('overflow-hidden rounded-xl border border-slate-200 bg-white shadow-card') }}>
    @isset($toolbar)
        <div class="border-b border-slate-200 p-4">{{ $toolbar }}</div>
    @endisset

    <div @class(['overflow-x-auto', 'max-h-[70vh] overflow-y-auto' => $sticky])>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead @class(['bg-slate-50', 'sticky top-0 z-10 shadow-[inset_0_-1px_0_theme(colors.slate.200)]' => $sticky])>
                <tr>{{ $head }}</tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white [&>tr:hover]:bg-slate-50/70 [&>tr]:transition-colors">
                {{ $slot }}
            </tbody>
        </table>
    </div>

    @if ($paginator && $paginator->hasPages())
        <div class="border-t border-slate-200 px-4 py-3">
            {{ $paginator->links() }}
        </div>
    @endif

    @isset($footer)
        <div class="border-t border-slate-200 px-4 py-3">{{ $footer }}</div>
    @endisset
</div>
