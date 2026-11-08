{{--
    Item di dalam x-ui.dropdown. Menjadi <a> bila diberi href, selain itu <button>
    (pakai type="submit" di dalam <form>, misal untuk Keluar).

    <x-ui.dropdown-item :href="route('profile.edit')" icon="user">Profil saya</x-ui.dropdown-item>
    <form method="POST" action="{{ route('logout') }}">@csrf <x-ui.dropdown-item type="submit" icon="arrow-right-start-on-rectangle">Keluar</x-ui.dropdown-item></form>
--}}
@props(['href' => null, 'icon' => null, 'danger' => false, 'type' => 'button'])

@php($tag = $href ? 'a' : 'button')

<{{ $tag }}
    @if ($href) href="{{ $href }}" @else type="{{ $type }}" @endif
    role="menuitem"
    {{ $attributes->class([
        'flex w-full items-center gap-2 px-3 py-2 text-left text-sm focus:outline-none',
        'text-slate-700 hover:bg-slate-50 hover:text-slate-900 focus-visible:bg-slate-100' => ! $danger,
        'text-danger-600 hover:bg-danger-50 focus-visible:bg-danger-50' => $danger,
    ]) }}>
    @if ($icon)
        <x-ui.icon :name="$icon" variant="m" :class="$danger ? 'h-4 w-4' : 'h-4 w-4 text-slate-400'" />
    @endif
    {{ $slot }}
</{{ $tag }}>
