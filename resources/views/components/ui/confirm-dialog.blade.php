{{--
    Dialog konfirmasi sebelum aksi berbahaya. Pasang SEKALI di layout. Memakai event yang sama
    dengan partials/confirm-delete lama, jadi tombol hapus yang sudah ada langsung bekerja:

        $dispatch('konfirmasi-hapus', { action: '{{ route(...) }}', nama: 'Ayam Geprek' })

    Opsional di detail event: judul, pesan, tombol (teks tombol), method (bawaan DELETE).
    Prop event hanya untuk halaman demo yang perlu nama event lain.
--}}
@props(['event' => 'konfirmasi-hapus'])

<div x-data="{ buka: false, action: '', nama: '', judul: '', pesan: '', tombol: '', method: 'DELETE' }"
     x-on:{{ $event }}.window="
         action = $event.detail.action;
         nama = $event.detail.nama ?? '';
         judul = $event.detail.judul ?? 'Hapus data?';
         pesan = $event.detail.pesan ?? 'akan dihapus. Riwayat transaksi yang terkait tetap tersimpan.';
         tombol = $event.detail.tombol ?? 'Ya, hapus';
         method = $event.detail.method ?? 'DELETE';
         buka = true;
         $nextTick(() => $refs.batal.focus());
     "
     x-on:keydown.escape.window="buka = false"
     x-show="buka" x-cloak
     role="alertdialog" aria-modal="true" aria-labelledby="konfirmasi-judul" aria-describedby="konfirmasi-pesan"
     {{ $attributes->class('fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center') }}>
    <div x-show="buka" x-transition.opacity class="fixed inset-0 bg-slate-900/50" x-on:click="buka = false" aria-hidden="true"></div>

    <div x-show="buka" x-transition class="relative w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div class="flex gap-4">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-danger-100 text-danger-600">
                <x-ui.icon name="exclamation-triangle" class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <h2 id="konfirmasi-judul" class="text-base font-semibold text-slate-900" x-text="judul"></h2>
                <p id="konfirmasi-pesan" class="mt-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-900" x-text="nama"></span>
                    <span x-text="pesan"></span>
                </p>
            </div>
        </div>

        <form method="POST" x-bind:action="action" class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            @csrf
            <input type="hidden" name="_method" x-bind:value="method">
            <x-ui.button variant="secondary" x-ref="batal" x-on:click="buka = false">Batal</x-ui.button>
            <x-ui.button type="submit" variant="danger"><span x-text="tombol"></span></x-ui.button>
        </form>
    </div>
</div>
