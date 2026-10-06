<x-app-layout>
    <x-slot name="header">Dashboard</x-slot>

    <div class="rounded-lg border border-stone-200 bg-white p-6">
        <h2 class="text-xl font-semibold text-stone-900">Selamat datang, {{ auth()->user()->name }}</h2>
        <p class="mt-1 text-sm text-stone-600">
            Anda masuk sebagai <span class="font-medium">{{ auth()->user()->namaPeran() }}</span>.
            Hari ini {{ \App\Support\Format::tanggal(now()) }}.
        </p>
    </div>

    <div class="mt-6">
        <h2 class="text-sm font-medium text-stone-600">Modul yang bisa Anda buka</h2>

        @if ($menu->isEmpty())
            <p class="mt-3 rounded-lg border border-dashed border-stone-300 p-6 text-sm text-stone-500">
                Belum ada modul yang tersedia untuk peran Anda. Modul lain akan muncul di sini pada tahap pengembangan berikutnya.
            </p>
        @else
            <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($menu as $item)
                    <a href="{{ route($item['route']) }}"
                       class="flex items-center gap-3 rounded-lg border border-stone-200 bg-white p-4 text-sm font-medium text-stone-800 hover:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600">
                        <span class="text-2xl" aria-hidden="true">{{ $item['icon'] }}</span>
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    @can('view-dashboard')
        <p class="mt-6 rounded-lg border border-dashed border-stone-300 p-6 text-sm text-stone-500">
            Ringkasan penjualan, stok, dan keuntungan akan tampil di sini setelah modul terkait selesai dibangun.
        </p>
    @endcan
</x-app-layout>
