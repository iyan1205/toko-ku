<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Fase 16 poin 9: Laporan → Stok (nilai persediaan per tanggal, perputaran, lambat & mati, kartu stok per batch). */
class LaporanStokTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;

    private StockService $stok;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->stok = app(StockService::class);
    }

    /** Catat mutasi pada waktu tertentu; unit_cost dalam rupiah. */
    private function catat(string $waktu, Product $produk, StockMovementType $jenis, float $jumlah, ?int $biaya = null, array $opsi = []): void
    {
        $this->travelTo(CarbonImmutable::parse($waktu));
        $this->stok->catat($produk, $jenis, $jumlah, null, null, $this->admin, null, $opsi + ($biaya !== null ? ['unit_cost' => $biaya * 100] : []));
    }

    /** Baris daftar laporan: nama produk => baris. @return Collection<string, mixed> */
    private function baris(string $route, array $query = []): Collection
    {
        $response = $this->actingAs($this->admin)->get(route($route, $query))->assertOk();

        return collect($response->viewData('rows')->items())->keyBy(fn ($row) => $row->name ?? $row->lot?->batch_number);
    }

    public function test_nilai_persediaan_per_tanggal_dari_stok_sekarang_dikurangi_mutasi_sesudahnya(): void
    {
        $kopi = Product::factory()->create(['name' => 'Kopi Bubuk']);
        // Stok awal tanpa mutasi (sebelum Fase 16) tetap terhitung di semua tanggal.
        Product::factory()->stok(3)->create(['name' => 'Gula Pasir', 'purchase_price' => 5000]);
        $this->catat('2026-10-01 10:00', $kopi, StockMovementType::Purchase, 10, 10000);
        $this->catat('2026-10-15 13:00', $kopi, StockMovementType::Sale, -4);
        $this->catat('2026-11-01 09:00', $kopi, StockMovementType::Purchase, 5, 12000);
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00'));

        $awal = $this->baris('reports.stock.valuation', ['per' => '2026-09-30']);
        $this->assertSame(['Gula Pasir'], $awal->keys()->all());

        $oktober = $this->baris('reports.stock.valuation', ['per' => '2026-10-15']);
        $this->assertEquals([6.0, 60000.0], [(float) $oktober['Kopi Bubuk']->qty_at, (float) $oktober['Kopi Bubuk']->value_at]);
        $this->assertEquals(15000.0, (float) $oktober['Gula Pasir']->value_at);

        $response = $this->get(route('reports.stock.valuation'))->assertOk();
        $this->assertSame(['items' => 2, 'value' => 135000.0], $response->viewData('totals')); // kopi 60.000 + 60.000, gula 15.000
        $response->assertSee('Rp 135.000');
    }

    public function test_mutasi_lama_tanpa_biaya_dinilai_dengan_harga_beli(): void
    {
        // Saat Fase 16 dipasang tersisa 2 (dinilai harga beli); sebelumnya terjual 3 tanpa biaya tercatat.
        $teh = Product::factory()->stok(2)->create(['name' => 'Teh Lama', 'purchase_price' => 8000]);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00'));
        StockMovement::create([
            'warehouse_id' => Warehouse::default()->id, 'product_id' => $teh->id, 'type' => StockMovementType::Sale,
            'quantity' => -3, 'stock_before' => 5, 'stock_after' => 2,
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00'));

        $sebelum = $this->baris('reports.stock.valuation', ['per' => '2026-11-04']);
        $this->assertEquals([5.0, 40000.0], [(float) $sebelum['Teh Lama']->qty_at, (float) $sebelum['Teh Lama']->value_at]);
    }

    public function test_perputaran_menghitung_penjualan_bersih_retur_dan_bahan_produksi_tetapi_bukan_barang_rusak(): void
    {
        $kopi = Product::factory()->create(['name' => 'Kopi Bubuk']);
        $karkas = Product::factory()->create(['name' => 'Karkas', 'type' => 'bahan_baku']);
        $this->catat('2026-09-20 10:00', $kopi, StockMovementType::Purchase, 10, 10000);
        $this->catat('2026-10-15 10:00', $kopi, StockMovementType::Sale, -4);
        $this->catat('2026-10-20 10:00', $kopi, StockMovementType::SaleReturn, 1, 10000);
        $this->catat('2026-10-25 10:00', $kopi, StockMovementType::Damaged, -1);
        $this->catat('2026-11-05 10:00', $kopi, StockMovementType::Sale, -2); // sesudah periode
        $this->catat('2026-10-02 10:00', $karkas, StockMovementType::Purchase, 9, 1000);
        $this->catat('2026-10-05 10:00', $karkas, StockMovementType::Production, -3);
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00'));

        $response = $this->actingAs($this->admin)->get(route('reports.stock.turnover', ['dari' => '2026-10-01', 'sampai' => '2026-10-31']))->assertOk();
        $rows = collect($response->viewData('rows')->items())->keyBy('name');
        $list = $response->viewData('list');

        // Kopi: awal 10 × 10.000, akhir 6 × 10.000, keluar 4 - 1 retur = 3 (rusak tidak dihitung).
        $kopiRow = $rows['Kopi Bubuk'];
        $this->assertEquals([100000.0, 60000.0, 3.0, 30000.0], [(float) $kopiRow->opening_value, (float) $kopiRow->closing_value, (float) $kopiRow->usage_qty, (float) $kopiRow->usage_value]);
        $this->assertSame(80000.0, $list->average($kopiRow));
        $this->assertSame(0.38, $list::turnover(30000, 80000));
        $this->assertSame(82.7, $list->daysOfInventory(30000, 80000)); // 80.000 ÷ 30.000 × 31 hari

        // Karkas: bahan terpakai produksi = pemakaian.
        $this->assertEquals([0.0, 6000.0, 3000.0], [(float) $rows['Karkas']->opening_value, (float) $rows['Karkas']->closing_value, (float) $rows['Karkas']->usage_value]);
        $this->assertSame(['items' => 2, 'opening' => 100000.0, 'closing' => 66000.0, 'average' => 83000.0, 'usage' => 33000.0], $response->viewData('totals'));
    }

    public function test_barang_lambat_dan_mati_dinilai_dari_pemakaian_n_hari_terakhir(): void
    {
        $produk = fn (string $nama) => Product::factory()->create(['name' => $nama]);
        $mati = $produk('Sirup Melon');
        $lambat = $produk('Teh Celup');
        $normal = $produk('Air Mineral');
        $baru = $produk('Kopi Susu');
        Product::factory()->stok(4)->create(['name' => 'Gula Lama']); // stok awal tanpa mutasi dan tidak pernah keluar
        foreach ([[$mati, 10], [$lambat, 20], [$normal, 10]] as [$p, $n]) {
            $this->catat('2026-07-01 10:00', $p, StockMovementType::Purchase, $n, 5000);
        }
        $this->catat('2026-10-20 10:00', $lambat, StockMovementType::Sale, -5); // sisa 15 > keluar 5
        $this->catat('2026-10-20 10:00', $normal, StockMovementType::Sale, -8); // sisa 2 <= keluar 8
        $this->catat('2026-11-10 10:00', $baru, StockMovementType::Purchase, 5, 5000);
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00'));

        $response = $this->actingAs($this->admin)->get(route('reports.stock.slow-moving'))->assertOk();
        $rows = collect($response->viewData('rows')->items())->keyBy('name');
        $this->assertEqualsCanonicalizing(['Sirup Melon', 'Teh Celup', 'Gula Lama'], $rows->keys()->all());
        $this->assertSame(['mati', 'lambat', 'mati'], [$rows['Sirup Melon']->movement_status, $rows['Teh Celup']->movement_status, $rows['Gula Lama']->movement_status]);
        $this->assertSame(270.0, $response->viewData('list')->daysOfCover($rows['Teh Celup'])); // 15 × 90 ÷ 5
        $ringkasan = $response->viewData('summary');
        $this->assertSame([2, 1, 1, 1], [$ringkasan['mati']->items, $ringkasan['lambat']->items, $ringkasan['baru']->items, $ringkasan['normal']->items]);

        $semua = $this->baris('reports.stock.slow-moving', ['status' => 'semua']);
        $this->assertSame(['baru', 'normal'], [$semua['Kopi Susu']->movement_status, $semua['Air Mineral']->movement_status]);

        // Dengan periode 30 hari, penjualan 20 Okt masih masuk; penjualan sebelum 15 Okt tidak.
        $this->catat('2026-10-01 10:00', $mati, StockMovementType::Sale, -1);
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00'));
        $tigaPuluh = $this->baris('reports.stock.slow-moving', ['hari' => 30]);
        $this->assertSame(['mati', 'lambat'], [$tigaPuluh['Sirup Melon']->movement_status, $tigaPuluh['Teh Celup']->movement_status]);
    }

    public function test_kartu_stok_per_batch_menampilkan_saldo_setiap_batch_walau_disaring_tanggal(): void
    {
        $susu = Product::factory()->create(['name' => 'Susu UHT', 'track_batches' => true]);
        $biasa = Product::factory()->create(['name' => 'Roti Tawar']);
        $this->catat('2026-11-01 10:00', $susu, StockMovementType::Purchase, 10, 6000, ['batch_number' => 'B1', 'expiry_date' => '2027-01-31']);
        $this->catat('2026-11-02 10:00', $susu, StockMovementType::Purchase, 5, 6000, ['batch_number' => 'B2', 'expiry_date' => '2026-12-31']);
        $this->catat('2026-11-03 10:00', $biasa, StockMovementType::Purchase, 5, 3000);
        $this->catat('2026-11-05 10:00', $susu, StockMovementType::Sale, -7); // FEFO: B2 habis 5, lalu B1 2
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00'));

        $kartu = fn (array $query) => collect($this->actingAs($this->admin)->get(route('reports.stock.batch-card', $query))->assertOk()->viewData('rows')->items())
            ->map(fn ($r) => [$r->lot->batch_number, (float) $r->quantity, (float) $r->lot_balance])->all();

        $this->assertSame([['B2', 5.0, 5.0], ['B2', -5.0, 0.0], ['B1', 10.0, 10.0], ['B1', -2.0, 8.0]], $kartu([]));
        $this->assertSame([['B2', -5.0, 0.0], ['B1', -2.0, 8.0]], $kartu(['produk' => $susu->id, 'dari' => '2026-11-05']));
    }

    public function test_laporan_stok_butuh_izin_dan_ekspor_excel_memuat_baris_total(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Kasir))->get(route('reports.stock.valuation'))->assertForbidden();

        $kopi = Product::factory()->create(['name' => 'Kopi Bubuk']);
        $this->catat('2026-10-01 10:00', $kopi, StockMovementType::Purchase, 10, 10000);
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00'));

        $gudang = $this->buatPengguna(RoleName::StafGudang);
        $response = $this->actingAs($gudang)->get(route('reports.stock.export', ['report' => 'nilai-persediaan', 'format' => 'xlsx', 'per' => '2026-10-31']))->assertOk();
        $sheet = IOFactory::load($response->getFile()->getPathname())->getActiveSheet();
        $this->assertEquals(['Kopi Bubuk', 100000], [$sheet->getCell('B2')->getValue(), $sheet->getCell('G2')->getValue()]);
        $this->assertEquals(['Total', 100000], [$sheet->getCell('A3')->getValue(), $sheet->getCell('G3')->getValue()]);

        foreach (['perputaran', 'lambat-mati', 'kartu-batch'] as $laporan) {
            $this->get(route('reports.stock.export', ['report' => $laporan, 'format' => 'pdf']))->assertOk()->assertDownload();
        }
    }
}
