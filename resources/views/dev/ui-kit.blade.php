<x-app-layout>
    <x-slot name="header">UI Kit</x-slot>

    @php
        $sections = [
            'warna' => 'Warna', 'tombol' => 'Tombol', 'badge' => 'Badge', 'alert' => 'Alert & Toast',
            'form' => 'Formulir', 'kartu' => 'Kartu & KPI', 'tabel' => 'Tabel', 'kosong' => 'Data kosong',
            'modal' => 'Modal & Konfirmasi', 'dropdown' => 'Dropdown', 'tabs' => 'Tabs',
            'navigasi' => 'Breadcrumb & Judul', 'skeleton' => 'Skeleton',
        ];

        $swatches = [
            'primary' => ['bg-primary-50', 'bg-primary-100', 'bg-primary-300', 'bg-primary-500', 'bg-primary-600', 'bg-primary-700', 'bg-primary-900'],
            'success' => ['bg-success-50', 'bg-success-100', 'bg-success-300', 'bg-success-500', 'bg-success-600', 'bg-success-700', 'bg-success-900'],
            'warning' => ['bg-warning-50', 'bg-warning-100', 'bg-warning-300', 'bg-warning-500', 'bg-warning-600', 'bg-warning-700', 'bg-warning-900'],
            'danger' => ['bg-danger-50', 'bg-danger-100', 'bg-danger-300', 'bg-danger-500', 'bg-danger-600', 'bg-danger-700', 'bg-danger-900'],
            'info' => ['bg-info-50', 'bg-info-100', 'bg-info-300', 'bg-info-500', 'bg-info-600', 'bg-info-700', 'bg-info-900'],
            'slate (netral)' => ['bg-slate-50', 'bg-slate-100', 'bg-slate-300', 'bg-slate-500', 'bg-slate-600', 'bg-slate-700', 'bg-slate-900'],
        ];

        $stockStatus = fn (array $p) => match (true) {
            $p['stock'] <= 0 => ['danger', 'Habis'],
            $p['stock'] <= $p['min_stock'] => ['warning', 'Menipis'],
            default => ['success', 'Aman'],
        };
    @endphp

    <div class="grid gap-8 lg:grid-cols-[12rem_minmax(0,1fr)]">
        {{-- Daftar isi --}}
        <nav class="hidden lg:block" aria-label="Daftar isi UI Kit">
            <ul class="sticky top-24 grid gap-1 text-sm">
                @foreach ($sections as $id => $label)
                    <li><a href="#{{ $id }}" class="block rounded-md px-3 py-1.5 text-slate-600 hover:bg-white hover:text-slate-900">{{ $label }}</a></li>
                @endforeach
            </ul>
        </nav>

        <div class="grid min-w-0 gap-12">
            <x-ui.alert type="info" title="Halaman internal pengembang">
                Hanya tampil di environment <code>local</code> untuk Super Admin. Komponen ada di
                <code>resources/views/components/ui</code>; contoh pemakaian ada di komentar tiap file.
            </x-ui.alert>

            {{-- ================= Warna ================= --}}
            <x-dev.section id="warna" title="Warna" description="Token di tailwind.config.js. Primary dipakai hemat untuk aksi utama; warna status hanya untuk status.">
                <div class="grid gap-3">
                    @foreach ($swatches as $nama => $kelas)
                        <div class="flex items-center gap-3">
                            <span class="w-28 shrink-0 text-sm font-medium text-slate-700">{{ $nama }}</span>
                            <div class="flex flex-1 gap-1.5">
                                @foreach ($kelas as $k)
                                    <span class="h-9 flex-1 rounded-md ring-1 ring-inset ring-slate-900/5 {{ $k }}" title="{{ $k }}"></span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                <p class="text-sm text-slate-500">Font: <span class="font-semibold text-slate-700">Inter</span> (400, 500, 600, 700), di-host sendiri lewat @fontsource/inter.</p>
            </x-dev.section>

            {{-- ================= Tombol ================= --}}
            <x-dev.section id="tombol" title="Tombol" description="Satu aksi utama (primary) per area. Tombol ikon saja wajib punya aria-label.">
                <x-ui.card>
                    <div class="grid gap-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.button>Simpan</x-ui.button>
                            <x-ui.button variant="secondary">Batal</x-ui.button>
                            <x-ui.button variant="danger" icon="trash">Hapus</x-ui.button>
                            <x-ui.button variant="ghost">Lainnya</x-ui.button>
                            <x-ui.button variant="link">Lihat semua</x-ui.button>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.button size="sm" icon="plus">Kecil</x-ui.button>
                            <x-ui.button icon="plus">Sedang</x-ui.button>
                            <x-ui.button size="lg" icon="plus">Besar</x-ui.button>
                            <x-ui.button variant="secondary" icon-right="chevron-down">Ikon kanan</x-ui.button>
                            <x-ui.button variant="secondary" icon="pencil-square" aria-label="Ubah" />
                            <x-ui.button variant="ghost" icon="ellipsis-vertical" aria-label="Menu lain" />
                            <x-ui.button disabled>Nonaktif</x-ui.button>
                            <x-ui.button :href="route('dashboard')" variant="secondary" icon="home">Tautan (a)</x-ui.button>
                        </div>
                    </div>
                </x-ui.card>
                <x-slot:code>&lt;x-ui.button type="submit"&gt;Simpan&lt;/x-ui.button&gt;
&lt;x-ui.button :href="route('products.create')" icon="plus"&gt;Tambah produk&lt;/x-ui.button&gt;
&lt;x-ui.button variant="danger" icon="trash"&gt;Hapus&lt;/x-ui.button&gt;
&lt;x-ui.button variant="ghost" icon="ellipsis-vertical" aria-label="Menu lain" /&gt;</x-slot:code>
            </x-dev.section>

            {{-- ================= Badge ================= --}}
            <x-dev.section id="badge" title="Badge" description="Status singkat: stok, pembayaran, persetujuan.">
                <x-ui.card>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.badge>Draft</x-ui.badge>
                        <x-ui.badge color="primary">Baru</x-ui.badge>
                        <x-ui.badge color="success">Lunas</x-ui.badge>
                        <x-ui.badge color="warning">Sebagian</x-ui.badge>
                        <x-ui.badge color="danger">Dibatalkan</x-ui.badge>
                        <x-ui.badge color="info">Diproses</x-ui.badge>
                        <x-ui.badge color="success" dot>Aman</x-ui.badge>
                        <x-ui.badge color="warning" dot>Menipis</x-ui.badge>
                        <x-ui.badge color="danger" dot>Habis</x-ui.badge>
                    </div>
                </x-ui.card>
                <x-slot:code>&lt;x-ui.badge color="success"&gt;Lunas&lt;/x-ui.badge&gt;
&lt;x-ui.badge color="warning" dot&gt;Menipis&lt;/x-ui.badge&gt;</x-slot:code>
            </x-dev.section>

            {{-- ================= Alert & Toast ================= --}}
            <x-dev.section id="alert" title="Alert & Toast" description="Alert menetap di halaman. Toast muncul sebentar di pojok kanan atas (dari session flash atau JavaScript).">
                <div class="grid gap-3">
                    <x-ui.alert type="info">Data stok diperbarui setiap kali ada transaksi.</x-ui.alert>
                    <x-ui.alert type="success" title="Penjualan tersimpan" dismissible>Struk siap dicetak.</x-ui.alert>
                    <x-ui.alert type="warning" title="Stok minus diizinkan">Penjualan tetap tersimpan walau stok habis.</x-ui.alert>
                    <x-ui.alert type="danger" title="Gagal menyimpan">Periksa kembali isian yang ditandai merah.</x-ui.alert>
                </div>
                <x-ui.card>
                    <div class="flex flex-wrap gap-2" x-data>
                        <x-ui.button variant="secondary" x-on:click="$dispatch('toast', { type: 'success', message: 'Produk tersimpan.' })">Toast sukses</x-ui.button>
                        <x-ui.button variant="secondary" x-on:click="$dispatch('toast', { type: 'danger', message: 'Stok tidak mencukupi.' })">Toast gagal</x-ui.button>
                        <x-ui.button variant="secondary" x-on:click="$dispatch('toast', { type: 'warning', message: 'Sesi kasir belum dibuka.' })">Toast peringatan</x-ui.button>
                        <x-ui.button variant="secondary" x-on:click="$dispatch('toast', { type: 'info', message: 'Ada 3 transaksi ditahan.' })">Toast info</x-ui.button>
                    </div>
                </x-ui.card>
                <x-slot:code>&lt;x-ui.alert type="warning" title="Judul"&gt;Isi pesan&lt;/x-ui.alert&gt;

// Toast dari controller:
return redirect()-&gt;route('products.index')-&gt;with('success', 'Produk tersimpan.');
// Toast dari Alpine:
$dispatch('toast', { type: 'success', message: 'Produk tersimpan.' })</x-slot:code>
            </x-dev.section>

            {{-- ================= Formulir ================= --}}
            <x-dev.section id="form" title="Formulir" description="Kolom tidak wajib otomatis bertanda (opsional). Pesan error dan nilai lama (old input) otomatis.">
                <x-ui.form-section title="Informasi produk" description="Nama dan kode yang tampil di kasir dan struk.">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.input name="contoh_nama" label="Nama produk" placeholder="Ayam Geprek" required />
                        <x-ui.input name="contoh_sku" label="SKU" hint="Kosongkan untuk dibuat otomatis." />
                        <x-ui.input name="contoh_harga" label="Harga jual" type="number" prefix="Rp" required />
                        <x-ui.input name="contoh_berat" label="Berat" type="number" suffix="gram" />
                        <x-ui.select name="contoh_kategori" label="Kategori" :options="$categories" placeholder="Pilih kategori" required />
                        <x-ui.input name="contoh_salah" label="Contoh isian dengan error" required />
                    </div>
                    <x-ui.textarea name="contoh_deskripsi" label="Deskripsi" rows="3" />
                    <div class="grid gap-3">
                        <x-ui.checkbox name="contoh_aktif" label="Produk aktif" hint="Produk nonaktif tidak tampil di kasir." checked />
                        <x-ui.checkbox name="contoh_pajak" label="Kena pajak (PPN)" />
                    </div>
                    <x-slot:footer>
                        <x-ui.button variant="secondary">Batal</x-ui.button>
                        <x-ui.button>Simpan produk</x-ui.button>
                    </x-slot:footer>
                </x-ui.form-section>
                <x-slot:code>&lt;x-ui.form-section title="Informasi produk" description="..."&gt;
    &lt;x-ui.input name="name" label="Nama produk" required /&gt;
    &lt;x-ui.input name="selling_price" label="Harga jual" type="number" prefix="Rp" required /&gt;
    &lt;x-ui.select name="category_id" label="Kategori" :options="$kategori" placeholder="Pilih kategori" required /&gt;
    &lt;x-ui.textarea name="description" label="Deskripsi" /&gt;
    &lt;x-ui.checkbox name="is_active" label="Produk aktif" :checked="$produk-&gt;is_active" unchecked-value="0" /&gt;
    &lt;x-slot:footer&gt;&lt;x-ui.button type="submit"&gt;Simpan&lt;/x-ui.button&gt;&lt;/x-slot:footer&gt;
&lt;/x-ui.form-section&gt;</x-slot:code>
            </x-dev.section>

            {{-- ================= Kartu & KPI ================= --}}
            <x-dev.section id="kartu" title="Kartu & KPI" description="Stat-card untuk angka ringkasan di dashboard.">
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <x-ui.stat-card label="Penjualan hari ini" value="Rp 1.250.000" icon="banknotes" trend="+12%" trend-direction="up" hint="dibanding kemarin" />
                    <x-ui.stat-card label="Transaksi" value="48" icon="shopping-cart" trend="-3" trend-direction="down" hint="dibanding kemarin" />
                    <x-ui.stat-card label="Stok menipis" value="8 produk" icon="exclamation-triangle" color="warning" trend="+2" trend-direction="up" :trend-good="false" />
                    <x-ui.stat-card label="Hutang jatuh tempo" value="Rp 3.400.000" icon="clock" color="danger" :href="route('dev.ui-kit')" hint="Klik untuk detail" />
                </div>
                <x-ui.card title="Transaksi terakhir" description="Kartu dengan judul, aksi, dan footer.">
                    <x-slot:actions><x-ui.button variant="link">Lihat semua</x-ui.button></x-slot:actions>
                    <p class="text-sm text-slate-600">Isi kartu.</p>
                    <x-slot:footer><p class="text-xs text-slate-500">Diperbarui 08 Nov 2026 14.30</p></x-slot:footer>
                </x-ui.card>
                <x-slot:code>&lt;x-ui.stat-card label="Penjualan hari ini" value="Rp 1.250.000" icon="banknotes" trend="+12%" trend-direction="up" /&gt;
&lt;x-ui.stat-card label="Stok menipis" value="8" color="warning" trend="+2" trend-direction="up" :trend-good="false" /&gt;
&lt;x-ui.card title="Judul" description="..."&gt; ... &lt;x-slot:actions&gt;...&lt;/x-slot:actions&gt; &lt;/x-ui.card&gt;</x-slot:code>
            </x-dev.section>

            {{-- ================= Tabel ================= --}}
            <x-dev.section id="tabel" title="Tabel" description="Coba cari, filter kategori, klik judul kolom untuk mengurutkan, dan pindah halaman. Data contoh, bukan dari database.">
                <x-ui.table :paginator="$products">
                    <x-slot:toolbar>
                        <x-ui.filter-bar :action="route('dev.ui-kit').'#tabel'" placeholder="Cari nama atau SKU">
                            <x-ui.select name="kategori" label="Kategori" hide-label :options="$categories" :selected="request('kategori')" placeholder="Semua kategori" />
                        </x-ui.filter-bar>
                    </x-slot:toolbar>
                    <x-slot:head>
                        <x-ui.table.th sort="name">Produk</x-ui.table.th>
                        <x-ui.table.th>Kategori</x-ui.table.th>
                        <x-ui.table.th sort="price" align="right">Harga</x-ui.table.th>
                        <x-ui.table.th sort="stock" align="right">Stok</x-ui.table.th>
                        <x-ui.table.th>Status</x-ui.table.th>
                        <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
                    </x-slot:head>
                    @forelse ($products as $p)
                        @php([$warnaStatus, $labelStatus] = $stockStatus($p))
                        <tr>
                            <x-ui.table.td>
                                <p class="font-medium text-slate-900">{{ $p['name'] }}</p>
                                <p class="text-xs text-slate-500">{{ $p['sku'] }}</p>
                            </x-ui.table.td>
                            <x-ui.table.td muted>{{ $p['category'] }}</x-ui.table.td>
                            <x-ui.table.td align="right" numeric>@rupiah($p['price'])</x-ui.table.td>
                            <x-ui.table.td align="right" numeric>{{ $p['stock'] }}</x-ui.table.td>
                            <x-ui.table.td><x-ui.badge :color="$warnaStatus" dot>{{ $labelStatus }}</x-ui.badge></x-ui.table.td>
                            <x-ui.table.td align="right">
                                <x-ui.button variant="ghost" size="sm" icon="pencil-square" aria-label="Ubah {{ $p['name'] }}" />
                            </x-ui.table.td>
                        </tr>
                    @empty
                        <x-ui.table.empty colspan="6" icon="magnifying-glass" title="Tidak ada produk yang cocok" description="Ubah kata kunci atau filter.">
                            <x-ui.button variant="secondary" :href="route('dev.ui-kit').'#tabel'">Reset filter</x-ui.button>
                        </x-ui.table.empty>
                    @endforelse
                </x-ui.table>
                <x-slot:code>&lt;x-ui.table :paginator="$produk"&gt;
    &lt;x-slot:toolbar&gt;&lt;x-ui.filter-bar placeholder="Cari produk"&gt; ...filter... &lt;/x-ui.filter-bar&gt;&lt;/x-slot:toolbar&gt;
    &lt;x-slot:head&gt;
        &lt;x-ui.table.th sort="name"&gt;Produk&lt;/x-ui.table.th&gt;
        &lt;x-ui.table.th sort="selling_price" align="right"&gt;Harga&lt;/x-ui.table.th&gt;
    &lt;/x-slot:head&gt;
    @@forelse ($produk as $p)
        &lt;tr&gt;&lt;x-ui.table.td&gt;...&lt;/x-ui.table.td&gt;&lt;x-ui.table.td align="right" numeric&gt;...&lt;/x-ui.table.td&gt;&lt;/tr&gt;
    @@empty
        &lt;x-ui.table.empty colspan="2" title="Belum ada produk" /&gt;
    @@endforelse
&lt;/x-ui.table&gt;

// Controller: urutkan hanya kolom yang diizinkan (whitelist), misal:
$sort = in_array($request-&gt;query('sort'), ['name', 'selling_price'], true) ? $request-&gt;query('sort') : 'name';</x-slot:code>
            </x-dev.section>

            {{-- ================= Data kosong ================= --}}
            <x-dev.section id="kosong" title="Data kosong" description="Saat belum ada data sama sekali, beri penjelasan dan aksi pertama.">
                <x-ui.card :padding="false">
                    <x-ui.empty-state icon="cube" title="Belum ada produk" description="Tambahkan produk pertama Anda atau impor dari Excel.">
                        <x-ui.button variant="secondary" icon="arrow-up-tray">Impor Excel</x-ui.button>
                        <x-ui.button icon="plus">Tambah produk</x-ui.button>
                    </x-ui.empty-state>
                </x-ui.card>
            </x-dev.section>

            {{-- ================= Modal & Konfirmasi ================= --}}
            <x-dev.section id="modal" title="Modal & Konfirmasi" description="Modal dibuka lewat event open-modal. Dialog konfirmasi memakai event konfirmasi-hapus yang sama dengan tombol hapus lama.">
                <x-ui.card>
                    <div class="flex flex-wrap gap-2" x-data>
                        <x-ui.button variant="secondary" x-on:click="$dispatch('open-modal', 'contoh-modal')">Buka modal</x-ui.button>
                        <x-ui.button variant="danger" icon="trash"
                                     x-on:click="$dispatch('konfirmasi-hapus-demo', { action: '#', nama: 'Ayam Geprek' })">Hapus (konfirmasi)</x-ui.button>
                    </div>
                </x-ui.card>

                <x-ui.modal name="contoh-modal" title="Tambah kategori" description="Kategori dipakai untuk mengelompokkan produk di kasir.">
                    <div class="grid gap-4">
                        <x-ui.input name="contoh_modal_nama" label="Nama kategori" required autofocus />
                        <x-ui.textarea name="contoh_modal_deskripsi" label="Deskripsi" rows="2" />
                    </div>
                    <x-slot:footer>
                        <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
                        <x-ui.button x-on:click="show = false; $dispatch('toast', { type: 'success', message: 'Demo: kategori tidak benar-benar disimpan.' })">Simpan</x-ui.button>
                    </x-slot:footer>
                </x-ui.modal>

                {{-- Demo: kirim formulir dicegah agar tidak ada request DELETE sungguhan. --}}
                <div x-data x-on:submit.capture.prevent="$dispatch('toast', { type: 'info', message: 'Demo: tidak ada data yang dihapus.' }); $el.querySelector('[x-ref=batal]')?.click()">
                    <x-ui.confirm-dialog event="konfirmasi-hapus-demo" />
                </div>

                <x-slot:code>&lt;x-ui.button x-on:click="$dispatch('open-modal', 'tambah-kategori')"&gt;Tambah&lt;/x-ui.button&gt;
&lt;x-ui.modal name="tambah-kategori" title="Tambah kategori"&gt; ... &lt;x-slot:footer&gt;...&lt;/x-slot:footer&gt; &lt;/x-ui.modal&gt;

// Konfirmasi hapus (dialog sudah terpasang di layout):
x-on:click="$dispatch('konfirmasi-hapus', { action: '...url destroy...', nama: 'Ayam Geprek' })"</x-slot:code>
            </x-dev.section>

            {{-- ================= Dropdown ================= --}}
            <x-dev.section id="dropdown" title="Dropdown">
                <x-ui.card>
                    <div class="flex flex-wrap gap-4">
                        <x-ui.dropdown align="left">
                            <x-slot:trigger>
                                <x-ui.button variant="secondary" icon-right="chevron-down" x-bind:aria-expanded="open">Aksi</x-ui.button>
                            </x-slot:trigger>
                            <x-ui.dropdown-item href="#tabel" icon="eye">Lihat detail</x-ui.dropdown-item>
                            <x-ui.dropdown-item href="#tabel" icon="pencil-square">Ubah</x-ui.dropdown-item>
                            <x-ui.dropdown-item href="#tabel" icon="arrow-down-tray">Ekspor Excel</x-ui.dropdown-item>
                            <div class="my-1 border-t border-slate-100"></div>
                            <x-ui.dropdown-item danger icon="trash">Hapus</x-ui.dropdown-item>
                        </x-ui.dropdown>
                    </div>
                </x-ui.card>
                <x-slot:code>&lt;x-ui.dropdown&gt;
    &lt;x-slot:trigger&gt;&lt;x-ui.button variant="secondary" icon-right="chevron-down" x-bind:aria-expanded="open"&gt;Aksi&lt;/x-ui.button&gt;&lt;/x-slot:trigger&gt;
    &lt;x-ui.dropdown-item :href="..." icon="pencil-square"&gt;Ubah&lt;/x-ui.dropdown-item&gt;
    &lt;x-ui.dropdown-item danger icon="trash"&gt;Hapus&lt;/x-ui.dropdown-item&gt;
&lt;/x-ui.dropdown&gt;</x-slot:code>
            </x-dev.section>

            {{-- ================= Tabs ================= --}}
            <x-dev.section id="tabs" title="Tabs" description="Panah kiri/kanan berpindah tab saat tab sedang difokus.">
                <x-ui.card>
                    <x-ui.tabs :tabs="['umum' => 'Umum', 'harga' => 'Harga & Stok', 'riwayat' => 'Riwayat']">
                        <x-ui.tab-panel name="umum"><p class="text-sm text-slate-600">Nama, kategori, satuan, dan foto produk.</p></x-ui.tab-panel>
                        <x-ui.tab-panel name="harga"><p class="text-sm text-slate-600">Harga beli, harga jual, diskon, dan stok minimum.</p></x-ui.tab-panel>
                        <x-ui.tab-panel name="riwayat"><p class="text-sm text-slate-600">Kartu stok dan riwayat perubahan harga.</p></x-ui.tab-panel>
                    </x-ui.tabs>
                </x-ui.card>
                <x-slot:code>&lt;x-ui.tabs :tabs="['umum' =&gt; 'Umum', 'harga' =&gt; 'Harga &amp; Stok']"&gt;
    &lt;x-ui.tab-panel name="umum"&gt;...&lt;/x-ui.tab-panel&gt;
    &lt;x-ui.tab-panel name="harga"&gt;...&lt;/x-ui.tab-panel&gt;
&lt;/x-ui.tabs&gt;</x-slot:code>
            </x-dev.section>

            {{-- ================= Breadcrumb & Judul ================= --}}
            <x-dev.section id="navigasi" title="Breadcrumb & Judul halaman">
                <x-ui.card>
                    <div class="grid gap-4">
                        <x-ui.breadcrumb :items="[
                            ['label' => 'Inventori'],
                            ['label' => 'Produk', 'href' => '#navigasi'],
                            ['label' => 'Ayam Geprek'],
                        ]" />
                        <x-ui.page-header title="Produk" description="Kelola katalog, harga, dan stok minimum.">
                            <x-slot:actions>
                                <x-ui.button variant="secondary" icon="arrow-down-tray">Ekspor</x-ui.button>
                                <x-ui.button icon="plus">Tambah produk</x-ui.button>
                            </x-slot:actions>
                        </x-ui.page-header>
                    </div>
                </x-ui.card>
                <x-slot:code>&lt;x-ui.breadcrumb :items="[['label' =&gt; 'Inventori'], ['label' =&gt; 'Produk', 'href' =&gt; route('products.index')], ['label' =&gt; $produk-&gt;name]]" /&gt;
&lt;x-ui.page-header title="Produk" description="..."&gt;&lt;x-slot:actions&gt;...&lt;/x-slot:actions&gt;&lt;/x-ui.page-header&gt;</x-slot:code>
            </x-dev.section>

            {{-- ================= Skeleton ================= --}}
            <x-dev.section id="skeleton" title="Skeleton" description="Penanda sedang memuat, misal saat pencarian produk di kasir.">
                <div class="grid gap-4 md:grid-cols-3">
                    <x-ui.card><x-ui.skeleton lines="4" /></x-ui.card>
                    <x-ui.card><x-ui.skeleton type="avatar" /></x-ui.card>
                    <x-ui.skeleton type="card" />
                </div>
                <x-ui.card><x-ui.skeleton type="table" lines="4" /></x-ui.card>
            </x-dev.section>
        </div>
    </div>

    {{-- Layout lama belum memasang toast baru; dipasang di sini untuk demo (Fase 2 memindahkannya ke layout). --}}
    <x-ui.toast />
</x-app-layout>
