<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\Setting;
use App\Services\SaleService;
use App\Services\StoreProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class StrukThermalTest extends TestCase
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

    /** Atur profil toko lewat service (jalur yang sama dengan form Pengaturan). */
    private function profilToko(array $ubah = []): void
    {
        app(StoreProfileService::class)->simpan(array_merge([
            'nama_toko' => 'Dkriuk Jabong',
            'nama_pemilik' => '',
            'alamat' => '',
            'telepon' => '',
            'instagram' => '',
            'tiktok' => '',
            'footer_struk' => 'Terima kasih telah berbelanja',
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
            'paid_amount' => '30000',
        ], $ubah), $this->kasir);
    }

    public function test_kasir_dapat_membuka_struk_penjualannya(): void
    {
        $jual = $this->jual();

        $this->actingAs($this->kasir)
            ->get(route('sales.receipt', $jual))
            ->assertOk()
            ->assertSee($jual->number)
            ->assertSee('Ayam Geprek')
            ->assertSee('TOTAL')
            ->assertSee('Kembali');
    }

    public function test_struk_memakai_ukuran_kertas_roll_58mm_secara_default(): void
    {
        $jual = $this->jual();

        $this->actingAs($this->kasir)
            ->get(route('sales.receipt', $jual))
            ->assertOk()
            ->assertSee('size: 58mm auto', false)
            ->assertSee('width: 58mm', false);
    }

    public function test_lebar_struk_mengikuti_pengaturan_80mm(): void
    {
        $this->profilToko(['lebar_struk_mm' => 80]);
        $jual = $this->jual();

        $this->actingAs($this->kasir)
            ->get(route('sales.receipt', $jual))
            ->assertOk()
            ->assertSee('size: 80mm auto', false)
            ->assertSee('width: 80mm', false)
            ->assertDontSee('size: 58mm auto', false);
    }

    public function test_struk_tidak_memuat_sidebar_maupun_aset_vite(): void
    {
        $jual = $this->jual();

        $response = $this->actingAs($this->kasir)->get(route('sales.receipt', $jual))->assertOk();

        // Halaman berdiri sendiri: tidak ada menu sidebar, tidak butuh Tailwind/Vite,
        // sehingga tetap tampil benar walau aset gagal dimuat.
        $response->assertDontSee('Menu utama');
        $response->assertDontSee('@vite', false);
        // Label tombol berubah saat jalur Bluetooth ditambahkan; yang penting tombol cetaknya ada.
        $response->assertSee('Cetak (printer biasa)');
    }

    public function test_footer_struk_dapat_diatur_dan_mendukung_beberapa_baris(): void
    {
        $this->profilToko(['footer_struk' => "Terima kasih\nBarang yang sudah dibeli tidak dapat ditukar"]);
        $jual = $this->jual();

        $this->actingAs($this->kasir)
            ->get(route('sales.receipt', $jual))
            ->assertOk()
            ->assertSee('Terima kasih')
            ->assertSee('Barang yang sudah dibeli tidak dapat ditukar');
    }

    public function test_struk_menandai_penjualan_yang_dibatalkan(): void
    {
        $jual = $this->jual();
        app(SaleService::class)->batalkan($jual, 'Salah input barang', $this->admin);

        $this->actingAs($this->admin)
            ->get(route('sales.receipt', $jual->fresh()))
            ->assertOk()
            ->assertSee('STRUK DIBATALKAN')
            ->assertSee('Salah input barang');
    }

    public function test_struk_menampilkan_sisa_piutang(): void
    {
        $pelanggan = Customer::factory()->create(['name' => 'Budi']);
        $jual = $this->jual([
            'customer_id' => $pelanggan->id,
            'payment_method' => PaymentMethod::Receivable->value,
            'paid_amount' => '5000',
            'dp_method' => 'cash',
        ]);

        // Total 20.000 dibayar di muka 5.000 -> sisa 15.000, status SEBAGIAN.
        $this->actingAs($this->kasir)
            ->get(route('sales.receipt', $jual))
            ->assertOk()
            ->assertSee('Sisa piutang')
            ->assertSee('15.000')
            ->assertSee('SEBAGIAN');
    }

    public function test_struk_piutang_tanpa_dp_berstatus_piutang(): void
    {
        $pelanggan = Customer::factory()->create(['name' => 'Siti']);
        $jual = $this->jual([
            'customer_id' => $pelanggan->id,
            'payment_method' => PaymentMethod::Receivable->value,
            'paid_amount' => '0',
        ]);

        $this->actingAs($this->kasir)
            ->get(route('sales.receipt', $jual))
            ->assertOk()
            ->assertSee('Sisa piutang')
            ->assertSee('PIUTANG');
    }

    public function test_kasir_tidak_bisa_membuka_struk_milik_kasir_lain(): void
    {
        $jual = $this->jual();

        $kasirLain = $this->buatPengguna(RoleName::Kasir);

        $this->actingAs($kasirLain)
            ->get(route('sales.receipt', $jual))
            ->assertForbidden();
    }

    public function test_admin_dapat_membuka_struk_semua_kasir(): void
    {
        $jual = $this->jual();

        $this->actingAs($this->admin)
            ->get(route('sales.receipt', $jual))
            ->assertOk()
            ->assertSee($jual->number);
    }

    public function test_pengguna_tanpa_izin_print_receipt_ditolak(): void
    {
        $jual = $this->jual();

        // Kasir punya print-receipt pada matriks bawaan; cabut untuk menguji gerbang izinnya.
        $tanpaIzin = $this->buatPengguna(RoleName::Kasir);
        $tanpaIzin->revokePermissionTo('print-receipt');

        $this->actingAs($tanpaIzin->fresh())
            ->get(route('sales.receipt', $jual))
            ->assertForbidden();
    }

    public function test_setelah_menyimpan_penjualan_kasir_diarahkan_ke_detail(): void
    {
        // Catatan: perilaku saat ini mengarahkan ke halaman detail penjualan.
        // Halaman struk tersedia lewat tombol "Cetak Struk" di halaman detail.
        $this->actingAs($this->kasir)
            ->post(route('sales.store'), [
                'customer_id' => null,
                'items' => [['product_id' => $this->ayam->id, 'quantity' => '1', 'discount' => '0']],
                'discount' => '0',
                'payment_method' => 'cash',
                'paid_amount' => '10000',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('sales.show', Sale::firstOrFail()));
    }

    public function test_halaman_detail_penjualan_menyediakan_tautan_struk(): void
    {
        $jual = $this->jual();

        $this->actingAs($this->kasir)
            ->get(route('sales.show', $jual))
            ->assertOk()
            ->assertSee(route('sales.receipt', $jual));
    }

    public function test_cetak_otomatis_hanya_aktif_bila_dinyalakan(): void
    {
        $jual = $this->jual();

        config(['pos.struk.cetak_otomatis' => false]);
        $this->actingAs($this->kasir)
            ->get(route('sales.receipt', $jual))
            ->assertOk()
            ->assertDontSee("addEventListener('load'", false);

        config(['pos.struk.cetak_otomatis' => true]);
        $this->actingAs($this->kasir)
            ->get(route('sales.receipt', $jual))
            ->assertOk()
            ->assertSee("addEventListener('load'", false);
    }
}