{{--
    Kartu angka (KPI). trend-direction: up | down. Warna tren hijau bila baik, merah bila buruk;
    bawaannya "naik = baik". Untuk angka yang lebih baik bila turun (misal stok menipis), beri :trend-good="false".

    <x-ui.stat-card label="Penjualan hari ini" value="Rp 1.250.000" icon="banknotes" trend="+12%" trend-direction="up" hint="dibanding kemarin" />
    <x-ui.stat-card label="Stok menipis" value="8" icon="exclamation-triangle" color="warning" :href="route('stock.index')" />
--}}
@props([
    'label',
    'value',
    'icon' => null,
    'color' => 'primary',
    'trend' => null,
    'trendDirection' => null,
    'trendGood' => null,
    'hint' => null,
    'href' => null,
])

@php
    $iconClass = match ($color) {
        'success' => 'bg-success-50 text-success-600',
        'warning' => 'bg-warning-50 text-warning-600',
        'danger' => 'bg-danger-50 text-danger-600',
        'info' => 'bg-info-50 text-info-600',
        'neutral' => 'bg-slate-100 text-slate-600',
        default => 'bg-primary-50 text-primary-600',
    };

    $isGood = $trendGood ?? ($trendDirection === 'up');
    $trendClass = $trendDirection === null ? 'text-slate-500' : ($isGood ? 'text-success-600' : 'text-danger-600');
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif
    {{ $attributes->class([
        'block rounded-xl border border-slate-200 bg-white p-5 shadow-card',
        'transition-colors hover:border-slate-300 hover:bg-slate-50/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500' => $href,
    ]) }}>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="truncate text-sm font-medium text-slate-500">{{ $label }}</p>
            <p class="mt-2 truncate text-2xl font-semibold tracking-tight text-slate-900 tabular-nums">{{ $value }}</p>
        </div>
        @if ($icon)
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg {{ $iconClass }}">
                <x-ui.icon :name="$icon" class="h-5 w-5" />
            </span>
        @endif
    </div>

    @if ($trend || $hint)
        <p class="mt-3 flex items-center gap-1 text-xs">
            @if ($trend)
                <span class="inline-flex items-center gap-0.5 font-medium {{ $trendClass }}">
                    @if ($trendDirection)
                        <x-ui.icon :name="$trendDirection === 'up' ? 'arrow-trending-up' : 'arrow-trending-down'" variant="m" class="h-4 w-4" />
                    @endif
                    {{ $trend }}
                </span>
            @endif
            @if ($hint)
                <span class="text-slate-500">{{ $hint }}</span>
            @endif
        </p>
    @endif
</{{ $tag }}>
