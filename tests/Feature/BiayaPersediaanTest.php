<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Exceptions\AturanBisnisException;
use App\Models\Journal;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Sale;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\SaleService;
use App\Services\StockService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Fase 16a: biaya persediaan (rata-rata / FIFO), batch & kedaluwarsa (FEFO), pembalik ke lot asal, transfer, nomor seri. */
class BiayaPersediaanTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private StockService $stok;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00'));
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->stok = app(StockService::class);
    }

    private function beli(Product $p, float $qty, int $hargaSen, array $opsi = [], ?Warehouse $gudang = null): void
    {
        $this->stok->catat($p, StockMovementType::Purchase, $qty, null, 'Beli', $this->admin, $gudang, ['unit_cost' => $hargaSen] + $opsi);
    }

    private function jual(Product $p, string $qty, array $ubah = []): Sale
    {
        return app(SaleService::class)->buat(array_merge([
            'customer_id' => null, 'items' => [['product_id' => $p->id, 'quantity' => $qty, 'discount' => '0']],
            'discount' => '0', 'payment_method' => 'cash', 'paid_amount' => '10000000',
        ], $ubah), $this->admin);
    }

    private function nilai(Product $p, ?Warehouse $gudang = null): float
    {
        return (float) WarehouseStock::query()->where('product_id', $p->id)->where('warehouse_id', ($gudang ?? Warehouse::default())->id)->value('value');
    }

    public function test_rata_rata_tertimbang_menjadi_hpp_penjualan(): void
    {
        $p = Product::factory()->create(['selling_price' => 5000, 'purchase_price' => 900]);
        $this->beli($p, 10, 100000);
        $this->beli($p, 10, 200000);
        $this->assertSame(30000.0, $this->nilai($p));

        $sale = $this->jual($p, '5');

        $this->assertSame('1500.00', $sale->items->first()->cost_price);
        $this->assertSame(22500.0, $this->nilai($p));
        $this->assertSame(['-7500.00', '1500.00'], [$sale->items->first()->product->stockMovements()->latest('id')->first()->total_cost, '1500.00']);
    }

    public function test_fifo_memakai_biaya_lot_tertua_dan_jurnal_hpp_mengikutinya(): void
    {
        app(ChartOfAccountsService::class)->install();
        setting(['inventory.costing_method' => 'fifo']);
        $p = Product::factory()->create(['selling_price' => 5000]);
        $this->beli($p, 10, 100000);
        $this->beli($p, 10, 200000);

        $sale = $this->jual($p, '15');

        $mutasi = $p->stockMovements()->where('type', 'sale')->sole();
        $this->assertSame('-20000.00', $mutasi->total_cost); // 10 × 1.000 + 5 × 2.000
        $this->assertSame(10000.0, $this->nilai($p));
        $this->assertSame([0.0, 5.0], StockLot::query()->where('product_id', $p->id)->orderBy('id')->pluck('quantity')->map(fn ($q) => (float) $q)->all());

        $jurnal = Journal::query()->where('source_type', $sale->getMorphClass())->where('source_id', $sale->id)->sole();
        $hpp = $jurnal->lines->first(fn ($l) => $l->account->type->value === 'cogs');
        $this->assertSame('20000.00', $hpp->debit);
    }

    public function test_pembatalan_dan_retur_mengembalikan_lot_dan_biaya_asal(): void
    {
        setting(['inventory.costing_method' => 'fifo']);
        $p = Product::factory()->create(['selling_price' => 5000]);
        $this->beli($p, 5, 100000);
        $this->beli($p, 5, 300000);

        $sale = $this->jual($p, '6');
        app(SaleService::class)->batalkan($sale, 'Salah input kasir', $this->admin);

        $this->assertSame([5.0, 5.0], StockLot::query()->where('product_id', $p->id)->orderBy('id')->pluck('quantity')->map(fn ($q) => (float) $q)->all());
        $this->assertSame(20000.0, $this->nilai($p));
        $this->assertSame('8000.00', $p->stockMovements()->where('quantity', '>', 0)->where('type', 'sale')->sole()->total_cost);
    }

    public function test_fefo_dan_batch_kedaluwarsa_tidak_dijual(): void
    {
        $p = Product::factory()->create(['selling_price' => 5000, 'track_batches' => true]);
        $this->beli($p, 5, 100000, ['batch_number' => 'b-lama', 'expiry_date' => '2027-06-30']);
        $this->beli($p, 5, 100000, ['batch_number' => 'B-DEKAT', 'expiry_date' => '2026-12-31']);
        $this->beli($p, 3, 100000, ['batch_number' => 'B-BASI', 'expiry_date' => '2026-11-01']);

        $this->jual($p, '6');

        $sisa = StockLot::query()->where('product_id', $p->id)->pluck('quantity', 'batch_number')->map(fn ($q) => (float) $q)->all();
        $this->assertEquals(['B-LAMA' => 4.0, 'B-DEKAT' => 0.0, 'B-BASI' => 3.0], $sisa);

        $this->expectException(AturanBisnisException::class);
        $this->expectExceptionMessage('yang belum kedaluwarsa tinggal 4');
        $this->jual($p, '5');
    }

    public function test_transfer_membawa_batch_dan_biaya_ke_gudang_tujuan(): void
    {
        $p = Product::factory()->create(['track_batches' => true]);
        $tujuan = Warehouse::query()->create(['code' => 'G2', 'name' => 'Gudang 2', 'branch_id' => Warehouse::default()->branch_id, 'is_active' => true]);
        $this->beli($p, 4, 250000, ['batch_number' => 'B1', 'expiry_date' => '2027-01-31']);

        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_warehouse_id' => Warehouse::default()->id, 'to_warehouse_id' => $tujuan->id, 'transfer_date' => '2026-11-16',
            'items' => [['product_id' => $p->id, 'quantity' => '3']],
        ])->assertSessionHasNoErrors();

        $lot = StockLot::query()->where('warehouse_id', $tujuan->id)->sole();
        $this->assertSame(['B1', '2027-01-31', '2500.00', 3.0], [$lot->batch_number, $lot->expiry_date->toDateString(), $lot->unit_cost, (float) $lot->quantity]);
        $this->assertSame(7500.0, $this->nilai($p, $tujuan));
        $this->assertSame(2500.0, $this->nilai($p));
    }

    public function test_nomor_seri_masuk_keluar_dan_kembali_saat_dibatalkan(): void
    {
        $p = Product::factory()->create(['selling_price' => 5000, 'track_serials' => true]);
        $this->beli($p, 2, 100000, ['serials' => ['SN-1', 'SN-2']]);
        $this->assertSame(2, ProductSerial::query()->where('status', 'in_stock')->count());

        try {
            $this->beli($p, 1, 100000, ['serials' => ['SN-1']]);
            $this->fail('Seri ganda harus ditolak.');
        } catch (AturanBisnisException $e) {
            $this->assertStringContainsString('SN-1', $e->getMessage());
        }

        $sale = $this->jual($p, '1');
        $this->assertSame(['SN-1' => 'out', 'SN-2' => 'in_stock'], ProductSerial::query()->orderBy('serial_number')->pluck('status', 'serial_number')->all());

        app(SaleService::class)->batalkan($sale, 'Salah input kasir', $this->admin);
        $this->assertSame(2, ProductSerial::query()->where('status', 'in_stock')->count());

        $this->expectExceptionMessage('bilangan bulat');
        $this->stok->catat($p, StockMovementType::Adjustment, 0.5, null, null, $this->admin);
    }

    public function test_penerimaan_barang_berbatch_wajib_nomor_batch_dan_membuat_lot(): void
    {
        $p = Product::factory()->create(['name' => 'Susu UHT', 'track_batches' => true]);
        $po = app(PurchaseOrderService::class)->create([
            'supplier_id' => Supplier::factory()->create()->id, 'warehouse_id' => Warehouse::default()->id, 'order_date' => '2026-11-16',
            'items' => [['product_id' => $p->id, 'quantity' => '12', 'unit_price' => '6000', 'discount' => '0']],
        ], $this->admin, true);
        $itemId = $po->items()->value('id');
        $kirim = fn (array $ubah) => $this->actingAs($this->admin)->post(route('goods-receipts.store', $po), array_merge([
            'warehouse_id' => Warehouse::default()->id, 'received_date' => '2026-11-16', 'due_date' => '2026-12-16', 'items' => [$itemId => '12'],
        ], $ubah));

        $kirim([])->assertSessionHasErrors("batches.{$itemId}.batch_number");
        $kirim(['batches' => [$itemId => ['batch_number' => 'uht-01', 'expiry_date' => '2027-02-01']]])->assertSessionHasNoErrors();

        $lot = StockLot::query()->where('product_id', $p->id)->sole();
        $this->assertSame(['UHT-01', '6000.00', 12.0], [$lot->batch_number, $lot->unit_cost, (float) $lot->quantity]);
        $this->get(route('products.show', $p))->assertOk()->assertSee('UHT-01');
    }

    public function test_pengaturan_inventori_halaman_kedaluwarsa_dan_pengingat(): void
    {
        $pemilik = $this->buatPengguna(RoleName::Pemilik);
        $this->actingAs($this->admin)->get(route('settings.inventory.edit'))->assertForbidden();
        $this->actingAs($pemilik)->put(route('settings.inventory.update'), [
            'costing_method' => 'fifo', 'block_expired_sales' => '1', 'expiry_warning_days' => '45', 'slow_moving_days' => '60',
        ])->assertRedirect(route('settings.inventory.edit'));
        $this->assertSame('fifo', $this->stok->costingMethod());

        $p = Product::factory()->create(['name' => 'Roti Tawar', 'track_batches' => true]);
        $this->beli($p, 5, 100000, ['batch_number' => 'R1', 'expiry_date' => '2026-12-20']);
        $this->beli($p, 5, 100000, ['batch_number' => 'R2', 'expiry_date' => '2027-12-20']);

        $this->actingAs($this->admin)->get(route('stock.expiring'))->assertOk()->assertSee('R1')->assertDontSee('R2');
        $this->artisan('stok:kedaluwarsa')->assertSuccessful();
        $this->assertSame(1, $this->admin->notifications()->where('data->type', 'inventory.expiry')->count());
    }

    public function test_penyesuaian_keluar_dari_batch_yang_dipilih(): void
    {
        $p = Product::factory()->create(['track_batches' => true]);
        $this->beli($p, 5, 100000, ['batch_number' => 'A', 'expiry_date' => '2027-01-01']);
        $this->beli($p, 5, 100000, ['batch_number' => 'B', 'expiry_date' => '2027-06-01']);
        $lotB = StockLot::query()->where('batch_number', 'B')->sole();

        $this->actingAs($this->admin)->post(route('stock.adjust.store'), [
            'warehouse_id' => Warehouse::default()->id, 'product_id' => $p->id, 'type' => 'damaged', 'quantity' => '2', 'note' => 'Kemasan sobek', 'lot_id' => $lotB->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(3.0, (float) $lotB->fresh()->quantity);
        $this->assertSame(5.0, (float) StockLot::query()->where('batch_number', 'A')->value('quantity'));
    }
}
