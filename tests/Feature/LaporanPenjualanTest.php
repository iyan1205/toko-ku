<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Reports\LaporanPenjualan;
use App\Services\SaleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class LaporanPenjualanTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $kasir;
    private User $admin;
    private Product $ayam; // jual 10.000, modal 7.000

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->ayam = Product::factory()->stok(100)->create([
            'name' => 'Ayam Geprek', 'sku' => 'AYM-1', 'selling_price' => 10000, 'purchase_price' => 7000,
        ]);
        Customer::umum();
    }

    private function jual(int $jumlah, ?string $tanggal = null): Sale
    {
        $jual = app(SaleService::class)->buat([
            'customer_id' => null,
            'items' => [['product_id' => $this->ayam->id, 'quantity' => (string) $jumlah, 'discount' => '0']],
            'discount' => '0',
            'payment_method' => 'cash',
            'paid_amount' => (string) ($jumlah * 10000),
        ], $this->kasir);

        if ($tanggal !== null) {
            $jual->forceFill(['sold_at' => $tanggal])->save();
        }

        return $jual;
    }

    private function laporan(string $dari, string $sampai): LaporanPenjualan
    {
        return new LaporanPenjualan(CarbonImmutable::parse($dari)->startOfDay(), CarbonImmutable::parse($sampai)->endOfDay());
    }

    public function test_ringkasan_menghitung_omzet_hpp_dan_laba(): void
    {
        $this->jual(2); // 20.000, HPP 14.000, laba 6.000

        $r = $this->laporan('2020-01-01', '2030-01-01')->ringkasan();

        $this->assertSame(1, $r['jumlah_transaksi']);
        $this->assertSame(20000.0, $r['omzet']);
        $this->assertSame(14000.0, $r['hpp']);
        $this->assertSame(6000.0, $r['laba']);
        $this->assertSame(30.0, $r['margin']);
        $this->assertSame(20000.0, $r['rata_rata']);
        $this->assertSame(2.0, $r['jumlah_item']);
    }

    public function test_penjualan_dibatalkan_tidak_dihitung(): void
    {
        $jual = $this->jual(2);
        app(SaleService::class)->batalkan($jual, 'Salah input barang', $this->admin);

        $r = $this->laporan('2020-01-01', '2030-01-01')->ringkasan();

        $this->assertSame(0, $r['jumlah_transaksi']);
        $this->assertSame(0.0, $r['omzet']);
        $this->assertSame(0.0, $r['laba']);
    }

    public function test_transaksi_di_luar_periode_tidak_dihitung(): void
    {
        $this->jual(2, '2026-01-15 10:00:00');

        $this->assertSame(0.0, $this->laporan('2026-02-01', '2026-02-28')->ringkasan()['omzet']);
        $this->assertSame(20000.0, $this->laporan('2026-01-01', '2026-01-31')->ringkasan()['omzet']);
    }

    public function test_laba_memakai_harga_modal_saat_transaksi_bukan_harga_terkini(): void
    {
        $this->jual(1); // modal tersimpan 7.000

        $this->ayam->update(['purchase_price' => 9000]); // harga beli naik setelah transaksi

        $r = $this->laporan('2020-01-01', '2030-01-01')->ringkasan();

        $this->assertSame(7000.0, $r['hpp'], 'Laporan historis harus memakai cost_price yang disalin saat penjualan.');
        $this->assertSame(3000.0, $r['laba']);
    }

    public function test_rekap_harian_mengelompokkan_per_tanggal(): void
    {
        $this->jual(1, '2026-03-01 09:00:00');
        $this->jual(2, '2026-03-01 15:00:00');
        $this->jual(1, '2026-03-02 09:00:00');

        $harian = $this->laporan('2026-03-01', '2026-03-31')->harian();

        $this->assertCount(2, $harian);
        $this->assertSame('2026-03-01', $harian[0]['tanggal']);
        $this->assertSame(2, $harian[0]['jumlah']);
        $this->assertSame(30000.0, $harian[0]['omzet']);
        $this->assertSame(21000.0, $harian[0]['hpp']);
        $this->assertSame(9000.0, $harian[0]['laba']);
        $this->assertSame('2026-03-02', $harian[1]['tanggal']);
        $this->assertSame(10000.0, $harian[1]['omzet']);
    }

    public function test_produk_teratas_diurutkan_menurut_omzet(): void
    {
        $nasi = Product::factory()->stok(50)->create(['name' => 'Nasi Putih', 'sku' => 'NSI-1', 'selling_price' => 5000, 'purchase_price' => 3000]);

        app(SaleService::class)->buat([
            'customer_id' => null,
            'items' => [
                ['product_id' => $this->ayam->id, 'quantity' => '1', 'discount' => '0'],
                ['product_id' => $nasi->id, 'quantity' => '10', 'discount' => '0'],
            ],
            'discount' => '0', 'payment_method' => 'cash', 'paid_amount' => '60000',
        ], $this->kasir);

        $teratas = $this->laporan('2020-01-01', '2030-01-01')->produkTeratas();

        $this->assertSame('Nasi Putih', $teratas[0]['nama'], 'Nasi (50.000) mengalahkan Ayam (10.000).');
        $this->assertSame(10.0, $teratas[0]['jumlah']);
        $this->assertSame(50000.0, $teratas[0]['omzet']);
        $this->assertSame(20000.0, $teratas[0]['laba']);
        $this->assertSame('Ayam Geprek', $teratas[1]['nama']);
    }

    public function test_rekap_per_metode_dan_per_kasir(): void
    {
        $this->jual(1);
        $this->jual(1);

        $metode = $this->laporan('2020-01-01', '2030-01-01')->perMetode();
        $this->assertCount(1, $metode);
        $this->assertSame('Tunai', $metode[0]['metode']->label());
        $this->assertSame(2, $metode[0]['jumlah']);
        $this->assertSame(20000.0, $metode[0]['omzet']);

        $kasir = $this->laporan('2020-01-01', '2030-01-01')->perKasir();
        $this->assertCount(1, $kasir);
        $this->assertSame($this->kasir->name, $kasir[0]['nama']);
        $this->assertSame(2, $kasir[0]['jumlah']);
        $this->assertSame(6000.0, $kasir[0]['laba']);
    }

    public function test_piutang_masuk_ringkasan_dan_rekap_metode(): void
    {
        $pelanggan = Customer::factory()->create(['name' => 'Budi']);

        app(SaleService::class)->buat([
            'customer_id' => $pelanggan->id,
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '2', 'discount' => '0']],
            'discount' => '0', 'payment_method' => 'receivable', 'paid_amount' => '0',
        ], $this->kasir);

        $laporan = $this->laporan('2020-01-01', '2030-01-01');

        $this->assertSame(20000.0, $laporan->ringkasan()['piutang']);

        $piutang = $laporan->perMetode()->firstWhere(fn ($m) => $m['metode']->value === 'receivable');
        $this->assertNotNull($piutang);
        $this->assertSame(20000.0, $piutang['piutang']);
    }

    public function test_admin_dapat_membuka_halaman_laporan(): void
    {
        $this->jual(2);

        $this->actingAs($this->admin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Laporan Penjualan')
            ->assertSee('Laba kotor');
    }

    public function test_kasir_tidak_punya_akses_laporan(): void
    {
        $this->actingAs($this->kasir)
            ->get(route('reports.index'))
            ->assertForbidden();
    }

    public function test_ekspor_excel_berhasil(): void
    {
        $this->jual(1);

        $this->actingAs($this->admin)
            ->get(route('reports.sales.export'))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_default_periode_adalah_bulan_berjalan(): void
    {
        $this->jual(1);

        $this->actingAs($this->admin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee(now()->startOfMonth()->format('Y-m-d'))
            ->assertSee(now()->format('Y-m-d'));
    }
}
