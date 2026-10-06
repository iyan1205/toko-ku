<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Enums\PurchaseStatus;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class PembatalanPembelianTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;
    private Supplier $supplier;
    private Product $p1;
    private Product $p2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->supplier = Supplier::factory()->create(['name' => 'PT Sumber Rejeki']);
        $this->p1 = Product::factory()->create(['name' => 'Ayam Geprek']);
        $this->p2 = Product::factory()->create(['name' => 'Nasi Putih']);
    }

    private function buatPembelian(array $ubah = []): Purchase
    {
        return app(PurchaseService::class)->buat(array_merge([
            'supplier_id' => $this->supplier->id,
            'supplier_invoice_number' => 'INV-001',
            'purchase_date' => now()->format('Y-m-d'),
            'items' => [
                ['product_id' => $this->p1->id, 'quantity' => '10', 'unit_price' => '8000', 'discount' => '0'],
                ['product_id' => $this->p2->id, 'quantity' => '5', 'unit_price' => '3000', 'discount' => '0'],
            ],
            'discount' => '0', 'tax' => '0', 'other_cost' => '0',
            'paid_amount' => '95000', 'payment_method' => 'cash', 'update_cost' => true,
        ], $ubah), $this->admin);
    }

    public function test_pembatalan_mengembalikan_stok_menandai_pembayaran_dan_menyimpan_jejak(): void
    {
        $pembelian = $this->buatPembelian();

        $this->actingAs($this->admin)
            ->post(route('purchases.void', $pembelian), ['alasan' => 'Salah input faktur'])
            ->assertRedirect(route('purchases.show', $pembelian))
            ->assertSessionHas('success');

        $pembelian->refresh();
        $this->assertSame(PurchaseStatus::Voided, $pembelian->status);
        $this->assertSame($this->admin->id, $pembelian->voided_by);
        $this->assertSame('Salah input faktur', $pembelian->void_reason);
        $this->assertNotNull($pembelian->voided_at);
        $this->assertSame('95000.00', $pembelian->paid_amount, 'Angka historis tidak diubah.');

        $this->assertSame(0.0, (float) $this->p1->fresh()->stock);
        $this->assertSame(0.0, (float) $this->p2->fresh()->stock);

        $balik = StockMovement::where('quantity', '<', 0)->get();
        $this->assertCount(2, $balik);
        foreach ($balik as $m) {
            $this->assertSame('purchase', $m->reference_type);
            $this->assertSame($pembelian->id, $m->reference_id);
            $this->assertStringContainsString('Pembatalan pembelian', $m->note);
            $this->assertStringContainsString('Salah input faktur', $m->note);
        }
        // Dua mutasi masuk + dua pembalik: tidak ada yang dihapus.
        $this->assertDatabaseCount('stock_movements', 4);

        $bayar = Payment::firstOrFail();
        $this->assertNotNull($bayar->voided_at);
        $this->assertSame($this->admin->id, $bayar->voided_by);
        $this->assertSame('95000.00', $bayar->amount, 'Catatan pembayaran tetap utuh.');
    }

    public function test_alasan_wajib_dan_pembelian_tetap_utuh_bila_ditolak(): void
    {
        $pembelian = $this->buatPembelian();

        $this->actingAs($this->admin)
            ->post(route('purchases.void', $pembelian), ['alasan' => 'ab'])
            ->assertSessionHasErrors('alasan');

        $this->assertSame(PurchaseStatus::Completed, $pembelian->fresh()->status);
        $this->assertSame(10.0, (float) $this->p1->fresh()->stock);
    }

    public function test_pembelian_tidak_dapat_dibatalkan_dua_kali(): void
    {
        $pembelian = $this->buatPembelian();

        $this->actingAs($this->admin);
        $this->post(route('purchases.void', $pembelian), ['alasan' => 'Salah input'])->assertSessionHas('success');
        $this->post(route('purchases.void', $pembelian), ['alasan' => 'Salah input lagi'])->assertSessionHas('error');

        $this->assertStringContainsString('sudah dibatalkan', session('error'));
        $this->assertDatabaseCount('stock_movements', 4);
    }

    public function test_pembatalan_ditolak_bila_barang_sudah_terjual_dan_tidak_ada_yang_berubah(): void
    {
        $pembelian = $this->buatPembelian();
        // Produk kedua sudah terjual 4 dari 5: pembalikan -5 akan gagal SETELAH produk pertama dibalik.
        app(StockService::class)->catat($this->p2, StockMovementType::Sale, -4, null, null, $this->admin);

        $this->actingAs($this->admin)
            ->post(route('purchases.void', $pembelian), ['alasan' => 'Salah input faktur'])
            ->assertSessionHas('error');

        $pesan = session('error');
        $this->assertStringContainsString('tidak dapat dibatalkan', $pesan);
        $this->assertStringContainsString('Stok tidak mencukupi', $pesan);
        $this->assertStringContainsString('Retur Pembelian', $pesan);

        $this->assertSame(PurchaseStatus::Completed, $pembelian->fresh()->status);
        $this->assertSame(10.0, (float) $this->p1->fresh()->stock, 'Pembalikan produk pertama harus ikut dibatalkan.');
        $this->assertSame(1.0, (float) $this->p2->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertNull(Payment::firstOrFail()->voided_at);
    }

    public function test_hutang_supplier_tidak_menghitung_pembelian_yang_dibatalkan(): void
    {
        $pembelian = $this->buatPembelian(['paid_amount' => '0', 'payment_method' => null, 'due_date' => now()->addDays(30)->format('Y-m-d')]);
        $this->assertSame(95000.0, (float) $this->supplier->purchases()->selesai()->sum('balance'));

        app(PurchaseService::class)->batalkan($pembelian, 'Salah supplier', $this->admin);

        $this->assertSame(0.0, (float) $this->supplier->purchases()->selesai()->sum('balance'));

        $this->actingAs($this->admin)
            ->get(route('suppliers.show', $this->supplier))
            ->assertOk()
            ->assertSee('Dibatalkan');

        // Supplier yang hanya punya pembelian dibatalkan boleh dihapus.
        $this->delete(route('suppliers.destroy', $this->supplier))->assertSessionHas('success');
    }

    public function test_nomor_faktur_dapat_dipakai_lagi_setelah_pembelian_dibatalkan(): void
    {
        $pembelian = $this->buatPembelian();

        $this->actingAs($this->admin);
        $payload = [
            'supplier_id' => $this->supplier->id, 'supplier_invoice_number' => 'INV-001',
            'purchase_date' => now()->format('Y-m-d'),
            'items' => [['product_id' => $this->p1->id, 'quantity' => '1', 'unit_price' => '1000', 'discount' => '0']],
            'discount' => '0', 'tax' => '0', 'other_cost' => '0', 'paid_amount' => '1000', 'payment_method' => 'cash',
        ];

        $this->post(route('purchases.store'), $payload)->assertSessionHasErrors('supplier_invoice_number');

        $this->post(route('purchases.void', $pembelian), ['alasan' => 'Salah input faktur']);

        $this->post(route('purchases.store'), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('purchases', 2);
    }

    public function test_halaman_dan_filter_menampilkan_pembelian_yang_dibatalkan(): void
    {
        $pembelian = $this->buatPembelian();
        app(PurchaseService::class)->batalkan($pembelian, 'Salah input faktur', $this->admin);

        $this->actingAs($this->admin);

        $this->get(route('purchases.show', $pembelian))
            ->assertOk()
            ->assertSee('Pembelian ini dibatalkan')
            ->assertSee('Salah input faktur')
            ->assertDontSee('Batalkan pembelian ini');

        $this->get(route('purchases.index', ['status' => 'voided']))->assertSee($pembelian->number);
        $this->get(route('purchases.index', ['status' => 'completed']))->assertDontSee($pembelian->number);
    }

    public function test_stok_minus_yang_diizinkan_konfigurasi_membuat_pembatalan_tetap_berjalan(): void
    {
        $pembelian = $this->buatPembelian();
        app(StockService::class)->catat($this->p2, StockMovementType::Sale, -4, null, null, $this->admin);
        config(['pos.allow_negative_stock' => true]);

        app(PurchaseService::class)->batalkan($pembelian, 'Salah input faktur', $this->admin);

        $this->assertSame(-4.0, (float) $this->p2->fresh()->stock);
    }
}
