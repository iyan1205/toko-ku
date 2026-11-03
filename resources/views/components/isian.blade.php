@props(['label', 'name', 'type' => 'text', 'value' => null, 'hint' => null, 'wajib' => false])

<div {{ $attributes->only('class') }}>
    <label for="{{ $name }}" class="label-isian">
        {{ $label }}
        @unless ($wajib)
            <span class="font-normal text-slate-400">(opsional)</span>
        @endunless
    </label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
           @if (! in_array($type, ['password', 'file'])) value="{{ old($name, $value) }}" @endif
           @required($wajib)
           {{ $attributes->except('class') }}
           @class(['isian mt-1', 'isian-salah' => $errors->has($name)])>
    @if ($hint)
        <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
    @error($name)
        <p class="mt-1 text-sm text-amber-600">{{ $message }}</p>
    @enderror
</div>