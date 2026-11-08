{{--
    Isian teks panjang.

    <x-ui.textarea name="description" label="Deskripsi" rows="4" hint="Tampil di detail produk." />
--}}
@props([
    'name',
    'label' => null,
    'value' => null,
    'hint' => null,
    'required' => false,
    'hideLabel' => false,
    'rows' => 3,
])

@php
    $key = trim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.');
    $id = $attributes->get('id') ?? 'f-'.str_replace('.', '-', $key);
    $error = ($errors ?? new \Illuminate\Support\ViewErrorBag)->first($key) ?: null;
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp

<x-ui.field :for="$id" :label="$label" :required="$required" :hint="$hint" :error="$error" :hide-label="$hideLabel" {{ $attributes->only('class') }}>
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}"
              @required($required)
              @if ($error) aria-invalid="true" @endif
              @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
              {{ $attributes->except(['class', 'id']) }}
              @class([
                  'block w-full rounded-lg border text-sm shadow-sm placeholder:text-slate-400 focus:outline-none focus:ring-1',
                  'disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500',
                  'border-slate-300 focus:border-primary-500 focus:ring-primary-500' => ! $error,
                  'border-danger-400 focus:border-danger-500 focus:ring-danger-500' => $error,
              ])>{{ old($key, $value) }}</textarea>
</x-ui.field>
