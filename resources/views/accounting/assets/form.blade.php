{{-- Catat aset tetap baru (atau aset lama dengan akumulasi penyusutannya). Dijurnal saat disimpan. --}}
@php
    $kategori = $categories->mapWithKeys(fn ($c) => [$c->id => ['umur' => $c->useful_life_months, 'aturan' => $c->ruleLabel()]]);
@endphp

<x-app-layout>
    <x-slot name="header">Catat aset tetap</x-slot>
    <x-slot name="description">Untuk cabang {{ $branchName }}. Harga, umur, dan akun tidak bisa diubah setelah disimpan; aset yang belum disusutkan bisa dihapus lalu dicatat ulang.</x-slot>

    @if ($categories->isEmpty())
        <x-ui.alert type="warning" class="mb-6">Belum ada kategori aset. Buat atau pasang kategori standar di <a href="{{ route('asset-categories.index') }}" class="font-medium underline">Aset Tetap → Kategori</a>.</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('fixed-assets.store') }}" class="grid gap-8"
          x-data="{ kategori: @js($kategori), pilih: @js((string) old('asset_category_id', '')), lama: @js((bool) old('opening_accumulated')), umur: @js((string) old('useful_life_months', '')),
                    ganti() { if (this.kategori[this.pilih]) this.umur = String(this.kategori[this.pilih].umur) } }">
        @csrf
        <x-ui.form-section title="Aset" description="Nama dan kategori menentukan akun aset, akumulasi, dan beban penyusutannya.">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="name" label="Nama aset" maxlength="150" required class="sm:col-span-2" placeholder="Misal Freezer Box 500 L" />
                <x-ui.select name="asset_category_id" label="Kategori" required x-model="pilih" x-on:change="ganti()" :options="$categories->pluck('name', 'id')" placeholder="Pilih kategori" />
            </div>
            <p class="text-xs text-slate-500" x-show="kategori[pilih]" x-text="kategori[pilih] ? 'Aturan: ' + kategori[pilih].aturan : ''"></p>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="acquisition_date" label="Tanggal perolehan" type="date" required :value="now()->format('Y-m-d')" :max="now()->format('Y-m-d')" />
                <x-ui.input name="acquisition_cost" label="Harga perolehan (Rp)" type="number" min="0" step="0.01" required />
                <x-ui.input name="residual_value" label="Nilai sisa (Rp)" type="number" min="0" step="0.01" value="0" hint="Perkiraan nilai di akhir umur. Biasanya 0." />
            </div>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="useful_life_months" label="Umur ekonomis (bulan)" type="number" min="1" max="600" x-model="umur" hint="Bawaan dari kategori." />
                <x-ui.select name="credit_account_id" label="Sumber dana" required class="sm:col-span-2" :options="$creditAccounts" placeholder="Pilih rekening, hutang, atau saldo awal"
                             hint="Untuk aset yang sudah dimiliki sebelum memakai aplikasi, pilih Ekuitas Saldo Awal." />
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Aset lama" description="Isi bila aset sudah disusutkan sebelum dicatat di sini. Jurnalnya bertanggal sehari sebelum mulai disusutkan.">
            <x-ui.checkbox name="aset_lama" label="Aset ini sudah disusutkan sebelumnya" x-model="lama" />
            <div class="grid gap-5 sm:grid-cols-3" x-show="lama" x-cloak>
                <x-ui.input name="opening_accumulated" label="Akumulasi penyusutan sampai saat ini (Rp)" type="number" min="0" step="0.01" />
                <x-ui.input name="depreciation_start" label="Mulai disusutkan di aplikasi" type="month" hint="Biasanya bulan pertama memakai aplikasi." />
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Lokasi & penanggung jawab">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="location" label="Lokasi" maxlength="150" placeholder="Misal Dapur belakang" />
                <x-ui.select name="employee_id" label="Penanggung jawab (karyawan)" :options="$employees" placeholder="Tidak ada" />
                <x-ui.input name="holder_name" label="Atau nama penanggung jawab" maxlength="100" />
            </div>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="serial_number" label="Nomor seri / plat" maxlength="100" />
                <x-ui.select name="cost_center_id" label="Cost center" :options="$costCenters" placeholder="Tanpa" hint="Penyusutannya masuk cost center ini." />
                <x-ui.input name="description" label="Keterangan" maxlength="255" />
            </div>
            <x-slot:footer>
                <x-ui.button :href="route('fixed-assets.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit" icon="check">Simpan &amp; jurnal</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
