{{--
    Pilihan (select). Opsi dari array [nilai => teks], atau tulis <option> sendiri di slot.

    <x-ui.select name="category_id" label="Kategori" :options="$kategori->pluck('name', 'id')" placeholder="Pilih kategori" required />
    <x-ui.select name="stok" label="Status stok" hide-label :options="['aman' => 'Aman', 'habis' => 'Habis']" placeholder="Semua stok" />
--}}
@props([
    'name',
    'label' => null,
    'options' => [],
    'selected' => null,
    'placeholder' => null,
    'hint' => null,
    'required' => false,
    'hideLabel' => false,
])

@php
    $key = trim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.');
    $id = $attributes->get('id') ?? 'f-'.str_replace('.', '-', $key);
    $error = ($errors ?? new \Illuminate\Support\ViewErrorBag)->first($key) ?: null;
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));

    $current = old($key, $selected);
    $isSelected = fn ($value) => is_array($current)
        ? in_array((string) $value, array_map('strval', $current), true)
        : $current !== null && (string) $current === (string) $value;
@endphp

<x-ui.field :for="$id" :label="$label" :required="$required" :hint="$hint" :error="$error" :hide-label="$hideLabel" {{ $attributes->only('class') }}>
    <select id="{{ $id }}" name="{{ $name }}"
            @required($required)
            @if ($error) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except(['class', 'id']) }}
            @class([
                'block w-full rounded-lg border py-2 pl-3 pr-9 text-sm shadow-sm focus:outline-none focus:ring-1',
                'disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500',
                'border-slate-300 focus:border-primary-500 focus:ring-primary-500' => ! $error,
                'border-danger-400 focus:border-danger-500 focus:ring-danger-500' => $error,
            ])>
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif

        @if ($slot->isNotEmpty())
            {{ $slot }}
        @else
            @foreach ($options as $value => $text)
                <option value="{{ $value }}" @selected($isSelected($value))>{{ $text }}</option>
            @endforeach
        @endif
    </select>
</x-ui.field>
