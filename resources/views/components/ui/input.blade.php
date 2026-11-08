{{--
    Isian teks. Nilai lama (old input) dan pesan error otomatis, termasuk untuk nama array
    seperti items[0][quantity] (dicari sebagai items.0.quantity).

    <x-ui.input name="name" label="Nama produk" required />
    <x-ui.input name="selling_price" label="Harga jual" type="number" prefix="Rp" required />
    <x-ui.input name="cari" label="Cari" hide-label icon="magnifying-glass" placeholder="Cari produk" />
--}}
@props([
    'name',
    'label' => null,
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'required' => false,
    'hideLabel' => false,
    'prefix' => null,
    'suffix' => null,
    'icon' => null,
])

@php
    $key = trim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.');
    $id = $attributes->get('id') ?? 'f-'.str_replace('.', '-', $key);
    $error = ($errors ?? new \Illuminate\Support\ViewErrorBag)->first($key) ?: null;
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
    $keepsValue = ! in_array($type, ['password', 'file'], true);
@endphp

<x-ui.field :for="$id" :label="$label" :required="$required" :hint="$hint" :error="$error" :hide-label="$hideLabel" {{ $attributes->only('class') }}>
    <div class="relative flex">
        @if ($prefix)
            <span class="inline-flex items-center rounded-l-lg border border-r-0 border-slate-300 bg-slate-50 px-3 text-sm text-slate-500">{{ $prefix }}</span>
        @endif

        @if ($icon)
            <x-ui.icon :name="$icon" variant="m" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        @endif

        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
               @if ($keepsValue) value="{{ old($key, $value) }}" @endif
               @required($required)
               @if ($error) aria-invalid="true" @endif
               @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
               {{ $attributes->except(['class', 'id']) }}
               @class([
                   'block w-full min-w-0 border text-sm shadow-sm placeholder:text-slate-400 focus:outline-none focus:ring-1',
                   'disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500',
                   'border-slate-300 focus:border-primary-500 focus:ring-primary-500' => ! $error,
                   'border-danger-400 text-danger-900 focus:border-danger-500 focus:ring-danger-500' => $error,
                   'rounded-lg' => ! $prefix && ! $suffix,
                   'rounded-r-lg' => $prefix && ! $suffix,
                   'rounded-l-lg' => $suffix && ! $prefix,
                   'pl-9' => $icon,
               ])>

        @if ($suffix)
            <span class="inline-flex items-center rounded-r-lg border border-l-0 border-slate-300 bg-slate-50 px-3 text-sm text-slate-500">{{ $suffix }}</span>
        @endif
    </div>
</x-ui.field>
