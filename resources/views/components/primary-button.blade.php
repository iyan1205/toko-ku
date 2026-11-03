<button {{ $attributes->merge(['type' => 'submit', 'class' => 'tombol-utama']) }}>
    {{ $slot }}
</button>
