{{--
    Bar pencarian & filter (form GET). Isian cari memakai parameter `cari` seperti halaman lama.
    Slot = filter tambahan (biasanya x-ui.select dengan hide-label). Urutan tabel (sort/dir) tetap
    terbawa. Tombol Reset muncul bila ada filter yang terisi.

    <x-ui.filter-bar placeholder="Cari nama, SKU, atau barcode">
        <x-ui.select name="kategori" label="Kategori" hide-label :options="$kategori" placeholder="Semua kategori" />
    </x-ui.filter-bar>
--}}
@props(['action' => null, 'search' => 'cari', 'placeholder' => 'Cari…'])

@php
    $action ??= url()->current();
    $filters = collect(request()->except(['page', 'sort', 'dir']));
    $hasActiveFilter = $filters->contains(fn ($value) => filled($value));
@endphp

<form method="GET" action="{{ $action }}" role="search"
      {{ $attributes->class('flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center') }}>
    @if (request()->filled('sort'))
        <input type="hidden" name="sort" value="{{ request('sort') }}">
        <input type="hidden" name="dir" value="{{ request('dir') }}">
    @endif

    @if ($search)
        <div class="relative sm:min-w-[16rem] sm:max-w-sm sm:flex-1">
            <label for="filter-{{ $search }}" class="sr-only">{{ $placeholder }}</label>
            <x-ui.icon name="magnifying-glass" variant="m" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input type="search" id="filter-{{ $search }}" name="{{ $search }}" value="{{ request($search) }}"
                   placeholder="{{ $placeholder }}"
                   class="block w-full rounded-lg border-slate-300 pl-9 text-sm shadow-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-1 focus:ring-primary-500">
        </div>
    @endif

    {{ $slot }}

    <div class="flex items-center gap-2">
        <x-ui.button type="submit" variant="secondary" icon="funnel">Terapkan</x-ui.button>
        @if ($hasActiveFilter)
            <x-ui.button :href="$action" variant="ghost">Reset</x-ui.button>
        @endif
    </div>
</form>
