@props(['label', 'name', 'type' => 'text', 'value' => null, 'hint' => null, 'wajib' => false])

<div {{ $attributes->only('class') }}>
    <label for="{{ $name }}" class="block text-sm font-medium text-stone-700">
        {{ $label }}
        @unless ($wajib)
            <span class="font-normal text-stone-400">(opsional)</span>
        @endunless
    </label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
           @if (! in_array($type, ['password', 'file'])) value="{{ old($name, $value) }}" @endif
           @required($wajib)
           {{ $attributes->except('class') }}
           class="mt-1 block w-full rounded-md border-stone-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600 @error($name) border-red-400 @enderror">
    @if ($hint)
        <p class="mt-1 text-xs text-stone-500">{{ $hint }}</p>
    @endif
    @error($name)
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
