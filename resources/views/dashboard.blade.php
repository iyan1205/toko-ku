<x-app-layout>
    <x-slot name="header">Dashboard</x-slot>

    @php
        $jam = (int) now()->format('G');
        $sapaan = $jam < 11 ? 'Selamat pagi' : ($jam < 15 ? 'Selamat siang' : ($jam < 18 ? 'Selamat sore' : 'Selamat malam'));
    @endphp

    {{-- Sambutan --}}
    <div class="kartu overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-4 bg-gradient-to-r from-slate-800 to-slate-900 p-6">
            <div>
                <h2 class="text-xl font-semibold text-white">{{ $sapaan }}, {{ auth()->user()->name }}</h2>
                <p class="mt-1 text-sm text-slate-200">
                    Anda masuk sebagai <span class="font-medium text-white">{{ auth()->user()->namaPeran() }}</span>
                    &middot; {{ \App\Support\Format::tanggal(now()) }}
                </p>
            </div>
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-amber-400 text-xl font-bold text-slate-900"
                  aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
        </div>
    </div>

    {{-- Modul --}}
    <div class="mt-6">
        <h2 class="judul-bagian">Modul yang bisa Anda buka</h2>

        @if ($menu->isEmpty())
            <p class="mt-3 rounded-xl border border-dashed border-slate-300 p-6 text-sm text-slate-500">
                Belum ada modul yang tersedia untuk peran Anda. Modul lain akan muncul di sini pada tahap pengembangan berikutnya.
            </p>
        @else
            <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($menu as $item)
                    <a href="{{ route($item['route']) }}"
                       class="kartu group flex items-center gap-3 p-4 transition-colors hover:border-slate-400 hover:bg-slate-50/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-slate-100 text-xl transition-colors group-hover:bg-white"
                              aria-hidden="true">{{ $item['icon'] }}</span>
                        <span class="text-sm font-medium text-slate-700">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>