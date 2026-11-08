{{--
    Bagian formulir: judul + penjelasan di kiri, isian dalam kartu di kanan (bertumpuk di layar kecil).
    Slot footer (opsional) untuk tombol simpan.

    <x-ui.form-section title="Informasi produk" description="Nama dan kode yang tampil di kasir dan struk.">
        <x-ui.input name="name" label="Nama produk" required />
        <x-slot:footer><x-ui.button type="submit">Simpan</x-ui.button></x-slot:footer>
    </x-ui.form-section>
--}}
@props(['title', 'description' => null])

<section {{ $attributes->class('grid gap-4 lg:grid-cols-3 lg:gap-8') }}>
    <div>
        <h2 class="text-base font-semibold text-slate-900">{{ $title }}</h2>
        @if ($description)
            <p class="mt-1 text-sm text-slate-500">{{ $description }}</p>
        @endif
    </div>

    <div class="rounded-xl border border-slate-200 bg-white shadow-card lg:col-span-2">
        <div class="grid gap-5 p-5 sm:p-6">
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="flex flex-col-reverse gap-2 rounded-b-xl border-t border-slate-200 bg-slate-50/60 px-5 py-3 sm:flex-row sm:justify-end sm:px-6">
                {{ $footer }}
            </div>
        @endisset
    </div>
</section>
