{{-- Tambah / ubah akun. Akun sistem (dipakai jurnal otomatis) hanya bisa diganti nama dan deskripsinya. --}}
@use('App\Enums\AccountType')
@use('App\Enums\NormalBalance')
@php
    $edit = $account->exists;
    $sistem = $edit && $account->is_system;
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah akun '.$account->code : 'Tambah akun' }}</x-slot>
    <x-slot name="description">{{ $edit ? $account->name : 'Akun baru di bagan akun. Kode mengikuti kelompoknya, misal 6-xxxx untuk beban.' }}</x-slot>

    <form method="POST" action="{{ $edit ? route('accounts.update', $account) : route('accounts.store') }}" class="grid gap-8">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        @if ($sistem)
            <x-ui.alert type="info">Akun sistem: dipakai jurnal otomatis, jadi kode, kelompok, dan statusnya tidak bisa diubah. Nama dan deskripsi tetap bisa disesuaikan.</x-ui.alert>
            <input type="hidden" name="code" value="{{ $account->code }}">
            <input type="hidden" name="type" value="{{ $account->type->value }}">
            <input type="hidden" name="is_active" value="1">
        @endif

        <x-ui.form-section title="Akun" description="Akun induk hanya untuk pengelompokan dan tidak bisa dipakai di jurnal.">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="code" label="Kode akun" :value="$account->code" maxlength="20" required :disabled="$sistem" placeholder="Misal 6-1800" />
                <x-ui.input name="name" label="Nama akun" :value="$account->name" maxlength="100" required class="sm:col-span-2" />
            </div>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.select name="type" label="Kelompok" required :disabled="$sistem" :options="collect(AccountType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])" :selected="$account->type?->value" />
                <x-ui.select name="normal_balance" label="Saldo normal" :options="collect(NormalBalance::cases())->mapWithKeys(fn ($b) => [$b->value => $b->label()])" :selected="$account->normal_balance?->value"
                             placeholder="Sesuai kelompok" hint="Ubah hanya untuk akun kontra, misal Retur Penjualan atau Akumulasi Penyusutan." />
                <x-ui.select name="parent_id" label="Akun induk" :options="$parents->mapWithKeys(fn ($p) => [$p->id => $p->code.' '.$p->name.' ('.$p->type->label().')'])" :selected="$account->parent_id" placeholder="Tanpa induk" />
            </div>
            <x-ui.input name="description" label="Deskripsi" :value="$account->description" maxlength="255" />
        </x-ui.form-section>

        <x-ui.form-section title="Status">
            @unless ($sistem)
                <x-ui.checkbox name="is_header" label="Akun induk (kelompok)" :checked="$account->is_header" unchecked-value="0"
                               :hint="$usedInJournals ? 'Akun ini sudah dipakai di jurnal, jadi tidak bisa dijadikan akun induk.' : 'Centang bila akun ini hanya mengelompokkan akun lain.'" />
                <x-ui.checkbox name="is_active" label="Akun aktif" :checked="$account->is_active ?? true" unchecked-value="0"
                               hint="Akun nonaktif tidak bisa dipilih di jurnal baru. Jurnal lama tidak berubah." />
            @endunless

            <x-slot:footer>
                <x-ui.button :href="route('accounts.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit">{{ $edit ? 'Simpan perubahan' : 'Simpan akun' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
