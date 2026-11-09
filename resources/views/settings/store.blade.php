{{-- Profil toko: identitas di kepala struk dan dokumen. Lebar kertas & footer ada di Struk & Printer. --}}
<x-app-layout>
    <x-slot name="header">Profil Toko</x-slot>
    <x-slot name="description">Identitas toko yang tercetak di struk dan dokumen.</x-slot>

    @php($bolehUbah = auth()->user()->can('settings.store.update'))

    <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" class="grid gap-8">
        @csrf
        @method('PUT')

        <fieldset @disabled(! $bolehUbah) class="grid gap-8">
            <x-ui.form-section title="Identitas toko" description="Nama, alamat, dan kontak tercetak di kepala struk pelanggan.">
                <x-ui.input name="nama_toko" label="Nama toko" :value="$profil['nama_toko']" maxlength="100" required hint="Contoh: Dkriuk Jabong" />
                <x-ui.input name="nama_pemilik" label="Nama pemilik" :value="$profil['nama_pemilik']" maxlength="100"
                            hint="Tercetak kecil di bawah nama toko. Kosongkan bila tidak ingin ditampilkan." />
                <x-ui.textarea name="alamat" label="Alamat" :value="$profil['alamat']" rows="2" maxlength="255" />
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input name="telepon" label="Telepon" type="tel" :value="$profil['telepon']" maxlength="25" hint="Contoh: 0812-3456-7890" />
                    <x-ui.input name="email" label="Email" type="email" :value="$profil['email']" maxlength="100" />
                </div>
            </x-ui.form-section>

            <x-ui.form-section title="Pajak" description="NPWP toko untuk dokumen dan struk (bila ditampilkan di Struk & Printer).">
                <x-ui.input name="npwp" label="NPWP" :value="$profil['npwp']" maxlength="25" inputmode="numeric"
                            hint="15 digit (format lama) atau 16 digit (NIK). Contoh: 01.234.567.8-901.000" />
            </x-ui.form-section>

            <x-ui.form-section title="Logo" description="Tampil di struk bila diaktifkan di Struk & Printer. Gunakan gambar sederhana berlatar putih; printer thermal mencetaknya hitam-putih.">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start" x-data="{ pratinjau: null, hapus: false }">
                    <div class="grid h-24 w-24 shrink-0 place-items-center overflow-hidden rounded-lg border border-dashed border-slate-300 bg-slate-50">
                        <template x-if="pratinjau">
                            <img x-bind:src="pratinjau" alt="Pratinjau logo baru" class="max-h-full max-w-full object-contain">
                        </template>
                        @if ($profil['logo_url'])
                            <img x-show="! pratinjau && ! hapus" src="{{ $profil['logo_url'] }}" alt="Logo toko" class="max-h-full max-w-full object-contain">
                        @endif
                        <span x-show="! pratinjau && {{ $profil['logo_url'] ? 'hapus' : 'true' }}" @if ($profil['logo_url']) x-cloak @endif>
                            <x-ui.icon name="photo" class="h-8 w-8 text-slate-300" />
                        </span>
                    </div>

                    <div class="min-w-0 flex-1 space-y-3">
                        <x-ui.input name="logo" label="Unggah logo" type="file" accept="image/png,image/jpeg,image/webp"
                                    hint="PNG, JPG, atau WEBP, maksimal 1 MB."
                                    x-on:change="const f = $event.target.files[0]; pratinjau = f ? URL.createObjectURL(f) : null; if (f) hapus = false" />
                        @if ($profil['logo_url'])
                            <x-ui.checkbox name="hapus_logo" label="Hapus logo" x-model="hapus" />
                        @endif
                    </div>
                </div>
            </x-ui.form-section>

            <x-ui.form-section title="Media sosial" description="Cukup username-nya. Tanda @ otomatis dibuang.">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input name="instagram" label="Instagram" :value="$profil['instagram']" maxlength="60" prefix="@" hint="Contoh: dkriukjabong" />
                    <x-ui.input name="tiktok" label="TikTok" :value="$profil['tiktok']" maxlength="60" prefix="@" hint="Contoh: dkriukjabong" />
                </div>

                @if ($bolehUbah)
                    <x-slot:footer>
                        <x-ui.button type="submit">Simpan profil toko</x-ui.button>
                    </x-slot:footer>
                @endif
            </x-ui.form-section>
        </fieldset>
    </form>
</x-app-layout>
