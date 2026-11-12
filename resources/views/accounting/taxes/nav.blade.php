{{-- Navigasi halaman Pajak. --}}
@php
    $menu = ['taxes.ppn' => 'Rekap PPN', 'taxes.documents' => 'Faktur PPN', 'taxes.withholdings' => 'Bukti potong PPh'];
@endphp
<nav class="mb-6 flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Pajak">
    @foreach ($menu as $route => $label)
        <a href="{{ route($route) }}" @class([
            '-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
            'border-primary-600 text-primary-700' => request()->routeIs($route),
            'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => ! request()->routeIs($route),
        ]) @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
