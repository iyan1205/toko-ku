{{-- Tambah / ubah pelanggan. Kode dibuat otomatis (CUS-0001). Saldo piutang tidak diisi manual. --}}
@php
    $edit = $customer->exists;
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah pelanggan' : 'Tambah pelanggan' }}</x-slot>
    <x-slot name="description">{{ $edit ? $customer->name.' · '.$customer->code : 'Kode pelanggan dibuat otomatis (CUS-0001, CUS-0002, dan seterusnya).' }}</x-slot>

    <form method="POST" action="{{ $edit ? route('customers.update', $customer) : route('customers.store') }}" class="grid gap-8">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <x-ui.form-section title="Identitas" description="Nama tampil di kasir, struk, dan daftar piutang.">
            <x-ui.input name="name" label="Nama pelanggan" :value="$customer->name" maxlength="150" required />
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input name="phone" label="Nomor HP" type="tel" :value="$customer->phone" maxlength="20" hint="Bisa dicari di layar Kasir." />
                <x-ui.input name="email" label="Email" type="email" :value="$customer->email" maxlength="255" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Alamat & catatan" description="Saldo piutang tidak diisi manual: bertambah dari penjualan piutang dan berkurang saat pelanggan membayar, jadi selalu ada riwayatnya.">
            <x-ui.textarea name="address" label="Alamat" :value="$customer->address" rows="2" maxlength="1000" />
            <x-ui.textarea name="note" label="Catatan" :value="$customer->note" rows="2" maxlength="1000" />

            <x-slot:footer>
                <x-ui.button :href="$edit ? route('customers.show', $customer) : route('customers.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit">{{ $edit ? 'Simpan perubahan' : 'Simpan pelanggan' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
