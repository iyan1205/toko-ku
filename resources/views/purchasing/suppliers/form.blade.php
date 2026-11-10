{{-- Tambah / ubah supplier. Kode dibuat otomatis (SUP-0001). --}}
@php
    $edit = $supplier->exists;
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah supplier' : 'Tambah supplier' }}</x-slot>
    <x-slot name="description">{{ $edit ? $supplier->name.' · '.$supplier->code : 'Kode supplier dibuat otomatis.' }}</x-slot>

    <form method="POST" action="{{ $edit ? route('suppliers.update', $supplier) : route('suppliers.store') }}" class="grid gap-8">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <x-ui.form-section title="Identitas" description="Nama tampil di PO, faktur, dan laporan pembelian.">
            <x-ui.input name="name" label="Nama supplier" :value="$supplier->name" maxlength="150" required />
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input name="contact_name" label="Nama kontak" :value="$supplier->contact_name" maxlength="100" />
                <x-ui.input name="phone" label="Nomor telepon" type="tel" :value="$supplier->phone" maxlength="20" />
                <x-ui.input name="email" label="Email" type="email" :value="$supplier->email" maxlength="255" />
                <x-ui.input name="npwp" label="NPWP" :value="$supplier->npwp" maxlength="30" hint="Angka, titik, dan strip." />
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Alamat & catatan">
            <x-ui.textarea name="address" label="Alamat" :value="$supplier->address" rows="2" maxlength="1000" />
            <x-ui.textarea name="note" label="Catatan" :value="$supplier->note" rows="2" maxlength="1000" hint="Misal jadwal kirim atau syarat pembayaran." />

            <x-slot:footer>
                <x-ui.button :href="$edit ? route('suppliers.show', $supplier) : route('suppliers.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit">{{ $edit ? 'Simpan perubahan' : 'Simpan supplier' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
