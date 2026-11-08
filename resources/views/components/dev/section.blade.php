{{-- Satu bagian di halaman /dev/ui-kit: judul, penjelasan, contoh, dan potongan kode pemakaian. --}}
@props(['id', 'title', 'description' => null])

<section id="{{ $id }}" class="scroll-mt-24">
    <div class="mb-4">
        <h2 class="text-lg font-semibold text-slate-900">{{ $title }}</h2>
        @if ($description)
            <p class="mt-1 text-sm text-slate-500">{{ $description }}</p>
        @endif
    </div>

    <div class="grid gap-4">
        {{ $slot }}

        @isset($code)
            <pre class="overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs leading-relaxed text-slate-100"><code>{{ $code }}</code></pre>
        @endisset
    </div>
</section>
