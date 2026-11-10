{{--
    Dialog pembatalan (void) dokumen dengan isian alasan wajib (min. 5 karakter, field `alasan`).
    Buka dengan $dispatch('open-modal', name). Terbuka lagi otomatis bila alasan gagal divalidasi.

    <x-ui.button variant="danger" x-data x-on:click="$dispatch('open-modal', 'batal-transfer')">Batalkan</x-ui.button>
    <x-ui.void-dialog name="batal-transfer" :action="route('stock-transfers.void', $transfer)" title="Batalkan TRF-..?">
        Stok dikembalikan ke gudang asal. Dokumen tidak dihapus.
    </x-ui.void-dialog>
--}}
@props(['name', 'action', 'title', 'confirm' => 'Ya, batalkan', 'label' => 'Alasan pembatalan'])

{{-- _dialog: bila beberapa dialog ada di satu halaman, hanya dialog yang dikirim yang terbuka lagi. --}}
<x-ui.modal :name="$name" :title="$title" :show="$errors->has('alasan') && old('_dialog', $name) === $name">
    <form id="form-{{ $name }}" method="POST" action="{{ $action }}" class="grid gap-4">
        @csrf
        <input type="hidden" name="_dialog" value="{{ $name }}">
        <div class="text-sm text-slate-600">{{ $slot }}</div>
        <x-ui.textarea name="alasan" :id="'alasan-'.$name" :label="$label" rows="3" maxlength="255" required
                       hint="Minimal 5 karakter. Tercatat di dokumen dan log aktivitas." />
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="show = false">Kembali</x-ui.button>
        <x-ui.button type="submit" variant="danger" :form="'form-'.$name">{{ $confirm }}</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
