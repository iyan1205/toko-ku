<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use App\Services\Settings\ReceiptSettingsService;
use App\Services\Settings\StoreProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class StrukPrinterTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $pemilik;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->pemilik = $this->buatPengguna(RoleName::Pemilik);
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
        Customer::umum();
    }

    private function payload(array $ubah = []): array
    {
        return array_merge([
            'paper_width' => '58',
            'footer' => 'Terima kasih telah berbelanja',
            'auto_print' => '0',
            'show_logo' => '0',
            'show_npwp' => '0',
        ], $ubah);
    }

    private function simpan(array $ubah = []): void
    {
        $this->actingAs($this->pemilik)
            ->put(route('settings.receipts.update'), $this->payload($ubah))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.receipts.edit'));
    }

    private function jual(): Sale
    {
        $produk = Product::factory()->stok(10)->create(['name' => 'Ayam Geprek', 'selling_price' => 10000]);

        return app(SaleService::class)->buat([
            'items' => [['product_id' => $produk->id, 'quantity' => '1', 'discount' => '0']],
            'discount' => '0', 'payment_method' => 'cash', 'paid_amount' => '10000',
        ], $this->kasir);
    }

    public function test_halaman_struk_dan_printer_tampil(): void
    {
        $this->actingAs($this->pemilik)
            ->get(route('settings.receipts.edit'))
            ->assertOk()
            ->assertSee('Lebar kertas')
            ->assertSee('Footer struk')
            ->assertSee('<title>Struk &amp; Printer', false)
            ->assertSee(str_replace('/', '\\/', route('settings.receipts.preview')), false);
    }

    public function test_struk_memakai_lebar_dan_footer_dari_pengaturan(): void
    {
        $this->simpan(['paper_width' => '80', 'footer' => "Terima kasih\nSelamat menikmati"]);

        $this->actingAs($this->kasir)
            ->get(route('sales.receipt', $this->jual()))
            ->assertOk()
            ->assertSee('size: 80mm auto', false)
            ->assertSee('width: 80mm', false)
            ->assertSee('Selamat menikmati');
    }

    public function test_lebar_kertas_harus_58_atau_80(): void
    {
        $this->actingAs($this->pemilik)
            ->put(route('settings.receipts.update'), $this->payload(['paper_width' => '72']))
            ->assertSessionHasErrors(['paper_width' => 'Lebar kertas struk harus 58 mm atau 80 mm.']);
    }

    public function test_cetak_otomatis_dari_pengaturan_mengalahkan_nilai_env(): void
    {
        config(['pos.struk.cetak_otomatis' => true]);
        $jual = $this->jual();

        $this->simpan(['auto_print' => '0']);

        $this->actingAs($this->kasir)
            ->get(route('sales.receipt', $jual))
            ->assertOk()
            ->assertDontSee("addEventListener('load'", false);

        $this->simpan(['auto_print' => '1']);
        config(['pos.struk.cetak_otomatis' => false]);

        $this->actingAs($this->kasir)
            ->get(route('sales.receipt', $jual))
            ->assertSee("addEventListener('load'", false);
    }

    public function test_logo_tampil_di_struk_hanya_bila_diaktifkan(): void
    {
        Storage::fake('public');
        app(StoreProfileService::class)->simpan(['logo' => UploadedFile::fake()->image('logo.png')]);
        $logo = app(StoreProfileService::class)->ambil()['logo_url'];
        $jual = $this->jual();

        $this->actingAs($this->kasir)->get(route('sales.receipt', $jual))->assertDontSee($logo, false);

        $this->simpan(['show_logo' => '1']);

        $this->actingAs($this->kasir)->get(route('sales.receipt', $jual))->assertSee('<img src="'.$logo.'"', false);
    }

    public function test_npwp_tampil_di_struk_dan_teks_bluetooth_hanya_bila_diaktifkan(): void
    {
        app(StoreProfileService::class)->simpan(['npwp' => '01.234.567.8-901.000']);
        $jual = $this->jual();

        $this->actingAs($this->kasir)->get(route('sales.receipt', $jual))->assertDontSee('NPWP 01.234.567.8-901.000');
        $this->assertStringNotContainsString('NPWP', $this->actingAs($this->kasir)->getJson(route('sales.receipt.text', $jual))->json('teks'));

        $this->simpan(['show_npwp' => '1']);

        $this->actingAs($this->kasir)->get(route('sales.receipt', $jual))->assertSee('NPWP 01.234.567.8-901.000');
        $this->assertStringContainsString('NPWP 01.234.567.8-901.000', $this->actingAs($this->kasir)->getJson(route('sales.receipt.text', $jual))->json('teks'));
    }

    // ---------------- Pratinjau ----------------

    public function test_pratinjau_memakai_isian_yang_belum_disimpan(): void
    {
        $this->actingAs($this->pemilik)
            ->get(route('settings.receipts.preview', ['paper_width' => 80, 'footer' => 'Promo hari ini']))
            ->assertOk()
            ->assertSee('size: 80mm auto', false)
            ->assertSee('Promo hari ini')
            ->assertSee('Kopi Susu Gula Aren')
            ->assertSee('POS-'.now()->format('Ymd').'-0001', false);

        $this->assertSame(58, app(ReceiptSettingsService::class)->get()['paper_width'], 'Pratinjau tidak menyimpan apa pun.');
        $this->assertSame(0, Sale::count());
    }

    public function test_pratinjau_tanpa_tombol_dan_skrip_cetak(): void
    {
        $this->simpan(['auto_print' => '1']);

        $this->actingAs($this->pemilik)
            ->get(route('settings.receipts.preview'))
            ->assertOk()
            ->assertDontSee('window.print()', false)
            ->assertDontSee("addEventListener('load'", false)
            ->assertDontSee('cetakBluetooth', false);
    }

    public function test_footer_dikosongkan_di_pratinjau_memakai_footer_bawaan(): void
    {
        $this->simpan(['footer' => 'Footer lama']);

        $this->actingAs($this->pemilik)
            ->get(route('settings.receipts.preview', ['footer' => '']))
            ->assertSee(StoreProfileService::FOOTER_BAWAAN)
            ->assertDontSee('Footer lama');
    }

    public function test_perubahan_tercatat_di_log_aktivitas(): void
    {
        $this->simpan(['paper_width' => '80', 'show_npwp' => '1']);

        $log = Activity::query()->where('description', 'Pengaturan struk diubah')->sole();
        $this->assertSame(['lebar_kertas' => '58 mm', 'tampilkan_npwp' => 'tidak'], $log->properties['old']);
        $this->assertSame(['lebar_kertas' => '80 mm', 'tampilkan_npwp' => 'ya'], $log->properties['attributes']);
    }

    public function test_admin_tanpa_izin_tidak_bisa_membuka_maupun_mengubah(): void
    {
        $admin = $this->buatPengguna(RoleName::Admin);

        $this->actingAs($admin)->get(route('settings.receipts.edit'))->assertForbidden();
        $this->actingAs($admin)->get(route('settings.receipts.preview'))->assertForbidden();
        $this->actingAs($admin)->put(route('settings.receipts.update'), $this->payload(['paper_width' => '80']))->assertForbidden();

        $this->assertSame(58, app(ReceiptSettingsService::class)->get()['paper_width']);
    }
}
