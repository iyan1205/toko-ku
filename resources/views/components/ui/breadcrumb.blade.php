{{--
    Jejak navigasi. Ikon rumah selalu mengarah ke Dashboard. Item terakhir = halaman saat ini.

    <x-ui.breadcrumb :items="[
        ['label' => 'Inventori'],
        ['label' => 'Produk', 'href' => route('products.index')],
        ['label' => 'Ayam Geprek'],
    ]" />
--}}
@props(['items' => []])

<nav aria-label="Breadcrumb" {{ $attributes }}>
    <ol class="flex flex-wrap items-center gap-1.5 text-sm text-slate-500">
        <li class="flex items-center">
            <a href="{{ route('dashboard') }}" class="rounded text-slate-400 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                <x-ui.icon name="home" variant="m" class="h-4 w-4" />
                <span class="sr-only">Dashboard</span>
            </a>
        </li>
        @foreach ($items as $item)
            <li class="flex min-w-0 items-center gap-1.5">
                <x-ui.icon name="chevron-right" variant="m" class="h-4 w-4 text-slate-300" />
                @if ($loop->last)
                    <span class="truncate font-medium text-slate-700" aria-current="page">{{ $item['label'] }}</span>
                @elseif (! empty($item['href']))
                    <a href="{{ $item['href'] }}" class="truncate rounded hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">{{ $item['label'] }}</a>
                @else
                    <span class="truncate">{{ $item['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
