<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Exceptions\AturanBisnisException;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\PurchaseService;
use App\Support\Uang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class PembayaranTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;
    private Purchase $pembelian;
    private PaymentService $bayar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->bayar = app(PaymentService::class);

        // Hutang Rp 100.000, belum dibayar sama sekali.
        $this->pembelian = app(PurchaseService::class)->buat([
            'supplier_id' => Supplier::factory()->create()->id,
            'purchase_date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'items' => [['product_id' => Product::factory()->create()->id, 'quantity' => '10', 'unit_price' => '10000', 'discount' => '0']],
            'discount' => '0', 'tax' => '0', 'other_cost' => '0', 'paid_amount' => '0',
        ], $this->admin);
    }

    public function test_pembayaran_bertahap_hingga_lunas(): void
    {
        $satu = $this->bayar->bayarPembelian($this->pembelian, Uang::sen('30000'), PaymentMethod::Transfer, 'Cicilan 1', $this->admin);

        $this->assertSame('PAY-' . now()->format('Ymd') . '-0001', $satu->number);
        $this->assertSame('30000.00', $this->pembelian->paid_amount, 'Instance pemanggil ikut tersinkron.');
        $this->assertSame('70000.00', $this->pembelian->balance);
        $this->assertSame(PaymentStatus::Partial, $this->pembelian->payment_status);

        $dua = $this->bayar->bayarPembelian($this->pembelian, Uang::sen('70000'), PaymentMethod::Cash, null, $this->admin);

        $this->assertSame('PAY-' . now()->format('Ymd') . '-0002', $dua->number);
        $fresh = $this->pembelian->fresh();
        $this->assertSame('100000.00', $fresh->paid_amount);
        $this->assertSame('0.00', $fresh->balance);
        $this->assertSame(PaymentStatus::Paid, $fresh->payment_status);
        $this->assertSame(2, Payment::count());
    }

    public function test_pembayaran_melebihi_sisa_hutang_ditolak_dengan_pesan_yang_menyebut_sisa(): void
    {
        $this->bayar->bayarPembelian($this->pembelian, Uang::sen('30000'), PaymentMethod::Cash, null, $this->admin);

        try {
            $this->bayar->bayarPembelian($this->pembelian, Uang::sen('70000.01'), PaymentMethod::Cash, null, $this->admin);
            $this->fail('Seharusnya ditolak.');
        } catch (AturanBisnisException $e) {
            $this->assertStringContainsString('Pembayaran melebihi sisa hutang', $e->getMessage());
            $this->assertStringContainsString('Rp 70.000', $e->getMessage());
        }

        $this->assertSame('70000.00', $this->pembelian->fresh()->balance);
        $this->assertSame(1, Payment::count());
    }

    public function test_jumlah_nol_negatif_dan_metode_piutang_ditolak(): void
    {
        foreach ([[0, PaymentMethod::Cash], [-500, PaymentMethod::Cash], [1000, PaymentMethod::Receivable]] as [$sen, $metode]) {
            try {
                $this->bayar->bayarPembelian($this->pembelian, $sen, $metode, null, $this->admin);
                $this->fail('Seharusnya ditolak.');
            } catch (AturanBisnisException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, Payment::count());
        $this->assertSame('100000.00', $this->pembelian->fresh()->balance);
    }

    public function test_pembelian_yang_dibatalkan_tidak_dapat_dibayar(): void
    {
        app(PurchaseService::class)->batalkan($this->pembelian, 'Salah supplier', $this->admin);

        $this->expectException(AturanBisnisException::class);
        $this->expectExceptionMessage('dibatalkan');

        $this->bayar->bayarPembelian($this->pembelian, Uang::sen('1000'), PaymentMethod::Cash, null, $this->admin);
    }

    public function test_catatan_pembayaran_tidak_dapat_diubah_atau_dihapus(): void
    {
        $bayar = $this->bayar->bayarPembelian($this->pembelian, Uang::sen('30000'), PaymentMethod::Cash, null, $this->admin);

        try {
            $bayar->update(['amount' => 1]);
            $this->fail('Ubah jumlah seharusnya ditolak.');
        } catch (LogicException $e) {
            $this->assertSame('Catatan pembayaran tidak boleh diubah.', $e->getMessage());
        }

        try {
            $bayar->delete();
            $this->fail('Hapus seharusnya ditolak.');
        } catch (LogicException $e) {
            $this->assertSame('Catatan pembayaran tidak boleh dihapus.', $e->getMessage());
        }

        // Menandai batal diperbolehkan: satu-satunya perubahan yang sah. Muat ulang dulu: percobaan ubah
        // di atas meninggalkan perubahan `amount` yang belum tersimpan pada objek di memori.
        $bayar = $bayar->fresh();
        $bayar->forceFill(['voided_at' => now(), 'voided_by' => $this->admin->id, 'void_reason' => 'Uji'])->save();
        $this->assertNotNull($bayar->fresh()->voided_at);
        $this->assertSame('30000.00', $bayar->fresh()->amount);
    }
}
