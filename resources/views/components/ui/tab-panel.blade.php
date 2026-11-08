{{-- Isi satu tab di dalam x-ui.tabs. Lihat contoh di tabs.blade.php. --}}
@props(['name'])

<div x-show="tab === @js($name)" x-cloak
     role="tabpanel" id="panel-{{ $name }}" aria-labelledby="tab-{{ $name }}" tabindex="0"
     {{ $attributes->class('focus:outline-none') }}>
    {{ $slot }}
</div>
