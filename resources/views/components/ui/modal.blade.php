{{--
    Dialog modal. Buka/tutup dari mana saja dengan event:
        $dispatch('open-modal', 'tambah-kategori')   /   $dispatch('close-modal', 'tambah-kategori')
    Tutup juga dengan Esc, tombol X, atau klik di luar. Fokus pindah ke elemen [autofocus]
    atau isian pertama saat dibuka. max-width: sm | md | lg | xl | 2xl

    <x-ui.modal name="tambah-kategori" title="Tambah kategori">
        ...isi form...
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
            <x-ui.button type="submit" form="form-kategori">Simpan</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
--}}
@props(['name', 'title' => null, 'description' => null, 'maxWidth' => 'md', 'show' => false])

@php
    $widthClass = [
        'sm' => 'sm:max-w-sm',
        'md' => 'sm:max-w-md',
        'lg' => 'sm:max-w-lg',
        'xl' => 'sm:max-w-xl',
        '2xl' => 'sm:max-w-2xl',
    ][$maxWidth];
    $titleId = 'modal-'.$name.'-judul';
@endphp

<div x-data="{ show: @js($show) }"
     x-init="$watch('show', terbuka => {
         document.body.classList.toggle('overflow-hidden', terbuka);
         if (terbuka) $nextTick(() => ($el.querySelector('[autofocus]') ?? $el.querySelector('input:not([type=hidden]), select, textarea'))?.focus());
     })"
     x-on:open-modal.window="if ($event.detail === @js($name)) show = true"
     x-on:close-modal.window="if ($event.detail === @js($name)) show = false"
     x-on:keydown.escape.window="show = false"
     x-show="show" x-cloak
     role="dialog" aria-modal="true" @if ($title) aria-labelledby="{{ $titleId }}" @endif
     {{ $attributes->class('fixed inset-0 z-50 overflow-y-auto') }}>
    <div x-show="show" x-transition.opacity class="fixed inset-0 bg-slate-900/50" x-on:click="show = false" aria-hidden="true"></div>

    <div class="flex min-h-full items-end justify-center p-4 sm:items-center">
        <div x-show="show"
             x-transition:enter="ease-out duration-200" x-transition:enter-start="translate-y-4 opacity-0 sm:translate-y-0 sm:scale-95" x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
             x-transition:leave="ease-in duration-150" x-transition:leave-start="opacity-100 sm:scale-100" x-transition:leave-end="translate-y-4 opacity-0 sm:translate-y-0 sm:scale-95"
             class="relative w-full {{ $widthClass }} rounded-xl bg-white shadow-xl">
            @if ($title)
                <div class="flex items-start justify-between gap-4 px-6 pt-5">
                    <div>
                        <h2 id="{{ $titleId }}" class="text-base font-semibold text-slate-900">{{ $title }}</h2>
                        @if ($description)
                            <p class="mt-1 text-sm text-slate-500">{{ $description }}</p>
                        @endif
                    </div>
                    <button type="button" x-on:click="show = false" class="-mr-2 -mt-1 rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500" aria-label="Tutup">
                        <x-ui.icon name="x-mark" variant="m" class="h-5 w-5" />
                    </button>
                </div>
            @endif

            <div class="px-6 py-5">
                {{ $slot }}
            </div>

            @isset($footer)
                <div class="flex flex-col-reverse gap-2 rounded-b-xl border-t border-slate-200 bg-slate-50/60 px-6 py-4 sm:flex-row sm:justify-end">
                    {{ $footer }}
                </div>
            @endisset
        </div>
    </div>
</div>
