{{--
    Menu tarik-turun. Slot trigger = tombol pembuka (variabel Alpine `open` bisa dipakai di dalamnya).
    Tertutup saat klik di luar, Esc, atau memilih item. align: right | left

    <x-ui.dropdown>
        <x-slot:trigger>
            <x-ui.button variant="secondary" icon-right="chevron-down" x-bind:aria-expanded="open">Aksi</x-ui.button>
        </x-slot:trigger>
        <x-ui.dropdown-item :href="route('products.export')" icon="arrow-down-tray">Ekspor Excel</x-ui.dropdown-item>
        <x-ui.dropdown-item danger icon="trash">Hapus</x-ui.dropdown-item>
    </x-ui.dropdown>
--}}
@props(['align' => 'right', 'width' => 'w-56'])

<div x-data="{ open: false }"
     x-on:click.outside="open = false"
     x-on:keydown.escape="open = false"
     {{ $attributes->class('relative inline-block text-left') }}>
    <div x-on:click="open = ! open">
        {{ $trigger }}
    </div>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-100" x-transition:enter-start="scale-95 opacity-0" x-transition:enter-end="scale-100 opacity-100"
         x-transition:leave="transition ease-in duration-75" x-transition:leave-start="scale-100 opacity-100" x-transition:leave-end="scale-95 opacity-0"
         x-on:click="open = false"
         role="menu"
         @class([
             'absolute z-30 mt-2 rounded-lg bg-white py-1 shadow-float ring-1 ring-slate-900/5 focus:outline-none',
             $width,
             'right-0 origin-top-right' => $align === 'right',
             'left-0 origin-top-left' => $align === 'left',
         ])>
        {{ $slot }}
    </div>
</div>
