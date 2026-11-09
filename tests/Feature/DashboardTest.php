<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/**
 * Data penjualan (09 Nov 2026, jam 14.00):
 *   - hari ini: kasir A Rp 20.000, kasir B Rp 10.000, kasir A Rp 50.000 dibatalkan
 *   - kemarin: kasir A Rp 40.000
 *   - 10 hari lalu: kasir B Nasi Rp 15.000 (masuk 30 hari, tidak masuk 7 hari)
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;

    private User $kasirA;

    private User $kasirB;

    private Product $ayam;

    private Product $nasi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->kasirA = $this->buatPengguna(RoleName::Kasir, ['name' => 'Kasir Ani']);
        $this->kasirB = $this->buatPengguna(RoleName::Kasir, ['name' => 'Kasir Budi']);
        Customer::umum();

        $this->ayam = Product::factory()->stok(100)->create(['name' => 'Ayam Geprek', 'selling_price' => 10000, 'min_stock' => 5]);
        $this->nasi = Product::factory()->stok(100)->create(['name' => 'Nasi Uduk', 'selling_price' => 5000, 'min_stock' => 5]);
        Product::factory()->stok(2)->create(['name' => 'Teh Botol', 'min_stock' => 5]);
        Product::factory()->stok(0)->create(['name' => 'Kopi Sachet', 'min_stock' => 3]);

        $this->travelTo('2026-10-30 10:00:00');
        $this->jual($this->kasirB, $this->nasi, 3);

        $this->travelTo('2026-11-08 10:00:00');
        $this->jual($this->kasirA, $this->ayam, 4);

        $this->travelTo('2026-11-09 09:00:00');
        $this->jual($this->kasirA, $this->ayam, 2);
        $this->jual($this->kasirB, $this->ayam, 1);
        $batal = $this->jual($this->kasirA, $this->ayam, 5);
        app(SaleService::class)->batalkan($batal, 'Salah input', $this->admin);

        $this->travelTo('2026-11-09 14:00:00');
    }

    private function jual(User $kasir, Product $produk, int $jumlah): Sale
    {
        return app(SaleService::class)->buat([
            'items' => [['product_id' => $produk->id, 'quantity' => (string) $jumlah, 'discount' => '0']],
            'discount' => '0', 'payment_method' => 'cash', 'paid_amount' => '1000000',
        ], $kasir);
    }

    private function hutang(?string $jatuhTempo, string $sisa, array $atribut = []): Purchase
    {
        return Purchase::factory()->create(array_merge([
            'due_date' => $jatuhTempo, 'balance' => $sisa, 'grand_total' => $sisa,
            'payment_status' => (float) $sisa > 0 ? PaymentStatus::Unpaid : PaymentStatus::Paid,
        ], $atribut));
    }

    public function test_ringkasan_toko_hanya_menghitung_penjualan_selesai(): void
    {
        $data = app(DashboardService::class)->for($this->admin);

        $this->assertSame('store', $data['salesScope']);
        $this->assertSame(
            ['today_total' => 30000.0, 'today_count' => 2, 'yesterday_total' => 40000.0, 'average' => 15000.0],
            $data['sales'],
        );
    }

    public function test_grafik_berisi_30_hari_termasuk_hari_tanpa_penjualan(): void
    {
        $grafik = app(DashboardService::class)->for($this->admin)['chart'];

        $this->assertCount(30, $grafik);
        $this->assertSame('2026-10-11', $grafik[0]['date']);
        $this->assertSame(['date' => '2026-11-09', 'total' => 30000.0, 'count' => 2], array_intersect_key(end($grafik), array_flip(['date', 'total', 'count'])));
        $this->assertSame(15000.0, collect($grafik)->firstWhere('date', '2026-10-30')['total']);
        $this->assertSame(0.0, collect($grafik)->firstWhere('date', '2026-11-01')['total']);
        $this->assertSame('09 Nov', end($grafik)['label']);
    }

    public function test_produk_terlaris_7_dan_30_hari(): void
    {
        $terlaris = app(DashboardService::class)->for($this->admin)['topProducts'];

        $this->assertSame(['Ayam Geprek'], $terlaris[7]->pluck('nama')->all());
        $this->assertSame(['Ayam Geprek', 'Nasi Uduk'], $terlaris[30]->pluck('nama')->all());
        $this->assertSame(70000.0, $terlaris[7]->first()['omzet'], 'Penjualan batal (Rp 50.000) tidak dihitung.');
    }

    public function test_admin_melihat_transaksi_terakhir_semua_kasir_termasuk_yang_dibatalkan(): void
    {
        $data = app(DashboardService::class)->for($this->admin);

        $this->assertSame('all', $data['recentScope']);
        $this->assertCount(5, $data['recentSales']);
        $this->assertTrue($data['recentSales']->first()->dibatalkan(), 'Terbaru paling atas.');
    }

    public function test_stok_menipis_menghitung_produk_di_bawah_minimum(): void
    {
        $stok = app(DashboardService::class)->for($this->admin)['lowStock'];

        $this->assertSame(2, $stok['count']);
        $this->assertSame(['Kopi Sachet', 'Teh Botol'], $stok['items']->pluck('name')->all(), 'Stok paling sedikit dulu.');
    }

    public function test_hutang_jatuh_tempo_dan_yang_segera_jatuh_tempo(): void
    {
        $this->hutang('2026-11-08', '100000');
        $this->hutang('2026-11-09', '50000');
        $this->hutang('2026-11-12', '70000');
        $this->hutang('2026-11-20', '90000');
        $this->hutang('2026-11-01', '0');
        $this->hutang('2026-11-01', '80000', ['status' => 'voided']);
        $this->hutang(null, '60000');

        $this->assertSame(
            ['overdue_total' => 150000.0, 'overdue_count' => 2, 'upcoming_total' => 70000.0, 'upcoming_count' => 1],
            app(DashboardService::class)->for($this->admin)['payables'],
        );
    }

    public function test_kasir_hanya_melihat_penjualannya_sendiri(): void
    {
        $data = app(DashboardService::class)->for($this->kasirA);

        $this->assertSame('own', $data['salesScope']);
        $this->assertSame(['today_total' => 20000.0, 'today_count' => 1, 'yesterday_total' => 40000.0, 'average' => 20000.0], $data['sales']);
        $this->assertNull($data['chart']);
        $this->assertNull($data['topProducts']);
        $this->assertSame('own', $data['recentScope']);
        $this->assertSame([$this->kasirA->id], $data['recentSales']->pluck('user_id')->unique()->values()->all());
        $this->assertNull($data['payables']);
        $this->assertNotNull($data['lowStock'], 'Kasir boleh melihat stok.');
    }

    public function test_halaman_dashboard_admin(): void
    {
        $this->hutang('2026-11-08', '100000');

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Penjualan hari ini')
            ->assertSee('Rp 30.000')
            ->assertSee('25%')
            ->assertSee('Omzet harian')
            ->assertSee('Produk terlaris')
            ->assertSee('Hutang jatuh tempo')
            ->assertSee('Rp 100.000')
            ->assertSee('Perlu diisi ulang')
            ->assertSee('Kasir Budi');
    }

    public function test_halaman_dashboard_kasir_tanpa_data_toko(): void
    {
        $budi = Sale::query()->where('user_id', $this->kasirB->id)->latest('id')->first();

        $this->actingAs($this->kasirA)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Penjualan saya hari ini')
            ->assertSee('Rp 20.000')
            ->assertSee('Transaksi terakhir saya')
            ->assertDontSee($budi->number)
            ->assertDontSee('Omzet harian')
            ->assertDontSee('Produk terlaris')
            ->assertDontSee('Hutang jatuh tempo');
    }

    public function test_purchasing_melihat_hutang_dan_stok_tanpa_penjualan(): void
    {
        $data = app(DashboardService::class)->for($this->buatPengguna(RoleName::Purchasing));

        $this->assertNull($data['sales']);
        $this->assertNull($data['recentSales']);
        $this->assertNotNull($data['payables']);
        $this->assertNotNull($data['lowStock']);
    }

    public function test_peran_tanpa_bagian_dashboard_melihat_pintasan_modul(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Hrd))->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Absensi')
            ->assertDontSee('Penjualan hari ini')
            ->assertDontSee('Perlu diisi ulang');
    }
}
