{{--
    Pembungkus label + teks bantuan + pesan error. Dipakai oleh x-ui.input, x-ui.select, x-ui.textarea.
    Kolom yang tidak wajib diberi tanda "(opsional)", sama seperti x-isian lama.
--}}
@props([
    'for',
    'label' => null,
    'required' => false,
    'hint' => null,
    'error' => null,
    'hideLabel' => false,
])

<div {{ $attributes }}>
    @if ($label)
        <label for="{{ $for }}" @class(['block text-sm font-medium text-slate-700', 'sr-only' => $hideLabel])>
            {{ $label }}
            @unless ($required)
                <span class="font-normal text-slate-400">(opsional)</span>
            @endunless
        </label>
    @endif

    <div @class(['mt-1.5' => $label && ! $hideLabel])>
        {{ $slot }}
    </div>

    @if ($hint)
        <p id="{{ $for }}-hint" class="mt-1.5 text-xs text-slate-500">{{ $hint }}</p>
    @endif

    @if ($error)
        <p id="{{ $for }}-error" class="mt-1.5 text-sm text-danger-600">{{ $error }}</p>
    @endif
</div>
