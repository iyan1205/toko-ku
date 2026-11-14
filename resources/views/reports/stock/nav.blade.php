{{-- Navigasi Laporan → Stok. Filter gudang & kategori ikut terbawa saat pindah laporan. --}}
@php
    $menu = [
        'reports.stock.valuation' => 'Nilai Persediaan',
        'reports.stock.turnover' => 'Perputaran Stok',
        'reports.stock.slow-moving' => 'Barang Lambat & Mati',
        'reports.stock.batch-card' => 'Kartu Stok per Batch',
    ];
    $bawa = request()->only(['gudang', 'kategori']);
@endphp
<nav class="mb-6 flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Laporan stok">
    @foreach ($menu as $route => $label)
        <a href="{{ route($route, $route === 'reports.stock.batch-card' ? array_intersect_key($bawa, ['gudang' => 1]) : $bawa) }}" @class([
            '-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
            'border-primary-600 text-primary-700' => request()->routeIs($route),
            'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => ! request()->routeIs($route),
        ]) @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
