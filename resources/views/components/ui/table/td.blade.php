{{--
    Sel tabel. numeric = angka sejajar (uang, jumlah). muted = teks abu.

    <x-ui.table.td align="right" numeric>@rupiah($p->selling_price)</x-ui.table.td>
--}}
@props(['align' => 'left', 'numeric' => false, 'muted' => false])

<td {{ $attributes->class([
    'px-4 py-3 align-middle',
    'text-right' => $align === 'right',
    'text-center' => $align === 'center',
    'tabular-nums whitespace-nowrap' => $numeric,
    'text-slate-500' => $muted,
    'text-slate-700' => ! $muted,
]) }}>{{ $slot }}</td>
