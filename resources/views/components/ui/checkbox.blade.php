{{--
    Kotak centang. Setelah validasi gagal, status centang mengikuti input lama (old input).
    Beri unchecked-value agar nilai tetap terkirim saat tidak dicentang (misal is_active = 0).
    Untuk pilihan ganda pakai nama berakhiran [] (misal permissions[]).

    <x-ui.checkbox name="is_active" label="Produk aktif" :checked="$produk->is_active" unchecked-value="0" />
    <x-ui.checkbox name="permissions[]" value="pos.transactions.create" label="Buat transaksi" />
--}}
@props([
    'name',
    'label',
    'value' => '1',
    'checked' => false,
    'uncheckedValue' => null,
    'hint' => null,
])

@php
    $multiple = str_ends_with($name, '[]');
    $key = trim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.');
    $id = $attributes->get('id') ?? 'f-'.str_replace('.', '-', $key).($multiple ? '-'.\Illuminate\Support\Str::slug((string) $value) : '');
    $error = ($errors ?? new \Illuminate\Support\ViewErrorBag)->first($key) ?: null;

    $isChecked = session()->hasOldInput()
        ? ($multiple
            ? in_array((string) $value, array_map('strval', (array) old($key, [])), true)
            : (string) old($key) === (string) $value)
        : (bool) $checked;
@endphp

<div {{ $attributes->only('class') }}>
    <div class="flex items-start gap-3">
        @if ($uncheckedValue !== null && ! $multiple)
            <input type="hidden" name="{{ $name }}" value="{{ $uncheckedValue }}">
        @endif

        <input id="{{ $id }}" name="{{ $name }}" type="checkbox" value="{{ $value }}"
               @checked($isChecked)
               @if ($hint) aria-describedby="{{ $id }}-hint" @endif
               {{ $attributes->except(['class', 'id']) }}
               class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-primary-600 focus:ring-primary-500 disabled:opacity-50">

        <div class="text-sm">
            <label for="{{ $id }}" class="font-medium text-slate-700">{{ $label }}</label>
            @if ($hint)
                <p id="{{ $id }}-hint" class="text-slate-500">{{ $hint }}</p>
            @endif
        </div>
    </div>

    @if ($error)
        <p class="mt-1.5 text-sm text-danger-600">{{ $error }}</p>
    @endif
</div>
