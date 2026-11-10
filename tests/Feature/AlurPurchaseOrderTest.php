<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseStatus;
use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Events\GoodsReceived;
use App\Events\PurchaseReturned;
use App\Events\PurchaseVoided;
use App\Events\SupplierPaymentRecorded;
use App\Exceptions\AturanBisnisException;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PaymentService;
use App\Services\PurchaseService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\StockService;
use App\Support\Uang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Alur PO → penerimaan (parsial, per gudang) → stok → hutang → retur, di tingkat service. */
class AlurPurchaseOrderTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;

    private Supplier $supplier;

    private Warehouse $gudangB;

    private Product $ayam;

    private Product $nasi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->supplier = Supplier::factory()->create(['name' => 'PT Sumber']);
        $this->gudangB = Warehouse::factory()->create(['name' => 'Gudang Belakang']);
        $this->ayam = Product::factory()->create(['name' => 'Ayam', 'purchase_price' => 5000]);
        $this->nasi = Product::factory()->create(['name' => 'Nasi', 'purchase_price' => 2000]);
    }

    /** PO 10 ayam @7.000 (diskon baris 1.000) + 3 nasi @3.333, diskon 1.000, pajak 1.001, ongkir 500. */
    private function po(bool $setujui = true, array $ubah = []): PurchaseOrder
    {
        return app(PurchaseOrderService::class)->create(array_merge([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->gudangB->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->ayam->id, 'quantity' => '10', 'unit_price' => '7000', 'discount' => '1000'],
                ['product_id' => $this->nasi->id, 'quantity' => '3', 'unit_price' => '3333', 'discount' => '0'],
            ],
            'discount' => '1000',
            'tax' => '1001',
            'other_cost' => '500',
        ], $ubah), $this->admin, $setujui);
    }

    /** @param array<int, string> $jumlah product_id => jumlah diterima */
    private function terima(PurchaseOrder $po, array $jumlah, array $ubah = []): GoodsReceipt
    {
        $items = $po->items()->get()->mapWithKeys(fn ($i) => [$i->id => $jumlah[$i->product_id] ?? '0'])->all();

        return app(GoodsReceiptService::class)->create($po, array_merge([
            'warehouse_id' => $this->gudangB->id,
            'received_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'supplier_invoice_number' => null,
            'items' => $items,
        ], $ubah), $this->admin);
    }

    public function test_po_menghitung_total_dengan_subtotal_sebelum_diskon_baris(): void
    {
        $po = $this->po(setujui: false);

        $this->assertSame(PurchaseOrderStatus::Draft, $po->status);
        $this->assertSame('79999.00', $po->subtotal); // 70.000 + 9.999
        $this->assertSame('1000.00', $po->item_discount);
        $this->assertSame('79500.00', $po->grand_total); // 79.999 - 1.000 - 1.000 + 1.001 + 500
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_draft_tidak_bisa_diterima_dan_disetujui_baru_bisa(): void
    {
        $po = $this->po(setujui: false);

        $this->expectException(AturanBisnisException::class);
        $this->terima($po, [$this->ayam->id => '1']);
    }

    public function test_penerimaan_parsial_menambah_stok_gudang_tujuan_dan_mencatat_hutang(): void
    {
        Event::fake([GoodsReceived::class]);
        $po = $this->po();

        $gr = $this->terima($po, [$this->ayam->id => '4']);

        $this->assertSame(4.0, $this->ayam->fresh()->stockIn($this->gudangB));
        $this->assertSame(0.0, $this->ayam->fresh()->stockIn(Warehouse::default()));
        $this->assertSame(4.0, (float) $this->ayam->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->ayam->id, 'warehouse_id' => $this->gudangB->id,
            'type' => StockMovementType::Purchase->value, 'reference_type' => 'goods_receipt', 'reference_id' => $gr->id,
        ]);

        $faktur = $gr->purchase;
        $this->assertSame($po->id, $faktur->purchase_order_id);
        $this->assertSame($this->gudangB->id, $faktur->warehouse_id);
        $this->assertSame('28000.00', $faktur->subtotal);      // 4 x 7.000
        $this->assertSame('400.00', $faktur->item_discount);   // 4/10 dari 1.000
        $this->assertSame(PaymentStatus::Unpaid, $faktur->payment_status);
        $this->assertSame($faktur->grand_total, $faktur->balance);
        $this->assertSame(now()->addDays(30)->toDateString(), $faktur->due_date->toDateString());

        $this->assertSame(PurchaseOrderStatus::Partial, $po->fresh()->status);
        $this->assertSame('4.000', $po->items()->where('product_id', $this->ayam->id)->value('received_quantity'));
        Event::assertDispatched(GoodsReceived::class, fn (GoodsReceived $e) => $e->receipt->is($gr) && $e->purchase->is($faktur));
    }

    public function test_seluruh_penerimaan_menjumlah_tepat_sama_dengan_total_po(): void
    {
        $po = $this->po();

        $this->terima($po, [$this->ayam->id => '3', $this->nasi->id => '1']);
        $this->terima($po, [$this->ayam->id => '3']);
        $this->terima($po, [$this->ayam->id => '4', $this->nasi->id => '2']);

        $faktur = Purchase::where('purchase_order_id', $po->id)->get();
        $jumlah = fn (string $kolom) => $faktur->sum(fn ($p) => Uang::sen($p->{$kolom}));

        // Sisa pembulatan masuk ke penerimaan terakhir: total semua faktur = total PO, sampai ke sen.
        $this->assertSame(Uang::sen($po->grand_total), $jumlah('grand_total'));
        $this->assertSame(Uang::sen($po->item_discount), $jumlah('item_discount'));
        $this->assertSame(Uang::sen($po->discount), $jumlah('discount'));
        $this->assertSame(Uang::sen($po->tax), $jumlah('tax'));
        $this->assertSame(Uang::sen($po->other_cost), $jumlah('other_cost'));
        $this->assertSame(PurchaseOrderStatus::Completed, $po->fresh()->status);
        $this->assertSame(10.0, (float) $this->ayam->fresh()->stock);
    }

    public function test_jumlah_diterima_tidak_boleh_melebihi_sisa_pesanan(): void
    {
        $po = $this->po();
        $this->terima($po, [$this->ayam->id => '8']);

        try {
            $this->terima($po, [$this->ayam->id => '3']);
            $this->fail('Penerimaan melebihi sisa harus ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('melebihi sisa pesanan', collect($e->errors())->flatten()->first());
        }

        $this->assertSame(8.0, (float) $this->ayam->fresh()->stock);
        $this->assertSame(1, Purchase::count());
    }

    public function test_harga_beli_produk_mengikuti_harga_bersih_penerimaan(): void
    {
        $po = $this->po();
        $this->terima($po, [$this->ayam->id => '10']);

        // 10 x 7.000 - diskon baris 1.000 = 69.000 → 6.900 per unit.
        $this->assertSame('6900.00', $this->ayam->fresh()->purchase_price);
    }

    public function test_pembatalan_penerimaan_membalik_stok_hutang_dan_status_po(): void
    {
        Event::fake([PurchaseVoided::class]);
        $po = $this->po();
        $gr1 = $this->terima($po, [$this->ayam->id => '4']);
        $gr2 = $this->terima($po, [$this->ayam->id => '6']);

        app(GoodsReceiptService::class)->void($gr2, 'Salah input jumlah', $this->admin);

        $this->assertSame(4.0, $this->ayam->fresh()->stockIn($this->gudangB));
        $this->assertSame(PurchaseStatus::Voided, $gr2->fresh()->status);
        $this->assertSame(PurchaseStatus::Voided, $gr2->purchase->fresh()->status);
        $this->assertSame(PurchaseStatus::Completed, $gr1->purchase->fresh()->status);
        $this->assertSame(PurchaseOrderStatus::Partial, $po->fresh()->status);
        $this->assertSame('4.000', $po->items()->where('product_id', $this->ayam->id)->value('received_quantity'));
        Event::assertDispatched(PurchaseVoided::class, fn ($e) => $e->purchase->is($gr2->purchase));
    }

    public function test_penerimaan_tidak_bisa_dibatalkan_bila_barang_sudah_keluar_gudang(): void
    {
        $po = $this->po();
        $gr = $this->terima($po, [$this->ayam->id => '4']);
        app(StockService::class)->catat($this->ayam, StockMovementType::Damaged, -3, null, 'Busuk', $this->admin, $this->gudangB);

        $this->expectException(AturanBisnisException::class);
        $this->expectExceptionMessage('sudah keluar');

        app(GoodsReceiptService::class)->void($gr, 'Coba batalkan', $this->admin);
    }

    public function test_faktur_dari_penerimaan_tidak_bisa_dibatalkan_langsung(): void
    {
        $po = $this->po();
        $gr = $this->terima($po, [$this->ayam->id => '4']);

        $this->expectException(AturanBisnisException::class);
        $this->expectExceptionMessage('halaman penerimaan barang');

        app(PurchaseService::class)->batalkan($gr->purchase, 'Coba batalkan', $this->admin);
    }

    public function test_po_bisa_dibatalkan_hanya_sebelum_ada_penerimaan_lalu_ditutup_setelahnya(): void
    {
        $service = app(PurchaseOrderService::class);
        $kosong = $this->po();
        $this->assertSame(PurchaseOrderStatus::Cancelled, $service->cancel($kosong, 'Supplier tutup', $this->admin)->status);

        $po = $this->po();
        $this->terima($po, [$this->ayam->id => '4']);

        try {
            $service->cancel($po, 'Supplier tutup', $this->admin);
            $this->fail('PO yang sudah menerima barang tidak boleh dibatalkan.');
        } catch (AturanBisnisException) {
        }

        $ditutup = $service->close($po, 'Sisa tidak dikirim', $this->admin);
        $this->assertSame(PurchaseOrderStatus::Completed, $ditutup->status);
        $this->assertNotNull($ditutup->closed_at);
    }

    public function test_retur_disetujui_mengurangi_stok_dan_hutang(): void
    {
        Event::fake([PurchaseReturned::class]);
        $po = $this->po();
        $faktur = $this->terima($po, [$this->ayam->id => '10'])->purchase;
        $baris = $faktur->items()->where('product_id', $this->ayam->id)->first();
        $service = app(PurchaseReturnService::class);

        $draft = $service->create($faktur, [
            'warehouse_id' => $this->gudangB->id, 'return_date' => now()->toDateString(), 'reason' => 'Ayam busuk',
            'items' => [$baris->id => '2'],
        ], $this->admin);

        // Draft belum berpengaruh.
        $this->assertSame(10.0, (float) $this->ayam->fresh()->stock);
        $this->assertSame($faktur->balance, $faktur->fresh()->balance);
        $this->assertSame('13800.00', $draft->total); // 2/10 x 69.000

        $retur = $service->approve($draft, $this->admin);

        $this->assertSame(8.0, $this->ayam->fresh()->stockIn($this->gudangB));
        $this->assertSame('2.000', $baris->fresh()->returned_quantity);
        $this->assertSame('13800.00', $faktur->fresh()->returned_amount);
        $this->assertSame(Uang::keDesimal(Uang::sen($faktur->balance) - 1_380_000), $faktur->fresh()->balance);
        $this->assertSame('0.00', $retur->refund_amount);
        $this->assertDatabaseHas('stock_movements', ['reference_type' => 'purchase_return', 'reference_id' => $retur->id, 'quantity' => '-2.000']);
        Event::assertDispatched(PurchaseReturned::class);
    }

    public function test_retur_atas_faktur_lunas_dicatat_sebagai_refund(): void
    {
        $faktur = $this->terima($this->po(), [$this->ayam->id => '10'])->purchase;
        app(PaymentService::class)->bayarPembelian($faktur, Uang::sen($faktur->balance), PaymentMethod::Transfer, null, $this->admin);
        $baris = $faktur->items()->first();

        $retur = app(PurchaseReturnService::class)->create($faktur, [
            'warehouse_id' => $this->gudangB->id, 'return_date' => now()->toDateString(), 'reason' => 'Ayam busuk',
            'items' => [$baris->id => '10'],
        ], $this->admin, approve: true);

        $this->assertSame($baris->line_total, $retur->total); // seluruh baris: nilai baris persis
        $this->assertSame($retur->total, $retur->refund_amount);
        $this->assertSame('0.00', $faktur->fresh()->balance);
        $this->assertSame(PaymentStatus::Paid, $faktur->fresh()->payment_status);
    }

    public function test_retur_tidak_boleh_melebihi_jumlah_yang_dibeli_dikurangi_retur_sebelumnya(): void
    {
        $faktur = $this->terima($this->po(), [$this->ayam->id => '10'])->purchase;
        $baris = $faktur->items()->first();
        $service = app(PurchaseReturnService::class);
        $data = ['warehouse_id' => $this->gudangB->id, 'return_date' => now()->toDateString(), 'reason' => 'Ayam busuk'];

        $service->create($faktur, $data + ['items' => [$baris->id => '7']], $this->admin, approve: true);

        $this->expectException(ValidationException::class);
        $service->create($faktur, $data + ['items' => [$baris->id => '4']], $this->admin);
    }

    public function test_faktur_dengan_retur_disetujui_tidak_bisa_dibatalkan(): void
    {
        $po = $this->po();
        $gr = $this->terima($po, [$this->ayam->id => '10']);
        $baris = $gr->purchase->items()->first();
        app(PurchaseReturnService::class)->create($gr->purchase, [
            'warehouse_id' => $this->gudangB->id, 'return_date' => now()->toDateString(), 'reason' => 'Ayam busuk',
            'items' => [$baris->id => '1'],
        ], $this->admin, approve: true);

        $this->expectException(AturanBisnisException::class);
        $this->expectExceptionMessage('retur yang disetujui');

        app(GoodsReceiptService::class)->void($gr, 'Coba batalkan', $this->admin);
    }

    public function test_pembayaran_hutang_mengirim_event(): void
    {
        Event::fake([SupplierPaymentRecorded::class]);
        $faktur = $this->terima($this->po(), [$this->ayam->id => '10'])->purchase;

        $bayar = app(PaymentService::class)->bayarPembelian($faktur, 1_000_000, PaymentMethod::Cash, 'Cicilan 1', $this->admin);

        Event::assertDispatched(SupplierPaymentRecorded::class, fn ($e) => $e->payment->is($bayar) && $e->purchase->is($faktur));
        $this->assertSame(PaymentStatus::Partial, $faktur->fresh()->payment_status);
    }
}
