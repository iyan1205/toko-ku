<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Enums\StockTransferStatus;
use App\Models\Branch;
use App\Models\Journal;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Scopes\BranchScope;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Inventory\StockTransferService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Fase 16c: transfer antar cabang dua langkah (dalam perjalanan), stok min/maks per gudang, saran pesan ulang → draft PO. */
class TransferCabangPesanUlangTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;

    private Warehouse $jabong;

    private Product $susu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        app(ChartOfAccountsService::class)->install();
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $utama = Branch::query()->where('is_default', true)->sole();
        $cabang = Branch::query()->create(['code' => 'JBG', 'name' => 'Cabang Jabong', 'is_active' => true, 'company_id' => $utama->company_id]);
        $this->jabong = Warehouse::factory()->create(['name' => 'Gudang Jabong', 'branch_id' => $cabang->id]);
        $this->susu = Product::factory()->create(['name' => 'Susu', 'purchase_price' => 5000]);
        app(StockService::class)->catat($this->susu, StockMovementType::Purchase, 20, null, null, $this->admin, null, ['unit_cost' => 500000]);
    }

    private function stok(Warehouse $w): float
    {
        return (float) WarehouseStock::query()->where('warehouse_id', $w->id)->where('product_id', $this->susu->id)->value('quantity');
    }

    public function test_transfer_antar_cabang_dikirim_lalu_diterima_dengan_kekurangan(): void
    {
        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_warehouse_id' => Warehouse::default()->id, 'to_warehouse_id' => $this->jabong->id, 'transfer_date' => now()->toDateString(),
            'items' => [['product_id' => $this->susu->id, 'quantity' => '10']],
        ])->assertSessionHasNoErrors();

        $transfer = StockTransfer::query()->sole();
        $this->assertSame(StockTransferStatus::InTransit, $transfer->status);
        $this->assertSame([10.0, 0.0], [$this->stok(Warehouse::default()), $this->stok($this->jabong)]);
        $this->get(route('stock-transfers.index'))->assertOk()->assertSee('1 transfer dalam perjalanan')->assertSee('Rp 50.000');

        $itemId = $transfer->items()->value('id');
        $this->post(route('stock-transfers.receive', $transfer), ['received' => [$itemId => '11']])->assertSessionHasErrors("received.{$itemId}");
        $this->post(route('stock-transfers.receive', $transfer), ['received' => [$itemId => '8'], 'receive_note' => '2 kotak penyok'])->assertSessionHas('success');

        $this->assertSame(StockTransferStatus::Completed, $transfer->fresh()->status);
        $this->assertSame([10.0, 8.0], [$this->stok(Warehouse::default()), $this->stok($this->jabong)]);

        $masuk = Journal::withoutGlobalScope(BranchScope::class)->where('source_type', 'stock_transfer')->where('source_event', 'transfer_in')->sole();
        $baris = $masuk->lines->mapWithKeys(fn ($l) => [$l->account->code => [$l->debit, $l->credit]])->all();
        $this->assertSame(['40000.00', '0.00'], $baris['1-1400']); // persediaan masuk 8 × 5.000
        $this->assertSame(['10000.00', '0.00'], $baris['5-2000']); // selisih 2 × 5.000
        $this->assertSame(['0.00', '50000.00'], $baris['1-1700']);

        $this->expectExceptionMessage('diterima dengan kekurangan');
        app(StockTransferService::class)->void($transfer->fresh(), 'Salah kirim', $this->admin);
    }

    public function test_transfer_dalam_perjalanan_dibatalkan_kembali_ke_gudang_asal_dan_satu_cabang_langsung_selesai(): void
    {
        $transfer = app(StockTransferService::class)->create([
            'from_warehouse_id' => Warehouse::default()->id, 'to_warehouse_id' => $this->jabong->id, 'transfer_date' => now()->toDateString(),
            'items' => [['product_id' => $this->susu->id, 'quantity' => '5']],
        ], $this->admin);
        app(StockTransferService::class)->void($transfer, 'Batal kirim', $this->admin);
        $this->assertSame([20.0, 0.0], [$this->stok(Warehouse::default()), $this->stok($this->jabong)]);
        $this->assertSame(0.0, abs(Journal::withoutGlobalScope(BranchScope::class)->get()->sum(fn ($j) => $j->lines->sum(fn ($l) => (float) $l->debit - (float) $l->credit))));

        $belakang = Warehouse::factory()->create(['name' => 'Gudang Belakang', 'branch_id' => Warehouse::default()->branch_id]);
        $lokal = app(StockTransferService::class)->create([
            'from_warehouse_id' => Warehouse::default()->id, 'to_warehouse_id' => $belakang->id, 'transfer_date' => now()->toDateString(),
            'items' => [['product_id' => $this->susu->id, 'quantity' => '5']],
        ], $this->admin);
        $this->assertSame(StockTransferStatus::Completed, $lokal->status);
        $this->assertSame(5.0, $this->stok($belakang));
    }

    public function test_saran_pesan_ulang_menghitung_pesanan_berjalan_dan_membuat_draft_po(): void
    {
        $supplier = Supplier::factory()->create(['name' => 'PT Susu']);
        $gudang = Warehouse::default();
        $this->actingAs($this->admin)->get(route('reorder.levels'))->assertOk()->assertSee('Susu');
        $this->put(route('reorder.levels.save'), ['warehouse_id' => $gudang->id, 'levels' => [$this->susu->id => ['min' => '30', 'max' => '25']]])->assertSessionHas('error');
        $this->put(route('reorder.levels.save'), ['warehouse_id' => $gudang->id, 'levels' => [$this->susu->id => ['min' => '40', 'max' => '60']]])->assertSessionHas('success');

        // Stok 20 + dipesan 15 (PO disetujui, belum datang) = 35 ≤ minimum 40: saran = 60 − 35 = 25.
        app(PurchaseOrderService::class)->create(['supplier_id' => $supplier->id, 'warehouse_id' => $gudang->id, 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->susu->id, 'quantity' => '15', 'unit_price' => '5000', 'discount' => '0']]], $this->admin, true);

        $this->get(route('reorder.index'))->assertOk()->assertSee('Susu')->assertViewHas('rows', fn ($rows) => $rows->sole()['suggested'] === 25.0 && $rows->sole()['supplier_id'] === null);

        $this->post(route('reorder.store'), ['lines' => [['selected' => '1', 'product_id' => $this->susu->id, 'warehouse_id' => $gudang->id, 'supplier_id' => '', 'quantity' => '25']]])
            ->assertSessionHas('error', 'Pilih supplier untuk setiap barang yang dicentang.');
        $this->post(route('reorder.store'), ['lines' => [['selected' => '1', 'product_id' => $this->susu->id, 'warehouse_id' => $gudang->id, 'supplier_id' => $supplier->id, 'quantity' => '25']]])
            ->assertRedirect(route('purchase-orders.index', ['status' => 'draft']));

        $draft = PurchaseOrder::query()->where('status', PurchaseOrderStatus::Draft->value)->sole();
        $this->assertSame(['25.000', '5000.00'], [$draft->items->sole()->quantity, $draft->items->sole()->unit_price]);
        $this->actingAs($this->buatPengguna(RoleName::Kasir))->get(route('reorder.index'))->assertForbidden();
    }
}
