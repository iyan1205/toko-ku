{{--
    Kartu putih berborder tipis. Slot opsional: actions (kanan judul), footer.

    <x-ui.card title="Riwayat stok" description="5 mutasi terakhir">
        <x-slot:actions><x-ui.button variant="link" :href="...">Semua</x-ui.button></x-slot:actions>
        ...
    </x-ui.card>
    <x-ui.card :padding="false"> (isi menempel ke tepi, misal tabel) </x-ui.card>
--}}
@props(['title' => null, 'description' => null, 'padding' => true])

<div {{ $attributes->class('rounded-xl border border-slate-200 bg-white shadow-card') }}>
    @if ($title || isset($actions))
        <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
            <div class="min-w-0">
                @if ($title)
                    <h3 class="text-sm font-semibold text-slate-900">{{ $title }}</h3>
                @endif
                @if ($description)
                    <p class="mt-0.5 text-sm text-slate-500">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div @class(['p-5' => $padding])>
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="rounded-b-xl border-t border-slate-200 bg-slate-50/60 px-5 py-3">
            {{ $footer }}
        </div>
    @endisset
</div>
