<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Enums\SaleReturnStatus;
use App\Events\SaleReturned;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Retur penjualan: kasir mengajukan, atasan menyetujui; stok kembali ke gudang penjualan, piutang dikurangi dulu. */
class ReturPenjualanTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $kasir;

    private User $admin;

    private Product $ayam; // 10.000, stok 20

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->ayam = Product::factory()->stok(20)->create(['name' => 'Ayam Geprek', 'selling_price' => 10000]);
        Customer::umum();
    }

    /** 2 Ayam = 20.000, diskon transaksi 2.000 -> total 18.000. */
    private function jual(array $ubah = []): Sale
    {
        $this->actingAs($this->kasir)->postJson(route('sales.store'), array_merge([
            'customer_id' => null,
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '2', 'discount' => '0']],
            'discount' => '2000',
            'payments' => [['method' => 'cash', 'amount' => '18000']],
        ], $ubah))->assertCreated();

        return Sale::with('items')->latest('id')->firstOrFail();
    }

    private function ajukan(Sale $jual, string $jumlah = '1', array $ubah = []): SaleReturn
    {
        $this->actingAs($this->kasir)->post(route('sale-returns.store'), array_merge([
            'sale_id' => $jual->id,
            'reason' => 'Ayam kurang matang',
            'items' => [$jual->items->sole()->id => $jumlah],
        ], $ubah))->assertSessionHasNoErrors();

        return SaleReturn::latest('id')->firstOrFail();
    }

    private function stokGudangUtama(): float
    {
        return (float) WarehouseStock::where('warehouse_id', Warehouse::default()->id)->where('product_id', $this->ayam->id)->value('quantity');
    }

    public function test_pengajuan_kasir_belum_mengubah_stok_maupun_uang(): void
    {
        $jual = $this->jual();

        $retur = $this->ajukan($jual);

        $this->assertSame(SaleReturnStatus::Draft, $retur->status);
        $this->assertSame('9000.00', $retur->total, 'Diskon transaksi ikut terbagi: 1 dari 2 ayam = 18.000 / 2.');
        $this->assertSame(18.0, $this->stokGudangUtama());
        $this->assertSame('0.00', $jual->fresh()->returned_amount);
    }

    public function test_retur_disetujui_mengembalikan_stok_ke_gudang_penjualan_dan_uang_ke_pelanggan(): void
    {
        Event::fake([SaleReturned::class]);
        $retur = $this->ajukan($this->jual());

        $this->actingAs($this->admin)
            ->post(route('sale-returns.approve', $retur), ['refund_method' => 'cash'])
            ->assertRedirect(route('sale-returns.show', $retur));

        $retur->refresh();
        $this->assertSame(SaleReturnStatus::Approved, $retur->status);
        $this->assertSame('9000.00', $retur->refund_amount);
        $this->assertSame(19.0, $this->stokGudangUtama());
        $this->assertSame('9000.00', $retur->sale->returned_amount);
        $refund = Payment::where('payable_type', $retur->getMorphClass())->where('payable_id', $retur->id)->sole();
        $this->assertSame(PaymentMethod::Cash, $refund->method);
        $this->assertSame('9000.00', $refund->amount);
        Event::assertDispatched(SaleReturned::class, fn (SaleReturned $e) => $e->saleReturn->is($retur));
    }

    public function test_retur_atas_penjualan_piutang_mengurangi_piutang_dulu_lalu_sisanya_dikembalikan(): void
    {
        $pelanggan = Customer::factory()->create();
        $jual = $this->jual(['customer_id' => $pelanggan->id, 'payment_method' => 'receivable', 'payments' => [['method' => 'cash', 'amount' => '15000']]]);
        $retur = $this->ajukan($jual);

        $this->actingAs($this->admin)->post(route('sale-returns.approve', $retur), ['refund_method' => 'cash']);

        $retur->refresh();
        $jual->refresh();
        $this->assertSame('3000.00', $retur->receivable_reduction);
        $this->assertSame('6000.00', $retur->refund_amount);
        $this->assertSame('0.00', $jual->balance);
        $this->assertSame(PaymentStatus::Paid, $jual->payment_status);
        $this->assertSame('0.00', $pelanggan->fresh()->receivable_balance);
    }

    public function test_kasir_tidak_bisa_menyetujui_retur(): void
    {
        $retur = $this->ajukan($this->jual());

        $this->actingAs($this->kasir)->post(route('sale-returns.approve', $retur))->assertForbidden();

        $this->assertSame(SaleReturnStatus::Draft, $retur->fresh()->status);
        $this->assertSame(18.0, $this->stokGudangUtama());
    }

    public function test_aksi_setujui_dari_kasir_tetap_tersimpan_sebagai_draft(): void
    {
        $retur = $this->ajukan($this->jual(), '1', ['aksi' => 'approve']);

        $this->assertSame(SaleReturnStatus::Draft, $retur->status);
        $this->assertSame(18.0, $this->stokGudangUtama());
    }

    public function test_jumlah_retur_melebihi_yang_dibeli_ditolak(): void
    {
        $jual = $this->jual();
        $itemId = $jual->items->sole()->id;

        $this->actingAs($this->kasir)
            ->post(route('sale-returns.store'), ['sale_id' => $jual->id, 'reason' => 'Ayam kurang matang', 'items' => [$itemId => '3']])
            ->assertSessionHasErrors(["items.{$itemId}" => 'Ayam Geprek: jumlah retur melebihi sisa yang bisa diretur (2 '.$jual->items->sole()->unit_name.').']);

        $this->assertSame(0, SaleReturn::count());
    }

    public function test_penjualan_yang_sudah_punya_retur_tidak_bisa_dibatalkan(): void
    {
        $retur = $this->ajukan($this->jual());
        $this->actingAs($this->admin)->post(route('sale-returns.approve', $retur), ['refund_method' => 'cash']);

        $this->actingAs($this->admin)
            ->post(route('sales.void', $retur->sale_id), ['alasan' => 'Salah input transaksi'])
            ->assertSessionHas('error');

        $this->assertSame(19.0, $this->stokGudangUtama());
        $this->assertFalse($retur->sale->fresh()->dibatalkan());
    }
}
