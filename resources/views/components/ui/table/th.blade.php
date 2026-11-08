{{--
    Judul kolom. Beri sort="kolom" agar bisa diurutkan: tautan menambah ?sort=kolom&dir=asc|desc
    ke URL saat ini (filter lain tetap, halaman kembali ke 1). Klik kedua membalik arah.
    Controller yang membaca sort/dir wajib memakai daftar kolom yang diizinkan (whitelist).

    <x-ui.table.th sort="name">Nama</x-ui.table.th>
    <x-ui.table.th align="right">Aksi</x-ui.table.th>
--}}
@props(['sort' => null, 'align' => 'left'])

@php
    $isActive = $sort !== null && request()->query('sort') === $sort;
    $direction = request()->query('dir') === 'desc' ? 'desc' : 'asc';
    $nextDirection = $isActive && $direction === 'asc' ? 'desc' : 'asc';
    $url = $sort !== null
        ? request()->fullUrlWithQuery(['sort' => $sort, 'dir' => $nextDirection, 'page' => null])
        : null;
    $sortIcon = $isActive ? ($direction === 'asc' ? 'chevron-up' : 'chevron-down') : 'chevron-up-down';
    $sortIconClass = $isActive ? 'h-4 w-4 text-slate-600' : 'h-4 w-4 text-slate-300 group-hover:text-slate-400';
@endphp

<th scope="col"
    @if ($isActive) aria-sort="{{ $direction === 'asc' ? 'ascending' : 'descending' }}" @endif
    {{ $attributes->class([
        'whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500',
        'text-left' => $align === 'left',
        'text-right' => $align === 'right',
        'text-center' => $align === 'center',
    ]) }}>
    @if ($url)
        <a href="{{ $url }}" @class([
            'group inline-flex items-center gap-1 rounded hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500',
            'flex-row-reverse' => $align === 'right',
            'text-slate-900' => $isActive,
        ])>
            {{ $slot }}
            <x-ui.icon :name="$sortIcon" variant="m" :class="$sortIconClass" />
        </a>
    @else
        {{ $slot }}
    @endif
</th>
