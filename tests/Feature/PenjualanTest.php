<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class PenjualanTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $kasir;
    private User $admin;
    private Product $ayam; // 10.000, stok 20
    private Product $nasi; // 5.000, stok 20

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->ayam = Product::factory()->stok(20)->create(['name' => 'Ayam Geprek', 'sku' => 'AYM-1', 'selling_price' => 10000, 'purchase_price' => 7000]);
        $this->nasi = Product::factory()->stok(20)->create(['name' => 'Nasi Putih', 'sku' => 'NSI-1', 'selling_price' => 5000, 'purchase_price' => 3000]);
        Customer::umum();
    }

    /** Keranjang: 2 Ayam (10.000) + 2 Nasi (5.000) = subtotal 30.000, diskon transaksi 2.000 -> total 28.000. */
    private function payload(array $ubah = []): array
    {
        return array_merge([
            'customer_id' => null,
            'items' => [
                ['product_id' => $this->ayam->id, 'quantity' => '2', 'discount' => '0'],
                ['product_id' => $this->nasi->id, 'quantity' => '2', 'discount' => '0'],
            ],
            'discount' => '2000',
            'payment_method' => 'cash',
            'paid_amount' => '30000',
        ], $ubah);
    }

    public function test_penjualan_tunai_dengan_kembalian(): void
    {
        $this->actingAs($this->kasir)
            ->post(route('sales.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $jual = Sale::with(['items', 'payments'])->firstOrFail();

        $this->assertSame('POS-' . now()->format('Ymd') . '-0001', $jual->number);
        $this->assertSame('30000.00', $jual->subtotal);
        $this->assertSame('0.00', $jual->item_discount);
        $this->assertSame('2000.00', $jual->discount);
        $this->assertSame('28000.00', $jual->grand_total);
        $this->assertSame('28000.00', $jual->paid_amount);
        $this->assertSame('0.00', $jual->balance);
        $this->assertSame('30000.00', $jual->tendered_amount);
        $this->assertSame('2000.00', $jual->change_amount);
        $this->assertSame(PaymentMethod::Cash, $jual->payment_method);
        $this->assertSame(PaymentStatus::Paid, $jual->payment_status);
        $this->assertSame($this->kasir->id, $jual->user_id);
        $this->assertTrue($jual->customer->is_default);

        $this->assertCount(1, $jual->payments);
        $this->assertSame('28000.00', $jual->payments->first()->amount);

        $this->assertSame(18.0, (float) $this->ayam->fresh()->stock);
        $this->assertSame(18.0, (float) $this->nasi->fresh()->stock);

        $mutasi = StockMovement::where('product_id', $this->ayam->id)->firstOrFail();
        $this->assertSame(StockMovementType::Sale, $mutasi->type);
        $this->assertSame(-2.0, (float) $mutasi->quantity);
        $this->assertSame('sale', $mutasi->reference_type);
        $this->assertSame($jual->id, $mutasi->reference_id);
    }

    public function test_uang_diterima_kurang_dari_total_ditolak(): void
    {
        $this->actingAs($this->kasir)
            ->post(route('sales.store'), $this->payload(['paid_amount' => '20000']))
            ->assertSessionHasErrors('paid_amount');

        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(20.0, (float) $this->ayam->fresh()->stock);
    }

    public function test_metode_non_tunai_otomatis_lunas_tanpa_kembalian(): void
    {
        $this->actingAs($this->kasir)
            ->post(route('sales.store'), $this->payload(['payment_method' => 'qris', 'paid_amount' => '0']))
            ->assertSessionHasNoErrors();

        $jual = Sale::firstOrFail();
        $this->assertSame(PaymentMethod::Qris, $jual->payment_method);
        $this->assertSame('28000.00', $jual->paid_amount);
        $this->assertSame('0.00', $jual->balance);
        $this->assertSame('0.00', $jual->change_amount);
        $this->assertSame(PaymentStatus::Paid, $jual->payment_status);
    }

    public function test_diskon_baris_mengurangi_subtotal_baris(): void
    {
        $this->actingAs($this->kasir)->post(route('sales.store'), $this->payload([
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '1', 'discount' => '1500']],
            'discount' => '0', 'paid_amount' => '8500',
        ]));

        $jual = Sale::with('items')->firstOrFail();
        $this->assertSame('8500.00', $jual->subtotal); // 10.000 - 1.500 diskon baris
        $this->assertSame('1500.00', $jual->item_discount);
        $this->assertSame('8500.00', $jual->grand_total);
        $this->assertSame('1500.00', $jual->items->first()->discount);
    }

    public function test_diskon_produk_tetap_diterapkan_dan_sama_dengan_pratinjau_kasir(): void
    {
        // Produk dengan diskon tetap 10%: harga jual 10.000 -> harga bersih 9.000.
        $ber = Product::factory()->stok(10)->create(['selling_price' => 10000, 'discount_percent' => 10]);

        $this->actingAs($this->kasir)->post(route('sales.store'), $this->payload([
            'items' => [['product_id' => $ber->id, 'quantity' => '3', 'discount' => '0']],
            'discount' => '0', 'paid_amount' => '27000',
        ]));

        $baris = Sale::with('items')->firstOrFail()->items->first();
        $this->assertSame('9000.00', $baris->unit_price, 'Harga tersimpan harus memakai diskon tetap produk (10.000 - 10%).');
        $this->assertSame('27000.00', $baris->line_total);
    }

    public function test_harga_modal_disalin_saat_transaksi_untuk_laporan_keuntungan_historis(): void
    {
        $this->actingAs($this->kasir)->post(route('sales.store'), $this->payload([
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '1', 'discount' => '0']],
            'discount' => '0', 'paid_amount' => '10000',
        ]));

        $baris = Sale::with('items')->firstOrFail()->items->first();
        $this->assertSame('7000.00', $baris->cost_price);

        // Harga beli produk berubah setelahnya; baris transaksi lama harus tetap memakai angka lama.
        $this->ayam->update(['purchase_price' => 9999]);
        $this->assertSame('7000.00', $baris->fresh()->cost_price);
    }

    public function test_stok_tidak_mencukupi_ditolak_dan_transaksi_tidak_tersimpan(): void
    {
        $this->actingAs($this->kasir)
            ->post(route('sales.store'), $this->payload([
                'items' => [['product_id' => $this->ayam->id, 'quantity' => '999', 'discount' => '0']],
                'paid_amount' => '9990000',
            ]))
            ->assertSessionHas('error');

        $this->assertStringStartsWith('Stok tidak mencukupi.', session('error'));
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(20.0, (float) $this->ayam->fresh()->stock);
        $this->assertSame(20.0, (float) $this->nasi->fresh()->stock, 'Baris pertama (Ayam) sukses dikurangi lalu harus ikut dibatalkan.');
    }

    public function test_produk_tidak_aktif_ditolak(): void
    {
        $this->ayam->update(['is_active' => false]);

        $this->actingAs($this->kasir)
            ->post(route('sales.store'), $this->payload(['items' => [['product_id' => $this->ayam->id, 'quantity' => '1', 'discount' => '0']], 'discount' => '0', 'paid_amount' => '10000']))
            ->assertSessionHasErrors('items.0.product_id');

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_produk_yang_sama_dua_kali_ditolak(): void
    {
        $this->actingAs($this->kasir)
            ->post(route('sales.store'), $this->payload(['items' => [
                ['product_id' => $this->ayam->id, 'quantity' => '1', 'discount' => '0'],
                ['product_id' => $this->ayam->id, 'quantity' => '1', 'discount' => '0'],
            ], 'paid_amount' => '20000']))
            ->assertSessionHasErrors(['items.0.product_id', 'items.1.product_id']);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_keranjang_kosong_ditolak(): void
    {
        $this->actingAs($this->kasir)
            ->post(route('sales.store'), $this->payload(['items' => []]))
            ->assertSessionHasErrors('items');
    }

    // ---------------- Piutang ----------------

    public function test_penjualan_piutang_menambah_saldo_pelanggan_dan_tidak_mengurangi_stok_secara_berbeda(): void
    {
        $pelanggan = Customer::factory()->create(['name' => 'Budi']);

        $this->actingAs($this->kasir)->post(route('sales.store'), $this->payload([
            'customer_id' => $pelanggan->id, 'payment_method' => 'receivable', 'paid_amount' => '0',
        ]))->assertSessionHasNoErrors();

        $jual = Sale::firstOrFail();
        $this->assertSame(PaymentMethod::Receivable, $jual->payment_method);
        $this->assertSame('0.00', $jual->paid_amount);
        $this->assertSame('28000.00', $jual->balance);
        $this->assertSame(PaymentStatus::Unpaid, $jual->payment_status);
        $this->assertSame('28000.00', $pelanggan->fresh()->receivable_balance);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(18.0, (float) $this->ayam->fresh()->stock, 'Stok tetap berkurang walau metode piutang.');
    }

    public function test_penjualan_piutang_dengan_bayar_di_muka(): void
    {
        $pelanggan = Customer::factory()->create();

        $this->actingAs($this->kasir)->post(route('sales.store'), $this->payload([
            'customer_id' => $pelanggan->id, 'payment_method' => 'receivable', 'paid_amount' => '10000', 'dp_method' => 'cash',
        ]))->assertSessionHasNoErrors();

        $jual = Sale::with('payments')->firstOrFail();
        $this->assertSame('10000.00', $jual->paid_amount);
        $this->assertSame('18000.00', $jual->balance);
        $this->assertSame(PaymentStatus::Partial, $jual->payment_status);
        $this->assertSame('18000.00', $pelanggan->fresh()->receivable_balance, 'Saldo piutang = total - DP.');

        $bayar = $jual->payments->firstOrFail();
        $this->assertSame('10000.00', $bayar->amount);
        $this->assertSame(PaymentMethod::Cash, $bayar->method);
        $this->assertSame('RCV-' . now()->format('Ymd') . '-0001', $bayar->number);
    }

    public function test_piutang_membutuhkan_pelanggan_terdaftar_bukan_umum(): void
    {
        $this->actingAs($this->kasir)
            ->post(route('sales.store'), $this->payload(['customer_id' => null, 'payment_method' => 'receivable', 'paid_amount' => '0']))
            ->assertSessionHasErrors('customer_id');

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_dp_lebih_besar_atau_sama_dengan_total_ditolak(): void
    {
        $pelanggan = Customer::factory()->create();

        $this->actingAs($this->kasir)
            ->post(route('sales.store'), $this->payload([
                'customer_id' => $pelanggan->id, 'payment_method' => 'receivable', 'paid_amount' => '28000', 'dp_method' => 'cash',
            ]))
            ->assertSessionHasErrors('paid_amount');

        $this->assertDatabaseCount('sales', 0);
        $this->assertSame('0.00', $pelanggan->fresh()->receivable_balance);
    }

    // ---------------- Hak akses & tampilan ----------------

    public function test_kasir_hanya_melihat_transaksi_miliknya_sendiri(): void
    {
        $this->actingAs($this->kasir)->post(route('sales.store'), $this->payload(['paid_amount' => '30000']));
        $milikKasir = Sale::firstOrFail();

        $kasirLain = $this->buatPengguna(RoleName::Kasir);
        $this->actingAs($kasirLain)->post(route('sales.store'), $this->payload(['paid_amount' => '30000']));
        $milikLain = Sale::where('user_id', $kasirLain->id)->firstOrFail();

        $this->actingAs($this->kasir);
        $this->get(route('sales.index'))->assertOk()->assertSee($milikKasir->number)->assertDontSee($milikLain->number);
        $this->get(route('sales.show', $milikKasir))->assertOk();
        $this->get(route('sales.show', $milikLain))->assertForbidden();
    }

    public function test_admin_melihat_semua_transaksi_dan_kolom_laba(): void
    {
        $this->actingAs($this->kasir)->post(route('sales.store'), $this->payload(['paid_amount' => '30000']));
        $jual = Sale::firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('sales.show', $jual))
            ->assertOk()
            ->assertSee('Laba')
            ->assertSee($this->kasir->name);
    }

    public function test_kasir_tidak_melihat_kolom_laba(): void
    {
        $this->actingAs($this->kasir)->post(route('sales.store'), $this->payload(['paid_amount' => '30000']));
        $jual = Sale::firstOrFail();

        $this->actingAs($this->kasir)
            ->get(route('sales.show', $jual))
            ->assertOk()
            ->assertDontSee('Laba');
    }

    public function test_hanya_pemegang_izin_create_sale_dapat_mengakses_kasir_dan_menjual(): void
    {
        $tanpaIzin = $this->buatPengguna(RoleName::Admin);
        // Admin tidak memiliki create-sale pada matriks default (lihat RolePermissionSeeder).
        $this->actingAs($tanpaIzin);
        $this->get(route('pos.index'))->assertForbidden();
        $this->post(route('sales.store'), $this->payload())->assertForbidden();

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_nomor_penjualan_berurutan_per_hari(): void
    {
        $this->actingAs($this->kasir);
        $this->post(route('sales.store'), $this->payload(['paid_amount' => '30000']));
        $this->post(route('sales.store'), $this->payload(['paid_amount' => '30000']));

        $this->assertEqualsCanonicalizing(
            ['POS-' . now()->format('Ymd') . '-0001', 'POS-' . now()->format('Ymd') . '-0002'],
            Sale::pluck('number')->all()
        );
    }

    public function test_halaman_kasir_meneruskan_pengaturan_stok_minus_ke_javascript(): void
    {
        // Regresi: properti ini sempat tidak diteruskan ke Alpine sama sekali, sehingga tombol tambah
        // produk stok habis selalu terkunci walau stok minus diizinkan di pengaturan.
        config(['pos.allow_negative_stock' => true]);

        $this->actingAs($this->kasir)
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('"stokMinus":true', false);

        config(['pos.allow_negative_stock' => false]);

        $this->get(route('pos.index'))
            ->assertOk()
            ->assertSee('"stokMinus":false', false);
    }

    public function test_pencarian_produk_dan_pelanggan_untuk_layar_kasir(): void
    {
        Customer::factory()->create(['name' => 'Siti Aminah']);

        $this->actingAs($this->kasir);

        $this->get(route('pos.products', ['q' => 'Ayam']))
            ->assertOk()->assertJsonCount(1)->assertJsonFragment(['sku' => 'AYM-1']);

        $this->get(route('pos.customers', ['q' => 'Siti']))
            ->assertOk()->assertJsonFragment(['name' => 'Siti Aminah']);

        $this->get(route('pos.customers', ['q' => '']))
            ->assertOk()->assertJsonFragment(['code' => 'UMUM']);
    }
}
