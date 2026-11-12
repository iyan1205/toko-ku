{{-- Tambah / ubah kategori aset. --}}
@use('App\Enums\DepreciationMethod')
@php
    $edit = $category->exists;
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah kategori '.$category->name : 'Tambah kategori aset' }}</x-slot>
    <x-slot name="description">Umur ekonomis, metode penyusutan, dan akun yang dipakai aset kategori ini.</x-slot>

    <form method="POST" action="{{ $edit ? route('asset-categories.update', $category) : route('asset-categories.store') }}" class="grid gap-8"
          x-data="{ metode: @js(old('method', $category->method?->value ?? 'straight_line')) }">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif
        <x-ui.form-section title="Kategori" description="Saldo menurun: penyusutan = nilai buku x tarif tahunan / 12; di bulan terakhir umur ekonomis sisanya dihabiskan.">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="code" label="Kode" :value="$category->code" maxlength="20" required placeholder="Misal KLP1" />
                <x-ui.input name="name" label="Nama" :value="$category->name" maxlength="100" required class="sm:col-span-2" />
            </div>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="useful_life_months" label="Umur ekonomis (bulan)" type="number" min="1" max="600" :value="$category->useful_life_months" required hint="Misal 48 = 4 tahun." />
                <x-ui.select name="method" label="Metode" required x-model="metode" :options="collect(DepreciationMethod::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()])" :selected="$category->method?->value" />
                <div x-show="metode === 'declining_balance'" x-cloak>
                    <x-ui.input name="declining_rate" label="Tarif per tahun (%)" type="number" min="0.01" max="100" step="0.01" :value="$category->declining_rate" />
                </div>
            </div>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.select name="asset_account_id" label="Akun aset" required :options="$assetAccounts" :selected="$category->asset_account_id" placeholder="Pilih akun" />
                <x-ui.select name="accumulated_account_id" label="Akun akumulasi penyusutan" required :options="$assetAccounts" :selected="$category->accumulated_account_id" placeholder="Pilih akun" />
                <x-ui.select name="expense_account_id" label="Akun beban penyusutan" required :options="$expenseAccounts" :selected="$category->expense_account_id" placeholder="Pilih akun" />
            </div>
            <x-ui.input name="description" label="Keterangan" :value="$category->description" maxlength="255" />
            <x-ui.checkbox name="is_active" label="Aktif" :checked="$category->is_active ?? true" unchecked-value="0" />
            <x-slot:footer>
                <x-ui.button :href="route('asset-categories.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit">{{ $edit ? 'Simpan perubahan' : 'Simpan kategori' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
