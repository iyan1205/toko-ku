{{--
    Badge status. color: neutral | primary | success | warning | danger | info

    <x-ui.badge color="success">Lunas</x-ui.badge>
    <x-ui.badge color="warning" dot>Menipis</x-ui.badge>
--}}
@props(['color' => 'neutral', 'dot' => false])

@php
    [$badgeClass, $dotClass] = match ($color) {
        'primary' => ['bg-primary-50 text-primary-700 ring-primary-600/20', 'bg-primary-500'],
        'success' => ['bg-success-50 text-success-700 ring-success-600/20', 'bg-success-500'],
        'warning' => ['bg-warning-50 text-warning-800 ring-warning-600/25', 'bg-warning-500'],
        'danger' => ['bg-danger-50 text-danger-700 ring-danger-600/20', 'bg-danger-500'],
        'info' => ['bg-info-50 text-info-700 ring-info-600/20', 'bg-info-500'],
        default => ['bg-slate-50 text-slate-700 ring-slate-500/20', 'bg-slate-400'],
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $badgeClass]) }}>
    @if ($dot)
        <span class="h-1.5 w-1.5 rounded-full {{ $dotClass }}" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
