<x-app-layout>
    <x-slot name="header">Profil Toko</x-slot>

    <form method="POST" action="{{ route('settings.update') }}" class="grid gap-6 lg:grid-cols-3">
        @csrf
        @method('PUT')

        {{-- ---------------- Kolom isian ---------------- --}}
        <div class="space-y-6 lg:col-span-2">
            <div class="kartu p-6">
                <h2 class="text-sm font-medium text-stone-600">Identitas toko</h2>
                <p class="mt-1 text-sm text-stone-500">Data ini tercetak di kepala struk pelanggan.</p>

                <div class="mt-4 space-y-5">
                    <x-isian label="Nama toko" name="nama_toko" :value="$profil['nama_toko']" :wajib="true" maxlength="100"
                             hint="Contoh: Dkriuk Jabong" />

                    <x-isian label="Nama pemilik" name="nama_pemilik" :value="$profil['nama_pemilik']" maxlength="100"
                             hint="Tercetak kecil di bawah nama toko. Kosongkan bila tidak ingin ditampilkan." />

                    <div>
                        <label for="alamat" class="block text-sm font-medium text-stone-700">
                            Alamat <span class="font-normal text-stone-400">(opsional)</span>
                        </label>
                        <textarea id="alamat" name="alamat" rows="2" maxlength="255"
                                  class="mt-1 block w-full rounded-md border-stone-300 text-sm shadow-sm focus:border-brand-600 focus:ring-brand-600 @error('alamat') border-brand-400 @enderror">{{ old('alamat', $profil['alamat']) }}</textarea>
                        @error('alamat') <p class="mt-1 text-sm text-brand-600">{{ $message }}</p> @enderror
                    </div>

                    <x-isian label="Telepon" name="telepon" type="tel" :value="$profil['telepon']" maxlength="25"
                             hint="Contoh: 0812-3456-7890" />
                </div>
            </div>

            <div class="kartu p-6">
                <h2 class="text-sm font-medium text-stone-600">Media sosial</h2>
                <p class="mt-1 text-sm text-stone-500">Cukup username-nya. Tanda @ otomatis dibuang.</p>

                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <x-isian label="Instagram" name="instagram" :value="$profil['instagram']" maxlength="60"
                             hint="Contoh: dkriukjabong" />
                    <x-isian label="TikTok" name="tiktok" :value="$profil['tiktok']" maxlength="60"
                             hint="Contoh: dkriukjabong" />
                </div>
            </div>

            <div class="kartu p-6">
                <h2 class="text-sm font-medium text-stone-600">Kebutuhan printer</h2>
                <p class="mt-1 text-sm text-stone-500">
                    Lebar kertas harus sama dengan printer thermal yang Anda pakai, supaya teks tidak terpotong saat dicetak.
                </p>

                <fieldset class="mt-4">
                    <legend class="text-sm font-medium text-stone-700">Lebar kertas struk</legend>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        @foreach ($lebarDidukung as $lebar)
                            <label class="flex cursor-pointer items-start gap-3 rounded-md border border-stone-300 p-4 hover:bg-stone-50 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
                                <input type="radio" name="lebar_struk_mm" value="{{ $lebar }}"
                                       @checked((int) old('lebar_struk_mm', $profil['lebar_struk_mm']) === $lebar)
                                       class="mt-0.5 border-stone-300 text-brand-700 focus:ring-brand-600">
                                <span>
                                    <span class="block text-sm font-medium text-stone-900">{{ $lebar }} mm</span>
                                    <span class="block text-xs text-stone-500">
                                        {{ $lebar === 58 ? 'Printer mini / kasir genggam (paling umum)' : 'Printer thermal lebih lebar' }}
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('lebar_struk_mm') <p class="mt-1 text-sm text-brand-600">{{ $message }}</p> @enderror
                </fieldset>

                <div class="mt-5">
                    <label for="footer_struk" class="block text-sm font-medium text-stone-700">
                        Footer struk <span class="font-normal text-stone-400">(opsional)</span>
                    </label>
                    <textarea id="footer_struk" name="footer_struk" rows="2" maxlength="255"
                              class="mt-1 block w-full rounded-md border-stone-300 text-sm shadow-sm focus:border-brand-600 focus:ring-brand-600 @error('footer_struk') border-brand-400 @enderror">{{ old('footer_struk', $profil['footer_struk']) }}</textarea>
                    <p class="mt-1 text-xs text-stone-500">Baris baru diizinkan. Contoh: "Terima kasih telah berbelanja".</p>
                    @error('footer_struk') <p class="mt-1 text-sm text-brand-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('dashboard') }}"
                   class="tombol-kedua">Batal</a>
                <button type="submit"
                        class="tombol-utama">
                    Simpan profil toko
                </button>
            </div>
        </div>

        {{-- ---------------- Pratinjau struk ---------------- --}}
        <div class="lg:col-span-1">
            <div class="sticky top-20 rounded-lg border border-stone-200 bg-white p-4">
                <h2 class="text-sm font-medium text-stone-600">Pratinjau struk</h2>
                <p class="mt-1 text-xs text-stone-500">Ikut berubah saat Anda mengetik.</p>

                <div class="mt-3 flex justify-center overflow-hidden rounded bg-stone-100 p-3">
                    <div id="pratinjau-struk"
                         class="w-[58mm] bg-white px-2 py-3 font-mono text-[10px] leading-snug text-black shadow-sm"
                         style="width: {{ $profil['lebar_struk_mm'] }}mm">
                        <div class="text-center">
                            <div id="p-nama" class="text-[12px] font-bold">{{ $profil['nama_toko'] }}</div>
                            <div id="p-pemilik" class="text-[9px] {{ $profil['nama_pemilik'] ? '' : 'hidden' }}">{{ $profil['nama_pemilik'] }}</div>
                            <div id="p-alamat" class="text-[9px] {{ $profil['alamat'] ? '' : 'hidden' }}">{{ $profil['alamat'] }}</div>
                            <div id="p-telepon" class="text-[9px] {{ $profil['telepon'] ? '' : 'hidden' }}">Telp {{ $profil['telepon'] }}</div>
                        </div>

                        <div class="my-1 border-t border-dashed border-black"></div>

                        <div class="flex justify-between"><span>No</span><span>POS-20261016-0001</span></div>
                        <div class="flex justify-between"><span>Tanggal</span><span>16-10-2026 12:30</span></div>
                        <div class="flex justify-between"><span>Kasir</span><span>Kasir</span></div>

                        <div class="my-1 border-t border-dashed border-black"></div>

                        <div>Ayam Geprek</div>
                        <div class="flex justify-between"><span>2 Porsi &times; 10.000</span><span>20.000</span></div>

                        <div class="my-1 border-t border-dashed border-black"></div>

                        <div class="flex justify-between"><span>Subtotal</span><span>20.000</span></div>
                        <div class="flex justify-between font-bold"><span>TOTAL</span><span>20.000</span></div>

                        <div class="my-1 border-t border-dashed border-black"></div>

                        <div class="flex justify-between"><span>Metode</span><span>Tunai</span></div>
                        <div class="flex justify-between"><span>Bayar</span><span>25.000</span></div>
                        <div class="flex justify-between"><span>Kembali</span><span>5.000</span></div>

                        <div class="my-1 border-t border-dashed border-black"></div>

                        <div class="text-center text-[9px]">
                            <div id="p-footer" class="whitespace-pre-line">{{ $profil['footer_struk'] }}</div>
                            <div id="p-sosmed" class="{{ ($profil['instagram'] || $profil['tiktok']) ? '' : 'hidden' }}">
                                <span id="p-ig" class="{{ $profil['instagram'] ? '' : 'hidden' }}">IG @{{ $profil['instagram'] }}</span>
                                <span id="p-tt" class="{{ $profil['tiktok'] ? '' : 'hidden' }}">TikTok @{{ $profil['tiktok'] }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <script>
        // Pratinjau langsung: tidak menyimpan apa pun, hanya mencerminkan isian form.
        (function () {
            const q = (id) => document.getElementById(id);
            const val = (name) => {
                const el = document.querySelector('[name="' + name + '"]');
                return el ? el.value.trim() : '';
            };
            // Elemen yang tidak ada cukup dilewati; JANGAN menghentikan sisa pembaruan,
            // supaya satu elemen yang dihapus tidak mematikan seluruh pratinjau.
            const tampil = (el, teks) => {
                if (!el) return;
                el.textContent = teks;
                el.classList.toggle('hidden', teks === '');
            };

            function perbarui() {
                tampil(q('p-nama'), val('nama_toko'));
                tampil(q('p-pemilik'), val('nama_pemilik'));
                tampil(q('p-alamat'), val('alamat'));
                tampil(q('p-telepon'), val('telepon') ? 'Telp ' + val('telepon') : '');
                tampil(q('p-footer'), val('footer_struk'));

                const ig = val('instagram').replace(/^@+/, '');
                const tt = val('tiktok').replace(/^@+/, '');
                tampil(q('p-ig'), ig ? 'IG @' + ig : '');
                tampil(q('p-tt'), tt ? 'TikTok @' + tt : '');
                const sosmed = q('p-sosmed');
                if (sosmed) sosmed.classList.toggle('hidden', !ig && !tt);

                const lebar = document.querySelector('[name="lebar_struk_mm"]:checked');
                const struk = q('pratinjau-struk');
                if (lebar && struk) struk.style.width = lebar.value + 'mm';
            }

            document.querySelectorAll('input[name="nama_toko"], input[name="nama_pemilik"], input[name="alamat"], input[name="telepon"], input[name="instagram"], input[name="tiktok"], textarea[name="footer_struk"], input[name="lebar_struk_mm"]')
                .forEach((el) => el.addEventListener('input', perbarui));
        })();
    </script>
</x-app-layout>
