<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Enums\RoleName;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;
use ValueError;

class PembelianTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;
    private User $kasir;
    private Supplier $supplier;
    private Product $p1;
    private Product $p2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
        $this->supplier = Supplier::factory()->create(['name' => 'PT Sumber Rejeki']);
        $this->p1 = Product::factory()->create(['name' => 'Ayam Geprek', 'sku' => 'AYM-1', 'purchase_price' => 7000]);
        $this->p2 = Product::factory()->create(['name' => 'Nasi Putih', 'sku' => 'NSI-1', 'purchase_price' => 2000]);
    }

    /**
     * Subtotal = (10 x 8.000) + (5 x 3.000 - 500) = 80.000 + 14.500 = 94.500
     * Grand total = 94.500 - 1.000 + 0 + 2.000 = 95.500
     */
    private function payload(array $ubah = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier->id,
            'supplier_invoice_number' => 'INV-001',
            'purchase_date' => now()->format('Y-m-d'),
            'due_date' => null,
            'items' => [
                ['product_id' => $this->p1->id, 'quantity' => '10', 'unit_price' => '8000', 'discount' => '0'],
                ['product_id' => $this->p2->id, 'quantity' => '5', 'unit_price' => '3000', 'discount' => '500'],
            ],
            'discount' => '1000',
            'tax' => '0',
            'other_cost' => '2000',
            'paid_amount' => '95500',
            'payment_method' => 'cash',
            'update_cost' => '1',
            'note' => 'Faktur pertama',
        ], $ubah);
    }

    private function buatPembelian(array $ubah = []): Purchase
    {
        return app(PurchaseService::class)->buat($this->payload($ubah), $this->admin);
    }

    private function besar(string $nilai): float
    {
        return (float) $nilai;
    }

    public function test_pembelian_lunas_tunai_menyimpan_semuanya_dengan_benar(): void
    {
        $this->actingAs($this->admin)
            ->post(route('purchases.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $pembelian = Purchase::with(['items', 'payments'])->firstOrFail();

        $this->assertSame('PUR-' . now()->format('Ymd') . '-0001', $pembelian->number);
        $this->assertSame('94500.00', $pembelian->subtotal);
        $this->assertSame('1000.00', $pembelian->discount);
        $this->assertSame('2000.00', $pembelian->other_cost);
        $this->assertSame('95500.00', $pembelian->grand_total);
        $this->assertSame('95500.00', $pembelian->paid_amount);
        $this->assertSame('0.00', $pembelian->balance);
        $this->assertSame(PaymentStatus::Paid, $pembelian->payment_status);
        $this->assertSame(PurchaseStatus::Completed, $pembelian->status);
        $this->assertSame($this->admin->id, $pembelian->user_id);
        $this->assertSame('INV-001', $pembelian->supplier_invoice_number);

        $this->assertCount(2, $pembelian->items);
        $baris2 = $pembelian->items->firstWhere('product_id', $this->p2->id);
        $this->assertSame('Nasi Putih', $baris2->product_name);
        $this->assertSame('NSI-1', $baris2->sku);
        $this->assertSame('5.000', $baris2->quantity);
        $this->assertSame('3000.00', $baris2->unit_price);
        $this->assertSame('500.00', $baris2->discount);
        $this->assertSame('14500.00', $baris2->line_total);

        $this->assertCount(1, $pembelian->payments);
        $bayar = $pembelian->payments->first();
        $this->assertSame('PAY-' . now()->format('Ymd') . '-0001', $bayar->number);
        $this->assertSame('95500.00', $bayar->amount);
        $this->assertSame(PaymentMethod::Cash, $bayar->method);
        $this->assertSame('purchase', $bayar->payable_type);
    }

    public function test_stok_bertambah_dan_setiap_perubahan_tercatat_sebagai_mutasi(): void
    {
        $this->actingAs($this->admin)->post(route('purchases.store'), $this->payload());

        $pembelian = Purchase::firstOrFail();

        $this->assertSame(10.0, $this->besar($this->p1->fresh()->stock));
        $this->assertSame(5.0, $this->besar($this->p2->fresh()->stock));

        $this->assertDatabaseCount('stock_movements', 2);
        foreach (StockMovement::all() as $m) {
            $this->assertSame(StockMovementType::Purchase, $m->type);
            $this->assertSame('purchase', $m->reference_type);
            $this->assertSame($pembelian->id, $m->reference_id);
            $this->assertSame(0.0, $this->besar($m->stock_before));
            $this->assertGreaterThan(0, $this->besar($m->quantity));
            $this->assertSame($this->admin->id, $m->user_id);
            $this->assertStringContainsString($pembelian->number, $m->note);
            $this->assertStringContainsString('PT Sumber Rejeki', $m->note);
        }
    }

    public function test_harga_beli_produk_mengikuti_harga_bersih_pembelian(): void
    {
        $this->actingAs($this->admin)->post(route('purchases.store'), $this->payload());

        $this->assertSame('8000.00', $this->p1->fresh()->purchase_price);
        $this->assertSame('2900.00', $this->p2->fresh()->purchase_price, '(5 x 3.000 - 500) / 5 = 2.900');
    }

    public function test_harga_beli_produk_tidak_berubah_bila_opsinya_dimatikan(): void
    {
        $this->actingAs($this->admin)->post(route('purchases.store'), $this->payload(['update_cost' => '0']));

        $this->assertSame('7000.00', $this->p1->fresh()->purchase_price);
        $this->assertSame('2000.00', $this->p2->fresh()->purchase_price);
        $this->assertSame(10.0, $this->besar($this->p1->fresh()->stock), 'Stok tetap bertambah.');
    }

    public function test_pembelian_hutang_penuh_butuh_jatuh_tempo_dan_tidak_membuat_pembayaran(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('purchases.store'), $this->payload(['paid_amount' => '0', 'payment_method' => null, 'due_date' => null]))
            ->assertSessionHasErrors('due_date');
        $this->assertDatabaseCount('purchases', 0);

        $this->post(route('purchases.store'), $this->payload([
            'paid_amount' => '0', 'payment_method' => null, 'due_date' => now()->addDays(30)->format('Y-m-d'),
        ]))->assertSessionHasNoErrors();

        $pembelian = Purchase::firstOrFail();
        $this->assertSame(PaymentStatus::Unpaid, $pembelian->payment_status);
        $this->assertSame('0.00', $pembelian->paid_amount);
        $this->assertSame('95500.00', $pembelian->balance);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(10.0, $this->besar($this->p1->fresh()->stock), 'Barang sudah diterima walau belum dibayar.');
    }

    public function test_pembayaran_sebagian(): void
    {
        $this->actingAs($this->admin)->post(route('purchases.store'), $this->payload([
            'paid_amount' => '50000', 'payment_method' => 'transfer', 'due_date' => now()->addDays(14)->format('Y-m-d'),
        ]))->assertSessionHasNoErrors();

        $pembelian = Purchase::firstOrFail();
        $this->assertSame(PaymentStatus::Partial, $pembelian->payment_status);
        $this->assertSame('50000.00', $pembelian->paid_amount);
        $this->assertSame('45500.00', $pembelian->balance);
        $this->assertSame(PaymentMethod::Transfer, Payment::firstOrFail()->method);
    }

    public function test_pembayaran_melebihi_total_ditolak_dan_tidak_ada_yang_tersimpan(): void
    {
        $this->actingAs($this->admin)
            ->post(route('purchases.store'), $this->payload(['paid_amount' => '100000']))
            ->assertSessionHasErrors('paid_amount');

        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(0.0, $this->besar($this->p1->fresh()->stock));
    }

    public function test_metode_pembayaran_wajib_bila_ada_pembayaran_dan_piutang_tidak_berlaku(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('purchases.store'), $this->payload(['payment_method' => null]))->assertSessionHasErrors('payment_method');
        $this->post(route('purchases.store'), $this->payload(['payment_method' => 'receivable']))->assertSessionHasErrors('payment_method');
        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_diskon_yang_melebihi_nilai_ditolak(): void
    {
        $this->actingAs($this->admin);

        $item = fn (string $diskon) => [['product_id' => $this->p1->id, 'quantity' => '1', 'unit_price' => '1000', 'discount' => $diskon]];

        $this->post(route('purchases.store'), $this->payload(['items' => $item('5000'), 'paid_amount' => '0', 'due_date' => now()->addDay()->format('Y-m-d')]))
            ->assertSessionHasErrors('items.0.discount');

        $this->post(route('purchases.store'), $this->payload(['discount' => '500000']))
            ->assertSessionHasErrors('discount');

        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_produk_yang_sama_dua_kali_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->post(route('purchases.store'), $this->payload(['items' => [
                ['product_id' => $this->p1->id, 'quantity' => '1', 'unit_price' => '1000', 'discount' => '0'],
                ['product_id' => $this->p1->id, 'quantity' => '2', 'unit_price' => '1000', 'discount' => '0'],
            ]]));

        $kunci = collect(session('errors')->keys());
        $this->assertTrue($kunci->contains(fn ($k) => str_contains($k, 'product_id')));
        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_tanggal_dan_angka_tidak_valid_ditolak(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('purchases.store'), $this->payload(['purchase_date' => now()->addDay()->format('Y-m-d')]))
            ->assertSessionHasErrors('purchase_date');

        $this->post(route('purchases.store'), $this->payload(['due_date' => now()->subDays(5)->format('Y-m-d')]))
            ->assertSessionHasErrors('due_date');

        $this->post(route('purchases.store'), $this->payload(['items' => [['product_id' => $this->p1->id, 'quantity' => '1.2345', 'unit_price' => '1000']]]))
            ->assertSessionHasErrors('items.0.quantity');

        $this->post(route('purchases.store'), $this->payload(['items' => [['product_id' => $this->p1->id, 'quantity' => '1', 'unit_price' => '1000.555']]]))
            ->assertSessionHasErrors('items.0.unit_price');

        $this->post(route('purchases.store'), $this->payload(['items' => [['product_id' => $this->p1->id, 'quantity' => '0', 'unit_price' => '1000']]]))
            ->assertSessionHasErrors('items.0.quantity');

        $this->post(route('purchases.store'), $this->payload(['items' => []]))->assertSessionHasErrors('items');

        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_jumlah_desimal_untuk_satuan_kg(): void
    {
        $this->actingAs($this->admin)->post(route('purchases.store'), $this->payload([
            'items' => [['product_id' => $this->p1->id, 'quantity' => '2.5', 'unit_price' => '10000', 'discount' => '0']],
            'discount' => '0', 'other_cost' => '0', 'paid_amount' => '25000',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(2.5, $this->besar($this->p1->fresh()->stock));
        $this->assertSame('25000.00', Purchase::firstOrFail()->grand_total);
    }

    public function test_pembulatan_setengah_ke_atas_pada_total_baris(): void
    {
        // 0,5 x Rp 0,01 = Rp 0,005 -> dibulatkan naik menjadi Rp 0,01
        $pembelian = $this->buatPembelian([
            'items' => [['product_id' => $this->p1->id, 'quantity' => '0.5', 'unit_price' => '0.01', 'discount' => '0']],
            'discount' => '0', 'other_cost' => '0', 'paid_amount' => '0.01',
        ]);

        $this->assertSame('0.01', $pembelian->grand_total);
        $this->assertSame(PaymentStatus::Paid, $pembelian->payment_status);
    }

    public function test_pembelian_bernilai_nol_langsung_berstatus_lunas(): void
    {
        $pembelian = $this->buatPembelian([
            'items' => [['product_id' => $this->p1->id, 'quantity' => '3', 'unit_price' => '0', 'discount' => '0']],
            'discount' => '0', 'other_cost' => '0', 'paid_amount' => '0', 'payment_method' => null,
        ]);

        $this->assertSame('0.00', $pembelian->balance);
        $this->assertSame(PaymentStatus::Paid, $pembelian->payment_status);
        $this->assertSame(3.0, $this->besar($this->p1->fresh()->stock));
    }

    public function test_nomor_pembelian_berurutan_per_tanggal(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('purchases.store'), $this->payload(['supplier_invoice_number' => 'A-1']));
        $this->post(route('purchases.store'), $this->payload(['supplier_invoice_number' => 'A-2']));
        $this->post(route('purchases.store'), $this->payload(['supplier_invoice_number' => 'A-3', 'purchase_date' => '2026-01-15']));

        $this->assertEqualsCanonicalizing(
            ['PUR-' . now()->format('Ymd') . '-0001', 'PUR-' . now()->format('Ymd') . '-0002', 'PUR-20260115-0001'],
            Purchase::pluck('number')->all()
        );
    }

    public function test_faktur_yang_sama_dari_supplier_yang_sama_ditolak(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('purchases.store'), $this->payload())->assertSessionHasNoErrors();

        $this->post(route('purchases.store'), $this->payload())->assertSessionHasErrors('supplier_invoice_number');
        $this->assertSame('Nomor faktur ini sudah tercatat untuk supplier tersebut.', session('errors')->first('supplier_invoice_number'));

        $lain = Supplier::factory()->create();
        $this->post(route('purchases.store'), $this->payload(['supplier_id' => $lain->id]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('purchases', 2);
    }

    public function test_kegagalan_setelah_stok_berubah_membatalkan_seluruh_pembelian(): void
    {
        // Metode tidak dikenal baru ketahuan saat pembayaran dicatat, SETELAH dokumen dan stok ditulis.
        try {
            $this->buatPembelian(['payment_method' => 'metode-ngawur']);
            $this->fail('Seharusnya gagal.');
        } catch (ValueError) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('purchase_items', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0.0, $this->besar($this->p1->fresh()->stock));
        $this->assertSame('7000.00', $this->p1->fresh()->purchase_price);
    }

    public function test_produk_yang_tidak_ada_menggagalkan_pembelian_tanpa_sisa(): void
    {
        $this->expectException(\App\Exceptions\AturanBisnisException::class);

        try {
            $this->buatPembelian(['items' => [['product_id' => 99999, 'quantity' => '1', 'unit_price' => '1000', 'discount' => '0']]]);
        } finally {
            $this->assertDatabaseCount('purchases', 0);
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    // ---------------- Hak akses, daftar, pencarian ----------------

    public function test_kasir_tidak_dapat_mengakses_pembelian(): void
    {
        $pembelian = $this->buatPembelian();

        $this->actingAs($this->kasir);
        $this->get(route('purchases.index'))->assertForbidden();
        $this->get(route('purchases.create'))->assertForbidden();
        $this->get(route('purchases.show', $pembelian))->assertForbidden();
        $this->get(route('purchases.products', ['q' => 'Ayam']))->assertForbidden();
        $this->post(route('purchases.store'), $this->payload(['supplier_invoice_number' => 'X-1']))->assertForbidden();
        $this->post(route('purchases.void', $pembelian), ['alasan' => 'coba-coba saja'])->assertForbidden();

        $this->assertDatabaseCount('purchases', 1);
    }

    public function test_admin_dapat_membuka_formulir_daftar_dan_detail(): void
    {
        $pembelian = $this->buatPembelian();

        $this->actingAs($this->admin);
        $this->get(route('purchases.create'))->assertOk()->assertSee('Simpan pembelian')->assertSee('PT Sumber Rejeki');
        $this->get(route('purchases.index'))->assertOk()->assertSee($pembelian->number)->assertSee('LUNAS');
        $this->get(route('purchases.show', $pembelian))
            ->assertOk()
            ->assertSee($pembelian->number)
            ->assertSee('Ayam Geprek')
            ->assertSee('Rp 95.500')
            ->assertSee('Riwayat pembayaran')
            ->assertSee('PAY-' . now()->format('Ymd') . '-0001');
    }

    public function test_daftar_dapat_difilter_dan_meringkas_total_serta_hutang(): void
    {
        $lain = Supplier::factory()->create(['name' => 'CV Maju Jaya']);
        $this->buatPembelian(['supplier_invoice_number' => 'INV-A']);
        $this->buatPembelian([
            'supplier_id' => $lain->id, 'supplier_invoice_number' => 'INV-B',
            'paid_amount' => '0', 'payment_method' => null, 'due_date' => now()->addDays(7)->format('Y-m-d'),
        ]);

        $this->actingAs($this->admin);

        $this->get(route('purchases.index'))->assertSee('Rp 191.000');

        $this->get(route('purchases.index', ['supplier' => $lain->id]))
            ->assertSee('INV-B')->assertDontSee('INV-A')->assertSee('HUTANG');

        $this->get(route('purchases.index', ['pembayaran' => 'paid']))
            ->assertSee('INV-A')->assertDontSee('INV-B');

        $this->get(route('purchases.index', ['cari' => 'INV-B']))
            ->assertSee('INV-B')->assertDontSee('INV-A');

        $this->get(route('purchases.index', ['cari' => 'Maju Jaya']))
            ->assertSee('INV-B')->assertDontSee('INV-A');

        $this->get(route('purchases.index', ['dari' => now()->addDay()->format('Y-m-d')]))
            ->assertSee('Belum ada pembelian yang cocok');
    }

    public function test_pencarian_produk_untuk_formulir(): void
    {
        Product::factory()->create(['name' => 'Ayam Bakar', 'sku' => 'AYM-2', 'barcode' => '8991111', 'is_active' => false]);
        $dengan = Product::factory()->create(['name' => 'Teh Botol', 'sku' => 'TEH-1', 'barcode' => '8992222']);

        $this->actingAs($this->admin);

        $this->get(route('purchases.products', ['q' => 'Ayam']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['sku' => 'AYM-1', 'name' => 'Ayam Geprek']);

        $this->get(route('purchases.products', ['q' => '8992222']))
            ->assertJsonCount(1)
            ->assertJsonFragment(['id' => $dengan->id, 'barcode' => '8992222']);

        $this->get(route('purchases.products', ['q' => '8991111']))->assertJsonCount(0);
        $this->get(route('purchases.products', ['q' => '']))->assertExactJson([]);
    }

    // ---------------- Supplier ----------------

    public function test_detail_supplier_menampilkan_total_hutang_dan_riwayat_pembelian(): void
    {
        $pembelian = $this->buatPembelian([
            'paid_amount' => '20000', 'payment_method' => 'cash', 'due_date' => now()->addDays(30)->format('Y-m-d'),
        ]);

        $this->actingAs($this->admin)
            ->get(route('suppliers.show', $this->supplier))
            ->assertOk()
            ->assertSee('Hutang supplier')
            ->assertSee('Rp 75.500')
            ->assertSee($pembelian->number)
            ->assertSee('SEBAGIAN');
    }

    public function test_supplier_yang_masih_berhutang_tidak_dapat_dihapus(): void
    {
        $this->buatPembelian(['paid_amount' => '0', 'payment_method' => null, 'due_date' => now()->addDays(30)->format('Y-m-d')]);

        $this->actingAs($this->admin)
            ->delete(route('suppliers.destroy', $this->supplier))
            ->assertSessionHas('error');

        $this->assertStringContainsString('hutang', session('error'));
        $this->assertNotSoftDeleted('suppliers', ['id' => $this->supplier->id]);
    }

    public function test_supplier_yang_semua_pembeliannya_lunas_dapat_dihapus(): void
    {
        $this->buatPembelian();

        $this->actingAs($this->admin)
            ->delete(route('suppliers.destroy', $this->supplier))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('suppliers', ['id' => $this->supplier->id]);
        // Riwayat pembelian tetap bisa dibaca walau supplier sudah dihapus.
        $this->assertSame('PT Sumber Rejeki', Purchase::firstOrFail()->supplier->name);
    }
}
