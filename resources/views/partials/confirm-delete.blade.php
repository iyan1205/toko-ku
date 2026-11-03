{{--
    Dialog konfirmasi hapus, dipakai bersama. Buka dari tombol mana pun dengan:
    @click="$dispatch('konfirmasi-hapus', { action: '{{ route(...) }}', nama: '...' })"
--}}
<div x-data="{ buka: false, action: '', nama: '' }"
     x-on:konfirmasi-hapus.window="buka = true; action = $event.detail.action; nama = $event.detail.nama"
     x-on:keydown.escape.window="buka = false"
     x-show="buka" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/60 p-4"
     role="dialog" aria-modal="true" aria-labelledby="judul-hapus">
    <div @click.outside="buka = false" class="w-full max-w-md rounded-card bg-white p-6 shadow-float">
        <h2 id="judul-hapus" class="text-lg font-semibold text-stone-900">Hapus data?</h2>
        <p class="mt-2 text-sm text-stone-600">
            <span class="font-medium" x-text="nama"></span> akan dihapus. Riwayat transaksi yang terkait tetap tersimpan.
        </p>
        <form method="POST" :action="action" class="mt-6 flex justify-end gap-2">
            @csrf
            @method('DELETE')
            <button type="button" @click="buka = false" class="tombol-kedua">Batal</button>
            <button type="submit" class="tombol-bahaya">Ya, hapus</button>
        </form>
    </div>
</div>
