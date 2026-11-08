{{-- Dashboard sementara: sapaan + pintasan modul sesuai hak akses. KPI dan grafik dibuat di Fase 4. --}}
@php
    $jam = (int) now()->format('G');
    $sapaan = $jam < 11 ? 'Selamat pagi' : ($jam < 15 ? 'Selamat siang' : ($jam < 18 ? 'Selamat sore' : 'Selamat malam'));
    $kelompok = $menu->groupBy(fn ($item) => $item['parent'] ?? 'Lainnya');
@endphp

<x-app-layout>
    <x-slot name="header">{{ $sapaan }}, {{ auth()->user()->name }}</x-slot>
    <x-slot name="description">
        Anda masuk sebagai {{ auth()->user()->namaPeran() }} · {{ \App\Support\Format::tanggal(now()) }}
    </x-slot>

    @if ($menu->isEmpty())
        <x-ui.card :padding="false">
            <x-ui.empty-state icon="squares-2x2" title="Belum ada modul untuk peran Anda"
                              description="Modul akan muncul di sini setelah administrator memberi hak akses." />
        </x-ui.card>
    @else
        <div class="grid gap-8">
            @foreach ($kelompok as $namaKelompok => $items)
                <section>
                    <h2 class="text-sm font-semibold text-slate-900">{{ $namaKelompok }}</h2>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach ($items as $item)
                            <a href="{{ $item['url'] }}"
                               class="group flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-card transition-colors hover:border-slate-300 hover:bg-slate-50/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary-50 text-primary-600">
                                    <x-ui.icon :name="$item['icon'] ?? 'squares-2x2'" class="h-5 w-5" />
                                </span>
                                <span class="text-sm font-medium text-slate-700 group-hover:text-slate-900">{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</x-app-layout>
