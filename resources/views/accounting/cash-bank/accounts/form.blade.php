{{-- Tambah / ubah rekening kas & bank. Akun bagan akun dipilih saat dibuat (atau dibuat otomatis) dan tidak bisa diganti. --}}
@use('App\Enums\CashBankKind')
@php
    $edit = $rekening->exists;
    $jenis = old('kind', $rekening->kind?->value ?? CashBankKind::Bank->value);
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah rekening '.$rekening->name : 'Tambah rekening' }}</x-slot>
    <x-slot name="description">{{ $edit ? $rekening->account?->label() : 'Kas laci, rekening bank, e-wallet, atau kas kecil dengan plafon.' }}</x-slot>

    <form method="POST" action="{{ $edit ? route('cash-bank-accounts.update', $rekening) : route('cash-bank-accounts.store') }}" class="grid gap-8" x-data="{ jenis: @js($jenis) }">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <x-ui.form-section title="Rekening" description="Nama tampil di pilihan rekening transaksi kas dan pelunasan.">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.select name="kind" label="Jenis" required x-model="jenis" :options="collect(CashBankKind::cases())->mapWithKeys(fn ($k) => [$k->value => $k->label()])" :selected="$jenis" />
                <x-ui.input name="name" label="Nama rekening" :value="$rekening->name" maxlength="100" required class="sm:col-span-2" placeholder="Misal BCA Operasional" />
            </div>
            <div class="grid gap-5 sm:grid-cols-3" x-show="jenis === 'bank'" x-cloak>
                <x-ui.input name="bank_name" label="Bank / e-wallet" :value="$rekening->bank_name" maxlength="100" placeholder="Misal BCA" />
                <x-ui.input name="account_number" label="Nomor rekening" :value="$rekening->account_number" maxlength="50" />
                <x-ui.input name="account_holder" label="Atas nama" :value="$rekening->account_holder" maxlength="100" />
            </div>
            <div class="grid gap-5 sm:grid-cols-3" x-show="jenis === 'petty_cash'" x-cloak>
                <x-ui.input name="imprest_amount" label="Plafon kas kecil (Rp)" type="number" min="0" step="0.01" :value="$rekening->imprest_amount"
                            hint="Saldo tetap kas kecil. Halaman Rekening menunjukkan kekurangannya dan tombol Isi ulang." />
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.select name="branch_id" label="Cabang" :options="$branches" :selected="$rekening->branch_id" placeholder="Semua cabang"
                             hint="Rekening khusus cabang hanya bisa dipilih di cabang itu." />
                @unless ($edit)
                    <x-ui.select name="account_id" label="Akun di bagan akun" :options="$accounts->mapWithKeys(fn ($a) => [$a->id => $a->code.' '.$a->name])" placeholder="Buat akun baru otomatis"
                                 hint="Pilih akun Aset yang sudah ada, atau biarkan kosong agar dibuatkan akun baru di bawah Aset Lancar." />
                @endunless
            </div>
            <x-ui.input name="note" label="Catatan" :value="$rekening->note" maxlength="255" />
            <x-ui.checkbox name="is_active" label="Rekening aktif" :checked="$rekening->is_active ?? true" unchecked-value="0"
                           hint="Rekening nonaktif tidak bisa dipilih di transaksi baru. Saldonya tetap terlihat." />

            <x-slot:footer>
                <x-ui.button :href="route('cash-bank-accounts.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit">{{ $edit ? 'Simpan perubahan' : 'Simpan rekening' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
