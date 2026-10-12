<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PurchaseStatus;
use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\SaleService;
use App\Support\Uang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class PembatalanPenjualanTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $kasir;
    private User $admin;
    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->produk = Product::factory()->stok(20)->create(['selling_price' => 10000]);
        Customer::umum();
    }

    private function buatPenjualanTunai(array $ubah = []): Sale
    {
        return app(SaleService::class)->buat(array_merge([
            'customer_id' => null,
            'items' => [['product_id' => $this->produk->id, 'quantity' => '5', 'discount' => '0']],
            'discount' => '0', 'payment_method' => 'cash', 'paid_amount' => '50000',
        ], $ubah), $this->kasir);
    }

    public function test_pembatalan_mengembalikan_stok_menandai_pembayaran_dan_menyimpan_jejak(): void
    {
        $jual = $this->buatPenjualanTunai();

        $this->actingAs($this->admin)
            ->post(route('sales.void', $jual), ['alasan' => 'Salah input jumlah'])
            ->assertRedirect(route('sales.show', $jual))
            ->assertSessionHas('success');

        $jual->refresh();
        $this->assertSame(PurchaseStatus::Voided, $jual->status);
        $this->assertSame($this->admin->id, $jual->voided_by);
        $this->assertSame('Salah input jumlah', $jual->void_reason);
        $this->assertSame('50000.00', $jual->paid_amount, 'Angka historis tidak diubah.');

        $this->assertSame(20.0, (float) $this->produk->fresh()->stock);

        $balik = StockMovement::where('quantity', '>', 0)->where('type', StockMovementType::Sale->value)->get();
        $this->assertCount(1, $balik);
        $this->assertSame('sale', $balik->first()->reference_type);
        $this->assertSame($jual->id, $balik->first()->reference_id);
        $this->assertStringContainsString('Pembatalan penjualan', $balik->first()->note);
        $this->assertDatabaseCount('stock_movements', 2, 'Mutasi keluar awal + mutasi pembalik; tidak ada yang dihapus.');

        $bayar = Payment::firstOrFail();
        $this->assertNotNull($bayar->voided_at);
        $this->assertSame($this->admin->id, $bayar->voided_by);
        $this->assertSame('50000.00', $bayar->amount);
    }

    public function test_alasan_wajib_dan_transaksi_tetap_utuh_bila_ditolak(): void
    {
        $jual = $this->buatPenjualanTunai();

        $this->actingAs($this->admin)
            ->post(route('sales.void', $jual), ['alasan' => 'ab'])
            ->assertSessionHasErrors('alasan');

        $this->assertSame(PurchaseStatus::Completed, $jual->fresh()->status);
        $this->assertSame(15.0, (float) $this->produk->fresh()->stock);
    }

    public function test_penjualan_tidak_dapat_dibatalkan_dua_kali(): void
    {
        $jual = $this->buatPenjualanTunai();

        $this->actingAs($this->admin);
        $this->post(route('sales.void', $jual), ['alasan' => 'Salah input'])->assertSessionHas('success');
        $this->post(route('sales.void', $jual), ['alasan' => 'Salah input lagi'])->assertSessionHas('error');

        $this->assertStringContainsString('sudah dibatalkan', session('error'));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_kasir_tidak_dapat_membatalkan_penjualan(): void
    {
        $jual = $this->buatPenjualanTunai();

        $this->actingAs($this->kasir)
            ->post(route('sales.void', $jual), ['alasan' => 'Coba batalkan'])
            ->assertForbidden();

        $this->assertSame(PurchaseStatus::Completed, $jual->fresh()->status);
    }

    // ---------------- Piutang ----------------

    public function test_pembatalan_piutang_tanpa_dp_mengembalikan_saldo_pelanggan_ke_nol(): void
    {
        $pelanggan = Customer::factory()->create();
        $jual = $this->buatPenjualanTunai(['customer_id' => $pelanggan->id, 'payment_method' => 'receivable', 'paid_amount' => '0']);
        $this->assertSame('50000.00', $pelanggan->fresh()->receivable_balance);

        app(SaleService::class)->batalkan($jual, 'Salah pelanggan', $this->admin);

        $this->assertSame('0.00', $pelanggan->fresh()->receivable_balance);
        $this->assertSame(20.0, (float) $this->produk->fresh()->stock);
    }

    public function test_pembatalan_piutang_dengan_dp_mengembalikan_sisa_saldo_ke_nol_tanpa_merefund_dp(): void
    {
        $pelanggan = Customer::factory()->create();
        $jual = $this->buatPenjualanTunai(['customer_id' => $pelanggan->id, 'payment_method' => 'receivable', 'paid_amount' => '20000', 'dp_method' => 'cash']);
        $this->assertSame('30000.00', $pelanggan->fresh()->receivable_balance, 'Total 50.000 - DP 20.000.');

        app(SaleService::class)->batalkan($jual, 'Salah pelanggan', $this->admin);

        $this->assertSame('0.00', $pelanggan->fresh()->receivable_balance, 'Sisa kewajiban dihapus; DP yang sudah diterima tidak dikembalikan otomatis oleh sistem.');
        $this->assertNotNull(Payment::firstOrFail()->voided_at, 'Catatan DP tetap ditandai batal sebagai jejak audit.');
    }

    public function test_pembatalan_ditolak_bila_sudah_ada_pelunasan_piutang_susulan(): void
    {
        $pelanggan = Customer::factory()->create();
        $jual = $this->buatPenjualanTunai(['customer_id' => $pelanggan->id, 'payment_method' => 'receivable', 'paid_amount' => '0']);

        // Pelunasan susulan: terjadi di interaksi TERPISAH dari pembuatan transaksi (mis. pelanggan bayar cicilan).
        app(PaymentService::class)->terimaPenjualan($jual, Uang::sen('20000'), PaymentMethod::Cash, 'Cicilan', $this->admin);

        try {
            app(SaleService::class)->batalkan($jual, 'Coba batalkan setelah dicicil', $this->admin);
            $this->fail('Seharusnya ditolak.');
        } catch (\App\Exceptions\AturanBisnisException $e) {
            $this->assertStringContainsString('pelunasan piutang susulan', $e->getMessage());
            $this->assertStringContainsString('Retur Penjualan', $e->getMessage());
        }

        $this->assertSame(PurchaseStatus::Completed, $jual->fresh()->status);
        $this->assertSame(15.0, (float) $this->produk->fresh()->stock);
        $this->assertSame('30000.00', $pelanggan->fresh()->receivable_balance);
    }

    public function test_dp_saat_pembuatan_transaksi_tidak_menghalangi_pembatalan(): void
    {
        // Berbeda dari kasus di atas: DP yang diambil BERSAMAAN saat transaksi dibuat (initial_paid_amount)
        // tidak dianggap "pelunasan susulan", sehingga pembatalan tetap diizinkan.
        $pelanggan = Customer::factory()->create();
        $jual = $this->buatPenjualanTunai(['customer_id' => $pelanggan->id, 'payment_method' => 'receivable', 'paid_amount' => '20000', 'dp_method' => 'cash']);

        app(SaleService::class)->batalkan($jual, 'Dibatalkan segera setelah dibuat', $this->admin);

        $this->assertSame(PurchaseStatus::Voided, $jual->fresh()->status);
    }

    public function test_halaman_menampilkan_status_dibatalkan_dan_menyembunyikan_tombol_batal(): void
    {
        $jual = $this->buatPenjualanTunai();
        app(SaleService::class)->batalkan($jual, 'Salah input jumlah', $this->admin);

        $this->actingAs($this->admin)
            ->get(route('sales.show', $jual))
            ->assertOk()
            ->assertSee('Penjualan ini dibatalkan')
            ->assertSee('Salah input jumlah')
            ->assertDontSee('Batalkan penjualan ini');
    }
}
