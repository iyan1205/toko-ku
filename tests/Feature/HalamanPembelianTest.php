<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseReturnStatus;
use App\Enums\RoleName;
use App\Events\GoodsReceived;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Halaman Pembelian: PO, penerimaan barang, faktur, retur, hutang supplier, dan ekspor daftar. */
class HalamanPembelianTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;

    private Supplier $supplier;

    private Warehouse $belakang;

    private Product $ayam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->supplier = Supplier::factory()->create(['name' => 'PT Sumber Rejeki']);
        $this->belakang = Warehouse::factory()->create(['name' => 'Gudang Belakang']);
        $this->ayam = Product::factory()->create(['name' => 'Ayam Geprek', 'purchase_price' => 5000]);
    }

    private function stokGudang(Warehouse $gudang): float
    {
        return (float) WarehouseStock::where('warehouse_id', $gudang->id)->where('product_id', $this->ayam->id)->value('quantity');
    }

    /** @param array<string, mixed> $ubah */
    private function payloadPo(array $ubah = []): array
    {
        return array_merge([
            'aksi' => 'approve',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->belakang->id,
            'order_date' => now()->format('Y-m-d'),
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '10', 'unit_price' => '7000', 'discount' => '0']],
        ], $ubah);
    }

    /** Pembelian langsung belum dibayar, 10 x Rp 7.000, jatuh tempo $jatuhTempo hari lagi. */
    private function faktur(int $jatuhTempo = 30, ?Warehouse $gudang = null): Purchase
    {
        return app(PurchaseService::class)->buat([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => ($gudang ?? $this->belakang)->id,
            'purchase_date' => now()->subDays(40)->format('Y-m-d'),
            'due_date' => now()->addDays($jatuhTempo)->format('Y-m-d'),
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '10', 'unit_price' => '7000', 'discount' => '0']],
            'discount' => '0', 'tax' => '0', 'other_cost' => '0', 'paid_amount' => '0', 'update_cost' => false,
        ], $this->admin);
    }

    // ---------------- PO → penerimaan barang ----------------

    public function test_po_disetujui_lalu_diterima_sebagian_menambah_stok_dan_mencatat_hutang(): void
    {
        Event::fake([GoodsReceived::class]);
        $this->actingAs($this->admin)->post(route('purchase-orders.store'), $this->payloadPo())->assertSessionHasNoErrors();
        $po = PurchaseOrder::sole();
        $this->get(route('goods-receipts.create', ['po' => $po->id]))->assertOk()->assertSee('Ayam Geprek');

        $response = $this->post(route('goods-receipts.store', $po), [
            'warehouse_id' => $this->belakang->id,
            'received_date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(14)->format('Y-m-d'),
            'items' => [$po->items->sole()->id => '4'],
        ]);

        $faktur = Purchase::sole();
        $response->assertRedirect(route('goods-receipts.show', $faktur->goodsReceipt));
        $this->assertSame(PurchaseOrderStatus::Partial, $po->fresh()->status);
        $this->assertSame(4.0, $this->stokGudang($this->belakang));
        $this->assertSame('28000.00', $faktur->balance);
        $this->assertSame($po->id, $faktur->purchase_order_id);
        Event::assertDispatched(GoodsReceived::class, fn (GoodsReceived $e) => $e->purchase->is($faktur));
        $this->get(route('payables.index'))->assertOk()->assertSee($faktur->number)->assertSee('Rp 28.000');
    }

    public function test_purchasing_bisa_membuat_draft_tetapi_tidak_bisa_menyetujui_po(): void
    {
        $purchasing = $this->buatPengguna(RoleName::Purchasing);
        $this->actingAs($purchasing);

        $this->post(route('purchase-orders.store'), $this->payloadPo())->assertForbidden();
        $this->post(route('purchase-orders.store'), $this->payloadPo(['aksi' => 'draft']))->assertSessionHasNoErrors();
        $po = PurchaseOrder::sole();
        $this->post(route('purchase-orders.approve', $po))->assertForbidden();

        $this->assertSame(PurchaseOrderStatus::Draft, $po->fresh()->status);
    }

    public function test_cetak_po_menghasilkan_pdf(): void
    {
        $this->actingAs($this->admin)->post(route('purchase-orders.store'), $this->payloadPo());

        $this->get(route('purchase-orders.print', PurchaseOrder::sole()))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    // ---------------- Faktur & hutang ----------------

    public function test_pembelian_langsung_masuk_ke_gudang_yang_dipilih(): void
    {
        $this->actingAs($this->admin)->post(route('purchases.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->belakang->id,
            'purchase_date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '3', 'unit_price' => '7000']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(3.0, $this->stokGudang($this->belakang));
        $this->assertSame(0.0, $this->stokGudang(Warehouse::default()));
        $this->assertSame($this->belakang->id, Purchase::sole()->warehouse_id);
    }

    public function test_bayar_hutang_sebagian_dari_halaman_hutang(): void
    {
        $faktur = $this->faktur();

        $this->actingAs($this->admin)
            ->from(route('payables.index'))
            ->post(route('payables.store', $faktur), ['amount' => '30000', 'method' => 'transfer', 'paid_at' => now()->format('Y-m-d'), 'note' => 'Transfer BCA'])
            ->assertRedirect(route('payables.index'))
            ->assertSessionHas('success');

        $faktur->refresh();
        $this->assertSame('40000.00', $faktur->balance);
        $this->assertSame(PaymentStatus::Partial, $faktur->payment_status);
        $this->assertDatabaseHas('payments', ['payable_id' => $faktur->id, 'amount' => '30000.00', 'method' => 'transfer', 'note' => 'Transfer BCA', 'user_id' => $this->admin->id]);
    }

    public function test_bayar_melebihi_sisa_hutang_ditolak(): void
    {
        $faktur = $this->faktur();

        $this->actingAs($this->admin)
            ->post(route('payables.store', $faktur), ['amount' => '70000.01', 'method' => 'cash', 'paid_at' => now()->format('Y-m-d')])
            ->assertSessionHas('error', 'Pembayaran melebihi sisa hutang. Sisa hutang Rp 70.000.');

        $this->assertSame('70000.00', $faktur->fresh()->balance);
        $this->assertSame(0, Payment::count());
    }

    public function test_tanggal_bayar_tidak_boleh_di_masa_depan(): void
    {
        $faktur = $this->faktur();

        $this->actingAs($this->admin)
            ->post(route('payables.store', $faktur), ['amount' => '1000', 'method' => 'cash', 'paid_at' => now()->addDay()->format('Y-m-d')])
            ->assertSessionHasErrors(['paid_at' => 'Tanggal bayar tidak boleh lebih dari hari ini.']);

        $this->assertSame(0, Payment::count());
    }

    public function test_purchasing_bisa_melihat_hutang_tetapi_tidak_bisa_membayar(): void
    {
        $faktur = $this->faktur();
        $purchasing = $this->buatPengguna(RoleName::Purchasing);

        // URL di atribut Alpine ditulis lewat Js::from, sehingga "/" menjadi "\/".
        $urlBayar = str_replace('/', '\\/', route('payables.store', $faktur));
        $this->actingAs($this->admin)->get(route('payables.index'))->assertSee($urlBayar, false);

        $this->actingAs($purchasing)->get(route('payables.index'))->assertOk()->assertSee($faktur->number)->assertDontSee($urlBayar, false);
        $this->post(route('payables.store', $faktur), ['amount' => '1000', 'method' => 'cash', 'paid_at' => now()->format('Y-m-d')])->assertForbidden();

        $this->assertSame(0, Payment::count());
    }

    public function test_filter_hutang_yang_sudah_jatuh_tempo(): void
    {
        $lewat = $this->faktur(-2);
        $nanti = $this->faktur(20);

        $this->actingAs($this->admin)
            ->get(route('payables.index', ['jatuh_tempo' => 'lewat']))
            ->assertOk()
            ->assertSee($lewat->number)
            ->assertDontSee($nanti->number);
    }

    // ---------------- Retur ----------------

    public function test_retur_disetujui_lewat_halaman_mengurangi_stok_gudang_dan_hutang(): void
    {
        $faktur = $this->faktur();

        $this->actingAs($this->admin)->post(route('purchase-returns.store'), [
            'aksi' => 'approve',
            'purchase_id' => $faktur->id,
            'warehouse_id' => $this->belakang->id,
            'return_date' => now()->format('Y-m-d'),
            'reason' => 'Ayam rusak saat tiba',
            'items' => [$faktur->items->sole()->id => '2'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(PurchaseReturnStatus::Approved, PurchaseReturn::sole()->status);
        $this->assertSame(8.0, $this->stokGudang($this->belakang));
        $this->assertSame('56000.00', $faktur->fresh()->balance);
    }

    public function test_purchasing_tidak_bisa_langsung_menyetujui_retur(): void
    {
        $faktur = $this->faktur();
        $purchasing = $this->buatPengguna(RoleName::Purchasing);

        $this->actingAs($purchasing)->post(route('purchase-returns.store'), [
            'aksi' => 'approve',
            'purchase_id' => $faktur->id,
            'warehouse_id' => $this->belakang->id,
            'return_date' => now()->format('Y-m-d'),
            'reason' => 'Ayam rusak saat tiba',
            'items' => [$faktur->items->sole()->id => '2'],
        ])->assertForbidden();

        $this->assertSame(0, PurchaseReturn::count());
        $this->assertSame(10.0, $this->stokGudang($this->belakang));
    }

    // ---------------- Ekspor daftar ----------------

    /** @return array<string, array{string}> */
    public static function daftarYangBisaDiekspor(): array
    {
        return collect([
            'products.export', 'categories.export', 'units.export', 'recipes.export', 'warehouses.export',
            'stock.export', 'stock.movements.export', 'stock-transfers.export', 'stock-opnames.export',
            'suppliers.export', 'purchase-orders.export', 'goods-receipts.export', 'purchases.export',
            'purchase-returns.export', 'payables.export',
        ])->mapWithKeys(fn (string $route) => [$route => [$route]])->all();
    }

    #[DataProvider('daftarYangBisaDiekspor')]
    public function test_daftar_bisa_diekspor_ke_excel_dan_pdf(string $route): void
    {
        $this->faktur();
        $pemilik = $this->buatPengguna(RoleName::Pemilik);
        $this->actingAs($pemilik);

        $this->get(route($route, ['format' => 'xlsx']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->get(route($route, ['format' => 'pdf']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_format_ekspor_yang_tidak_dikenal_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->get(route('purchases.export', ['format' => 'csv']))
            ->assertSessionHasErrors('format');
    }
}
