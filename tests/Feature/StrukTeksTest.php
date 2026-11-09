<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use App\Services\StoreProfileService;
use App\Support\StrukTeks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/**
 * Cetak struk dari HP: printer thermal Bluetooth dijembatani aplikasi RawBT.
 * Yang diuji di sini adalah teks struk yang dikirim ke jembatan itu.
 */
class StrukTeksTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $kasir;

    private User $admin;

    private Product $ayam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->ayam = Product::factory()->stok(50)->create([
            'name' => 'Ayam Geprek', 'sku' => 'AYM-1', 'selling_price' => 10000, 'purchase_price' => 7000,
        ]);
        Customer::umum();
    }

    private function profilToko(array $ubah = []): void
    {
        app(StoreProfileService::class)->simpan(array_merge([
            'nama_toko' => 'Dkriuk Jabong',
            'nama_pemilik' => 'Iyan Hermawan',
            'alamat' => 'Jl. Merdeka No. 10',
            'telepon' => '0812-3456-7890',
            'instagram' => 'dkriukjabong',
            'tiktok' => 'dkriukjabong',
            'footer_struk' => 'Terima kasih',
            'lebar_struk_mm' => 58,
        ], $ubah));
    }

    private function jual(array $ubah = []): Sale
    {
        return app(SaleService::class)->buat(array_merge([
            'customer_id' => null,
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '2', 'discount' => '0']],
            'discount' => '0',
            'payment_method' => 'cash',
            'paid_amount' => '25000',
        ], $ubah), $this->kasir);
    }

    // ---------------- Lebar kertas ----------------

    public function test_lebar_karakter_sesuai_ukuran_kertas(): void
    {
        $this->assertSame(32, StrukTeks::lebarKertas(58), 'Kertas 58 mm = 32 karakter.');
        $this->assertSame(48, StrukTeks::lebarKertas(80), 'Kertas 80 mm = 48 karakter.');
        $this->assertSame(32, StrukTeks::lebarKertas(72), 'Ukuran tak dikenal kembali ke 58 mm.');
    }

    public function test_semua_baris_tidak_melebihi_lebar_kertas(): void
    {
        $this->profilToko();
        $jual = $this->jual();

        $teks = (new StrukTeks($jual->load(['customer', 'user', 'items']), app(StoreProfileService::class)->ambil()))->susun();

        $lebar = 32;
        foreach (explode("\n", $teks) as $i => $baris) {
            $this->assertLessThanOrEqual(
                $lebar,
                mb_strlen($baris),
                "Baris ke-{$i} melebihi {$lebar} karakter: '{$baris}'"
            );
        }
    }

    public function test_nama_produk_panjang_dipotong_bukan_merusak_tata_letak(): void
    {
        $this->profilToko();
        $panjang = Product::factory()->stok(10)->create([
            'name' => 'Ayam Geprek Sambal Bawang Spesial Jumbo Ekstra Pedas Level Lima',
            'selling_price' => 15000,
        ]);

        $jual = app(SaleService::class)->buat([
            'customer_id' => null,
            'items' => [['product_id' => $panjang->id, 'quantity' => '1', 'discount' => '0']],
            'discount' => '0', 'payment_method' => 'cash', 'paid_amount' => '15000',
        ], $this->kasir);

        $teks = (new StrukTeks($jual->load(['customer', 'user', 'items']), app(StoreProfileService::class)->ambil()))->susun();

        foreach (explode("\n", $teks) as $baris) {
            $this->assertLessThanOrEqual(32, mb_strlen($baris));
        }
        $this->assertStringContainsString('Ayam Geprek Sambal Bawang', $teks, 'Nama tetap terbaca walau dipotong.');
    }

    // ---------------- Isi struk ----------------

    public function test_teks_memuat_identitas_toko_dan_transaksi(): void
    {
        $this->profilToko();
        $jual = $this->jual();

        $teks = (new StrukTeks($jual->load(['customer', 'user', 'items']), app(StoreProfileService::class)->ambil()))->susun();

        $this->assertStringContainsString('DKRIUK JABONG', $teks, 'Nama toko huruf besar.');
        $this->assertStringContainsString('Iyan Hermawan', $teks);
        $this->assertStringContainsString('Jl. Merdeka No. 10', $teks);
        $this->assertStringContainsString('Telp 0812-3456-7890', $teks);
        $this->assertStringContainsString($jual->number, $teks);
        $this->assertStringContainsString('Ayam Geprek', $teks);
        $this->assertStringContainsString('TOTAL', $teks);
        $this->assertStringContainsString('20.000', $teks, 'Total 2 x 10.000.');
        $this->assertStringContainsString('Kembali', $teks);
        // Di kertas 58 mm kedua akun tidak muat sebaris, jadi masing-masing dapat barisnya sendiri.
        $this->assertStringContainsString('IG @dkriukjabong', $teks);
        $this->assertStringContainsString('TikTok @dkriukjabong', $teks);
    }

    public function test_nilai_uang_rata_kanan_sejajar(): void
    {
        $this->profilToko();
        $jual = $this->jual();

        $teks = (new StrukTeks($jual->load(['customer', 'user', 'items']), app(StoreProfileService::class)->ambil()))->susun();
        $baris = explode("\n", $teks);

        $barisTotal = null;
        foreach ($baris as $b) {
            if (str_starts_with($b, 'TOTAL')) {
                $barisTotal = $b;
            }
        }

        $this->assertNotNull($barisTotal);
        $this->assertSame(32, mb_strlen($barisTotal), 'Baris TOTAL harus pas selebar kertas (nilai rata kanan).');
        $this->assertStringEndsWith('20.000', $barisTotal);
    }

    public function test_pembatalan_ditandai_di_struk(): void
    {
        $this->profilToko();
        $jual = $this->jual();
        app(SaleService::class)->batalkan($jual, 'Salah input barang', $this->admin);

        $teks = (new StrukTeks($jual->fresh()->load(['customer', 'user', 'items']), app(StoreProfileService::class)->ambil()))->susun();

        $this->assertStringContainsString('** STRUK DIBATALKAN **', $teks);
        $this->assertStringContainsString('Salah input barang', $teks);
    }

    public function test_piutang_ditampilkan_di_struk(): void
    {
        $this->profilToko();
        $pelanggan = Customer::factory()->create(['name' => 'Budi']);

        $jual = $this->jual([
            'customer_id' => $pelanggan->id,
            'payment_method' => PaymentMethod::Receivable->value,
            'paid_amount' => '0',
        ]);

        $teks = (new StrukTeks($jual->load(['customer', 'user', 'items']), app(StoreProfileService::class)->ambil()))->susun();

        $this->assertStringContainsString('Sisa piutang', $teks);
        $this->assertStringContainsString('PIUTANG', $teks);
    }

    public function test_footer_banyak_baris_ikut_dicetak(): void
    {
        $this->profilToko(['footer_struk' => "Terima kasih\nSelamat menikmati"]);

        $teks = (new StrukTeks($this->jual()->load(['customer', 'user', 'items']), app(StoreProfileService::class)->ambil()))->susun();

        $this->assertStringContainsString('Terima kasih', $teks);
        $this->assertStringContainsString('Selamat menikmati', $teks);
    }

    public function test_lebar_80mm_memakai_kolom_lebih_lebar(): void
    {
        $this->profilToko(['lebar_struk_mm' => 80]);

        $teks = (new StrukTeks($this->jual()->load(['customer', 'user', 'items']), app(StoreProfileService::class)->ambil()))->susun();

        $maks = max(array_map('mb_strlen', explode("\n", $teks)));
        $this->assertGreaterThan(32, $maks, 'Kertas 80 mm harus memakai lebih dari 32 kolom.');
        $this->assertLessThanOrEqual(48, $maks);
    }

    // ---------------- Endpoint ----------------

    public function test_kasir_dapat_mengambil_teks_struk_penjualannya(): void
    {
        $this->profilToko();
        $jual = $this->jual();

        $this->actingAs($this->kasir)
            ->getJson(route('sales.receipt.text', $jual))
            ->assertOk()
            ->assertJsonStructure(['nomor', 'lebar_mm', 'lebar_karakter', 'teks'])
            ->assertJsonPath('nomor', $jual->number)
            ->assertJsonPath('lebar_mm', 58)
            ->assertJsonPath('lebar_karakter', 32)
            ->assertJsonPath('teks', fn ($t) => str_contains($t, 'Ayam Geprek'));
    }

    public function test_kasir_tidak_bisa_mengambil_struk_milik_kasir_lain(): void
    {
        $jual = $this->jual();
        $kasirLain = $this->buatPengguna(RoleName::Kasir);

        $this->actingAs($kasirLain)
            ->getJson(route('sales.receipt.text', $jual))
            ->assertForbidden();
    }

    public function test_admin_dapat_mengambil_teks_struk_semua_kasir(): void
    {
        $this->profilToko();
        $jual = $this->jual();

        $this->actingAs($this->admin)
            ->getJson(route('sales.receipt.text', $jual))
            ->assertOk()
            ->assertJsonPath('nomor', $jual->number);
    }

    public function test_tanpa_izin_print_receipt_ditolak(): void
    {
        $jual = $this->jual();
        // Boleh melihat semua penjualan (lolos SalePolicy), tetapi tidak punya izin cetak struk.
        $tanpaIzin = User::factory()->create()->givePermissionTo('pos.transactions.view');

        $this->actingAs($tanpaIzin)
            ->getJson(route('sales.receipt.text', $jual))
            ->assertForbidden();
    }

    public function test_tamu_diarahkan_ke_login(): void
    {
        $jual = $this->jual();

        $this->get(route('sales.receipt.text', $jual))->assertRedirect(route('login'));
    }

    // ---------------- Halaman struk menyediakan jalur Bluetooth ----------------

    public function test_halaman_struk_menyediakan_tombol_bluetooth(): void
    {
        $this->profilToko();
        $jual = $this->jual();

        $this->actingAs($this->kasir)
            ->get(route('sales.receipt', $jual))
            ->assertOk()
            ->assertSee('tombol-bluetooth', false)
            ->assertSee('Cetak via Bluetooth')
            ->assertSee('rawbt', false)
            ->assertSee(route('sales.receipt.text', $jual));
    }

    public function test_jalur_cetak_biasa_tetap_ada(): void
    {
        $this->profilToko();
        $jual = $this->jual();

        // Pengguna komputer tidak boleh kehilangan cara cetak yang lama.
        $this->actingAs($this->kasir)
            ->get(route('sales.receipt', $jual))
            ->assertOk()
            ->assertSee('Cetak (printer biasa)')
            ->assertSee('window.print()', false);
    }

    public function test_akun_sosmed_tidak_terpotong_di_kertas_sempit(): void
    {
        $this->profilToko(['lebar_struk_mm' => 58]);

        $teks = (new StrukTeks($this->jual()->load(['customer', 'user', 'items']), app(StoreProfileService::class)->ambil()))->susun();

        // Nama akun utuh, bukan terpotong di tengah.
        $this->assertStringContainsString('IG @dkriukjabong', $teks);
        $this->assertStringContainsString('TikTok @dkriukjabong', $teks);
        $this->assertStringNotContainsString('@dkriu\n', $teks, 'Nama akun tidak boleh terpotong.');
    }
}
