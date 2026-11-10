<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Events\CustomerPaymentRecorded;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Halaman Piutang Pelanggan: daftar transaksi belum lunas dan pelunasannya. */
class PiutangPelangganTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;

    private Customer $pelanggan;

    private Sale $jual; // total 18.000, dibayar 5.000, sisa piutang 13.000

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $kasir = $this->buatPengguna(RoleName::Kasir);
        $ayam = Product::factory()->stok(20)->create(['selling_price' => 10000]);
        Customer::umum();
        $this->pelanggan = Customer::factory()->create(['name' => 'Bu Rina']);

        $this->actingAs($kasir)->postJson(route('sales.store'), [
            'customer_id' => $this->pelanggan->id,
            'items' => [['product_id' => $ayam->id, 'quantity' => '2', 'discount' => '0']],
            'discount' => '2000',
            'payment_method' => 'receivable',
            'payments' => [['method' => 'cash', 'amount' => '5000']],
        ])->assertCreated();
        $this->jual = Sale::sole();
    }

    private function bayar(string $jumlah, array $ubah = []): TestResponse
    {
        return $this->post(route('receivables.store', $this->jual), array_merge([
            'amount' => $jumlah, 'method' => 'transfer', 'paid_at' => now()->format('Y-m-d'), 'note' => 'Transfer BRI',
        ], $ubah));
    }

    public function test_daftar_piutang_menampilkan_transaksi_yang_belum_lunas(): void
    {
        $this->actingAs($this->admin)
            ->get(route('receivables.index'))
            ->assertOk()
            ->assertSee($this->jual->number)
            ->assertSee('Bu Rina')
            ->assertSee('Rp 13.000');
    }

    public function test_pelunasan_sebagian_mengurangi_piutang_transaksi_dan_pelanggan(): void
    {
        Event::fake([CustomerPaymentRecorded::class]);

        $this->actingAs($this->admin)->from(route('receivables.index'))->bayar('10000')
            ->assertRedirect(route('receivables.index'))
            ->assertSessionHas('success');

        $this->assertSame('3000.00', $this->jual->fresh()->balance);
        $this->assertSame('3000.00', $this->pelanggan->fresh()->receivable_balance);
        $this->assertDatabaseHas('payments', ['payable_id' => $this->jual->id, 'amount' => '10000.00', 'method' => 'transfer', 'note' => 'Transfer BRI']);
        Event::assertDispatched(CustomerPaymentRecorded::class, fn (CustomerPaymentRecorded $e) => $e->sale->is($this->jual) && $e->payment->amount === '10000.00');
    }

    public function test_pelunasan_melebihi_sisa_piutang_ditolak(): void
    {
        $jumlahPembayaran = Payment::count();

        $this->actingAs($this->admin)->bayar('13000.01')
            ->assertSessionHas('error', 'Pembayaran melebihi sisa piutang. Sisa piutang Rp 13.000.');

        $this->assertSame('13000.00', $this->jual->fresh()->balance);
        $this->assertSame($jumlahPembayaran, Payment::count());
    }

    public function test_kasir_tanpa_izin_piutang_tidak_bisa_melunasi(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);

        $this->actingAs($kasir)->bayar('1000')->assertForbidden();

        $this->assertSame('13000.00', $this->jual->fresh()->balance);
    }
}
