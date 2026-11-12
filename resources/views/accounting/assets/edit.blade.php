{{-- Ubah keterangan aset (nilai keuangan tidak bisa diubah). --}}
<x-app-layout>
    <x-slot name="header">Ubah {{ $asset->code }}</x-slot>
    <x-slot name="description">Nama, lokasi, penanggung jawab, cost center, dan nomor seri. Harga dan umur tidak bisa diubah setelah dicatat.</x-slot>

    <form method="POST" action="{{ route('fixed-assets.update', $asset) }}" class="grid gap-8">
        @csrf
        @method('PUT')
        <x-ui.form-section title="Keterangan aset">
            <x-ui.input name="name" label="Nama aset" maxlength="150" required :value="$asset->name" />
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="location" label="Lokasi" maxlength="150" :value="$asset->location" />
                <x-ui.select name="employee_id" label="Penanggung jawab (karyawan)" :options="$employees" :selected="$asset->employee_id" placeholder="Tidak ada" />
                <x-ui.input name="holder_name" label="Atau nama penanggung jawab" maxlength="100" :value="$asset->holder_name" />
            </div>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input name="serial_number" label="Nomor seri / plat" maxlength="100" :value="$asset->serial_number" />
                <x-ui.select name="cost_center_id" label="Cost center" :options="$costCenters" :selected="$asset->cost_center_id" placeholder="Tanpa" hint="Berlaku untuk penyusutan berikutnya." />
                <x-ui.input name="description" label="Keterangan" maxlength="255" :value="$asset->description" />
            </div>
            <x-slot:footer>
                <x-ui.button :href="route('fixed-assets.show', $asset)" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit">Simpan perubahan</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
