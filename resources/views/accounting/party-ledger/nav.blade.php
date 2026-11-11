{{-- Navigasi laporan piutang (umur, kartu) / hutang (jadwal, umur, kartu). Variabel: $receivable. --}}
@php
    $menu = $receivable
        ? ['receivable-reports.aging' => 'Umur piutang', 'receivable-reports.card' => 'Kartu piutang']
        : ['payable-reports.schedule' => 'Jadwal pembayaran', 'payable-reports.aging' => 'Umur hutang', 'payable-reports.card' => 'Kartu hutang'];
@endphp
<nav class="mb-6 flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Laporan {{ $receivable ? 'piutang' : 'hutang' }}">
    @foreach ($menu as $route => $label)
        <a href="{{ route($route) }}" @class([
            '-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
            'border-primary-600 text-primary-700' => request()->routeIs($route),
            'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => ! request()->routeIs($route),
        ]) @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
