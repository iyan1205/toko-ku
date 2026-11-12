{{-- Navigasi bagian Aset Tetap. --}}
@php
    $menu = ['fixed-assets.index' => ['Daftar aset', 'fixed-assets.*'], 'depreciation.index' => ['Penyusutan', 'depreciation*'], 'asset-categories.index' => ['Kategori', 'asset-categories.*']];
@endphp
<nav class="mb-6 flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Aset tetap">
    @foreach ($menu as $route => [$label, $active])
        <a href="{{ route($route) }}" @class([
            '-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
            'border-primary-600 text-primary-700' => request()->routeIs($active),
            'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => ! request()->routeIs($active),
        ]) @if (request()->routeIs($active)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
