@props(['value'])

<label {{ $attributes->merge(['class' => 'label-isian']) }}>
    {{ $value ?? $slot }}
</label>
