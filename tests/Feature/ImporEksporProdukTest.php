<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Exceptions\ImportGagalException;
use App\Exports\ProductsExport;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Services\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class ImporEksporProdukTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private Category $kategori;
    private Unit $satuan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->kategori = Category::factory()->create(['name' => 'Makanan']);
        $this->satuan = Unit::factory()->create(['name' => 'PCS']);
    }

    private function baris(array $ubah = []): array
    {
        return array_merge([
            'SKU' => 'AYM-001',
            'Barcode' => '8999999000011',
            'Nama Produk' => 'Ayam Geprek',
            'Kategori' => 'Makanan',
            'Satuan' => 'PCS',
            'Harga Beli' => 8000,
            'Harga Jual' => 10000,
            'Stok Minimum' => 5,
            'Diskon (%)' => 0,
            'Status' => 'Aktif',
            'Deskripsi' => null,
        ], $ubah);
    }

    private function impor(array $baris): array
    {
        return app(ProductImportService::class)->impor(collect($baris));
    }

    private function galat(array $baris): array
    {
        try {
            $this->impor($baris);
        } catch (ImportGagalException $e) {
            return $e->daftar();
        }

        $this->fail('Seharusnya melempar ImportGagalException.');
    }

    // ---------------- Layanan impor ----------------

    public function test_impor_membuat_produk_baru_dan_sku_kosong_dibuat_otomatis(): void
    {
        $hasil = $this->impor([
            $this->baris(),
            $this->baris(['SKU' => '', 'Barcode' => null, 'Nama Produk' => 'Nasi Putih', 'Kategori' => 'makanan', 'Satuan' => 'pcs', 'Harga Beli' => 3000, 'Harga Jual' => 5000]),
        ]);

        $this->assertSame(['dibuat' => 2, 'diperbarui' => 0], $hasil);
        $this->assertSame('PRD-00001', Product::where('name', 'Nasi Putih')->value('sku'));

        $ayam = Product::where('sku', 'AYM-001')->firstOrFail();
        $this->assertSame($this->kategori->id, $ayam->category_id);
        $this->assertSame(10000.0, (float) $ayam->selling_price);
        $this->assertSame(0.0, (float) $ayam->stock);
    }

    public function test_impor_memperbarui_produk_dengan_sku_sama_tanpa_menyentuh_stok(): void
    {
        Product::factory()->stok(12)->create([
            'sku' => 'AYM-001', 'name' => 'Nama Lama', 'selling_price' => 1,
            'category_id' => $this->kategori->id, 'unit_id' => $this->satuan->id,
        ]);

        $hasil = $this->impor([$this->baris(['SKU' => 'aym-001', 'Nama Produk' => 'Ayam Geprek Baru'])]);

        $this->assertSame(['dibuat' => 0, 'diperbarui' => 1], $hasil);
        $this->assertSame(1, Product::count());

        $produk = Product::firstOrFail();
        $this->assertSame('Ayam Geprek Baru', $produk->name);
        $this->assertSame(10000.0, (float) $produk->selling_price);
        $this->assertSame(12.0, (float) $produk->stock);
    }

    public function test_satu_baris_salah_membatalkan_seluruh_impor(): void
    {
        $daftar = $this->galat([
            $this->baris(),
            $this->baris(['SKU' => 'AYM-002', 'Barcode' => null, 'Kategori' => 'Makannan']),
        ]);

        $this->assertContains('Baris 3: Kategori "Makannan" tidak ditemukan.', $daftar);
        $this->assertSame(0, Product::count());
    }

    public function test_harga_dengan_simbol_rupiah_ditolak_dengan_pesan_jelas(): void
    {
        $daftar = $this->galat([$this->baris(['Harga Beli' => 'Rp 8.000'])]);

        $this->assertStringContainsString('Baris 2: Harga Beli harus berupa angka biasa', implode("\n", $daftar));
    }

    public function test_sku_dan_barcode_ganda_di_dalam_berkas_ditolak(): void
    {
        $daftar = implode("\n", $this->galat([
            $this->baris(),
            $this->baris(['Nama Produk' => 'Salinan']),
        ]));

        $this->assertStringContainsString('SKU "AYM-001" muncul ganda', $daftar);
        $this->assertStringContainsString('Barcode "8999999000011" muncul ganda', $daftar);
        $this->assertSame(0, Product::count());
    }

    public function test_barcode_milik_produk_lain_ditolak(): void
    {
        Product::factory()->create(['sku' => 'LAIN-1', 'barcode' => '8999999000011']);

        $daftar = implode("\n", $this->galat([$this->baris()]));

        $this->assertStringContainsString('Barcode "8999999000011" sudah dipakai produk lain', $daftar);
    }

    public function test_sku_milik_produk_terhapus_ditolak(): void
    {
        Product::factory()->create(['sku' => 'AYM-001'])->delete();

        $daftar = implode("\n", $this->galat([$this->baris()]));

        $this->assertStringContainsString('dipakai produk yang sudah dihapus', $daftar);
    }

    public function test_kolom_opsional_boleh_tidak_ada_di_berkas(): void
    {
        $hasil = $this->impor([[
            'Nama Produk' => 'Roti Manis',
            'Kategori' => 'Makanan',
            'Satuan' => 'PCS',
            'Harga Beli' => 1000,
            'Harga Jual' => 2000,
        ]]);

        $this->assertSame(['dibuat' => 1, 'diperbarui' => 0], $hasil);

        $produk = Product::firstOrFail();
        $this->assertSame('PRD-00001', $produk->sku);
        $this->assertNull($produk->barcode);
        $this->assertTrue($produk->is_active);
        $this->assertSame(0.0, (float) $produk->discount_percent);
    }

    public function test_barcode_angka_dari_excel_dikembalikan_menjadi_teks_utuh(): void
    {
        $this->impor([$this->baris(['Barcode' => 8999999000011.0])]);

        $this->assertSame('8999999000011', Product::firstOrFail()->barcode);
    }

    public function test_kolom_wajib_yang_hilang_dan_berkas_kosong_ditolak(): void
    {
        $this->assertStringContainsString(
            'Kolom wajib tidak ditemukan',
            implode("\n", $this->galat([['Nama Produk' => 'Tanpa Kolom Lain']]))
        );

        $this->assertSame(['Berkas tidak berisi data produk.'], $this->galat([]));
        $this->assertSame(['Berkas tidak berisi data produk.'], $this->galat([['Nama Produk' => null, 'Kategori' => '']]));
    }

    // ---------------- Alur lewat HTTP ----------------

    public function test_admin_mengimpor_berkas_csv(): void
    {
        $csv = "SKU,Nama Produk,Kategori,Satuan,Harga Beli,Harga Jual\nCSV-001,Roti Tawar,Makanan,PCS,9000,12000\n";

        $this->actingAs($this->buatPengguna(RoleName::Admin))
            ->post(route('products.import.store'), ['file' => UploadedFile::fake()->createWithContent('produk.csv', $csv)])
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('products', ['sku' => 'CSV-001', 'name' => 'Roti Tawar']);
    }

    public function test_impor_csv_yang_salah_menampilkan_daftar_kesalahan_dan_tidak_menyimpan(): void
    {
        $csv = "SKU,Nama Produk,Kategori,Satuan,Harga Beli,Harga Jual\nCSV-001,Roti,Tidak Ada,PCS,9000,12000\n";

        $this->actingAs($this->buatPengguna(RoleName::Admin))
            ->post(route('products.import.store'), ['file' => UploadedFile::fake()->createWithContent('produk.csv', $csv)])
            ->assertSessionHas('import_errors')
            ->assertSessionHas('error');

        $this->assertSame(0, Product::count());
    }

    public function test_impor_menolak_jenis_berkas_lain(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Admin))
            ->post(route('products.import.store'), ['file' => UploadedFile::fake()->create('produk.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('file');
    }

    // ---------------- Ekspor ----------------

    public function test_ekspor_dan_templat_diunduh_admin(): void
    {
        Excel::fake();
        $this->actingAs($this->buatPengguna(RoleName::Admin));

        $this->get(route('products.export'))->assertOk();
        Excel::assertDownloaded('produk-' . now()->format('Ymd') . '.xlsx');

        $this->get(route('products.template'))->assertOk();
        Excel::assertDownloaded('template-impor-produk.xlsx');
    }

    public function test_isi_ekspor_mengikuti_filter_dan_memuat_semua_kolom(): void
    {
        Product::factory()->stok(3)->create([
            'name' => 'Zebra Cake', 'barcode' => '123456', 'sku' => 'ZBR-1',
            'category_id' => $this->kategori->id, 'unit_id' => $this->satuan->id,
        ]);
        Product::factory()->create(['name' => 'Lainnya']);

        $ekspor = new ProductsExport(['cari' => 'Zebra']);
        $baris = $ekspor->query()->get()->map(fn ($p) => $ekspor->map($p));

        $this->assertCount(1, $baris);
        $this->assertCount(count($ekspor->headings()), $baris->first());
        $this->assertSame('ZBR-1', $baris->first()[0]);
        $this->assertSame('123456', $baris->first()[1]);
        $this->assertSame('Makanan', $baris->first()[3]);
        $this->assertSame(3.0, $baris->first()[7]);
    }

    public function test_kasir_tidak_dapat_mengekspor_atau_mengimpor(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Kasir));

        $this->get(route('products.export'))->assertForbidden();
        $this->get(route('products.template'))->assertForbidden();
        $this->get(route('products.import'))->assertForbidden();
        $this->post(route('products.import.store'), [])->assertForbidden();
    }
}
