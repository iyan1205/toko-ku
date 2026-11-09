{{-- Struk & Printer. Pratinjau di kanan dimuat ulang (iframe) setiap isian berubah, sebelum disimpan. --}}
@php
    $bolehUbah = auth()->user()->can('settings.receipts.update');
    $awal = [
        'paper_width' => (int) old('paper_width', $receipt['paper_width']),
        'footer' => (string) old('footer', $receipt['footer']),
        'show_logo' => (bool) old('show_logo', $receipt['show_logo']),
        'show_npwp' => (bool) old('show_npwp', $receipt['show_npwp']),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">Struk &amp; Printer</x-slot>
    <x-slot name="description">Tampilan struk pelanggan dan cara mencetaknya. Identitas toko diatur di Profil Toko.</x-slot>

    <div class="grid gap-8 xl:grid-cols-[minmax(0,1fr)_22rem]"
         x-data="{
             ...@js($awal),
             url: '',
             muat() {
                 const q = new URLSearchParams({
                     paper_width: this.paper_width,
                     footer: this.footer,
                     show_logo: this.show_logo ? 1 : 0,
                     show_npwp: this.show_npwp ? 1 : 0,
                 });
                 this.url = @js(route('settings.receipts.preview')) + '?' + q.toString();
             },
         }"
         x-init="muat(); $watch('paper_width', () => muat()); $watch('show_logo', () => muat()); $watch('show_npwp', () => muat())">

        <form method="POST" action="{{ route('settings.receipts.update') }}" class="grid content-start gap-8">
            @csrf
            @method('PUT')

            <fieldset @disabled(! $bolehUbah) class="grid gap-8">
                <x-ui.form-section title="Kertas" description="Samakan dengan printer thermal yang dipakai supaya teks tidak terpotong.">
                    <fieldset>
                        <legend class="text-sm font-medium text-slate-700">Lebar kertas</legend>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach ($widths as $lebar)
                                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 transition-colors hover:bg-slate-50 has-[:checked]:border-primary-500 has-[:checked]:bg-primary-50/60">
                                    <input type="radio" name="paper_width" value="{{ $lebar }}" x-model.number="paper_width" @checked($awal['paper_width'] === $lebar)
                                           class="mt-0.5 border-slate-300 text-primary-600 focus:ring-primary-500">
                                    <span>
                                        <span class="block text-sm font-medium text-slate-900">{{ $lebar }} mm</span>
                                        <span class="block text-xs text-slate-500">{{ $lebar === 58 ? 'Printer mini / kasir genggam (paling umum)' : 'Printer thermal lebih lebar' }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('paper_width')
                            <p class="mt-1.5 text-sm text-danger-600">{{ $message }}</p>
                        @enderror
                    </fieldset>
                </x-ui.form-section>

                <x-ui.form-section title="Isi struk" description="Bagian yang tercetak di struk.">
                    <x-ui.textarea name="footer" label="Footer struk" :value="$receipt['footer']" rows="3" maxlength="255"
                                   hint="Boleh beberapa baris. Kosongkan untuk memakai “Terima kasih telah berbelanja”."
                                   x-model="footer" x-on:input.debounce.500ms="muat()" />

                    <x-ui.checkbox name="show_logo" label="Tampilkan logo toko" :checked="$receipt['show_logo']" unchecked-value="0" x-model="show_logo"
                                   hint="{{ $profile['logo_url'] ? 'Hanya untuk cetak lewat browser; cetak Bluetooth (RawBT) mencetak teks saja.' : 'Belum ada logo. Unggah dulu di Profil Toko.' }}" />
                    <x-ui.checkbox name="show_npwp" label="Tampilkan NPWP toko" :checked="$receipt['show_npwp']" unchecked-value="0" x-model="show_npwp"
                                   hint="{{ $profile['npwp'] ? 'NPWP '.$profile['npwp'] : 'NPWP belum diisi di Profil Toko.' }}" />
                </x-ui.form-section>

                <x-ui.form-section title="Pencetakan" description="Struk dicetak dari browser di perangkat kasir. Server tidak perlu terhubung ke printer.">
                    <x-ui.checkbox name="auto_print" label="Buka dialog cetak otomatis saat struk dibuka" :checked="$receipt['auto_print']" unchecked-value="0"
                                   hint="Matikan bila struk lebih sering dibuka untuk dilihat daripada dicetak." />

                    @if ($bolehUbah)
                        <x-slot:footer>
                            <x-ui.button type="submit">Simpan pengaturan struk</x-ui.button>
                        </x-slot:footer>
                    @endif
                </x-ui.form-section>
            </fieldset>
        </form>

        <aside class="xl:sticky xl:top-20 xl:self-start">
            <x-ui.card title="Pratinjau" description="Data contoh. Belum disimpan sampai Anda menekan Simpan." :padding="false">
                <div class="flex justify-center bg-slate-100 p-4">
                    <iframe x-bind:src="url" title="Pratinjau struk"
                            x-bind:style="`width: ${paper_width === 80 ? 340 : 260}px`"
                            class="h-[34rem] max-w-full rounded border-0 bg-white"></iframe>
                </div>
            </x-ui.card>
        </aside>
    </div>
</x-app-layout>
