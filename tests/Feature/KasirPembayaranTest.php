<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Events\CustomerPaymentRecorded;
use App\Events\SaleCompleted;
use App\Events\SaleVoided;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/**
 * Kasir Fase 6: pembayaran lebih dari satu metode (split), penyimpanan dari layar Kasir (JSON),
 * pajak penjualan, dan event untuk modul Akuntansi.
 */
class KasirPembayaranTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $kasir;

    private Product $ayam; // 10.000, stok 20

    private Product $nasi; // 5.000, stok 20

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
        $this->ayam = Product::factory()->stok(20)->create(['name' => 'Ayam Geprek', 'selling_price' => 10000, 'purchase_price' => 7000]);
        $this->nasi = Product::factory()->stok(20)->create(['name' => 'Nasi Putih', 'selling_price' => 5000, 'purchase_price' => 3000]);
        Customer::umum();
    }

    /**
     * 2 Ayam + 2 Nasi = 30.000, diskon 2.000 -> total 28.000.
     *
     * @param  list<array{method: string, amount: string}>  $bayar
     */
    private function payload(array $bayar, array $ubah = []): array
    {
        return array_merge([
            'customer_id' => null,
            'items' => [
                ['product_id' => $this->ayam->id, 'quantity' => '2', 'discount' => '0'],
                ['product_id' => $this->nasi->id, 'quantity' => '2', 'discount' => '0'],
            ],
            'discount' => '2000',
            'payments' => $bayar,
        ], $ubah);
    }

    private function aturPajak(bool $termasuk, string $tarif = '11'): void
    {
        setting(['tax.enabled' => true, 'tax.name' => 'PPN', 'tax.rate' => $tarif, 'tax.inclusive' => $termasuk]);
    }

    public function test_split_tunai_dan_qris_dengan_kembalian_dari_tunai(): void
    {
        $this->actingAs($this->kasir)
            ->postJson(route('sales.store'), $this->payload([
                ['method' => 'qris', 'amount' => '8000'],
                ['method' => 'cash', 'amount' => '50000'],
            ]))
            ->assertCreated()
            ->assertJson(['change' => 30000, 'grand_total' => 28000]);

        $jual = Sale::with('payments')->firstOrFail();
        $this->assertSame(PaymentMethod::Mixed, $jual->payment_method);
        $this->assertSame('28000.00', $jual->paid_amount);
        $this->assertSame('58000.00', $jual->tendered_amount);
        $this->assertSame('30000.00', $jual->change_amount);
        $this->assertSame(PaymentStatus::Paid, $jual->payment_status);

        $this->assertSame(
            [['qris', '8000.00'], ['cash', '20000.00']],
            $jual->payments->map(fn (Payment $p) => [$p->method->value, $p->amount])->all(),
            'Satu baris pembayaran per metode; tunai dicatat setelah dipotong kembalian.',
        );
    }

    public function test_satu_metode_dalam_bentuk_baru_tersimpan_sebagai_metode_itu(): void
    {
        $this->actingAs($this->kasir)
            ->postJson(route('sales.store'), $this->payload([['method' => 'transfer', 'amount' => '28000']]))
            ->assertCreated();

        $this->assertSame(PaymentMethod::Transfer, Sale::firstOrFail()->payment_method);
    }

    public function test_non_tunai_melebihi_total_ditolak(): void
    {
        $this->actingAs($this->kasir)
            ->postJson(route('sales.store'), $this->payload([
                ['method' => 'qris', 'amount' => '20000'],
                ['method' => 'debit', 'amount' => '10000'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payments' => 'Kembalian hanya bisa dari uang tunai']);

        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(20.0, (float) $this->ayam->fresh()->stock);
    }

    public function test_pembayaran_kurang_ditolak_dengan_menyebut_kekurangannya(): void
    {
        $this->actingAs($this->kasir)
            ->postJson(route('sales.store'), $this->payload([
                ['method' => 'qris', 'amount' => '8000'],
                ['method' => 'cash', 'amount' => '15000'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payments' => 'Pembayaran masih kurang Rp 5.000.']);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_metode_yang_sama_dua_kali_dan_piutang_sebagai_baris_bayar_ditolak(): void
    {
        $this->actingAs($this->kasir);

        $this->postJson(route('sales.store'), $this->payload([
            ['method' => 'cash', 'amount' => '10000'],
            ['method' => 'cash', 'amount' => '18000'],
        ]))->assertJsonValidationErrors('payments.1.method');

        $this->postJson(route('sales.store'), $this->payload([['method' => 'receivable', 'amount' => '28000']]))
            ->assertJsonValidationErrors('payments.0.method');

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_sisa_split_jadi_piutang_pelanggan_terdaftar(): void
    {
        $pelanggan = Customer::factory()->create();

        $this->actingAs($this->kasir)
            ->postJson(route('sales.store'), $this->payload([
                ['method' => 'cash', 'amount' => '5000'],
                ['method' => 'qris', 'amount' => '3000'],
            ], ['customer_id' => $pelanggan->id, 'payment_method' => 'receivable']))
            ->assertCreated()
            ->assertJson(['balance' => 20000]);

        $jual = Sale::firstOrFail();
        $this->assertSame(PaymentMethod::Receivable, $jual->payment_method);
        $this->assertSame('8000.00', $jual->paid_amount);
        $this->assertSame('20000.00', $jual->balance);
        $this->assertSame(PaymentStatus::Partial, $jual->payment_status);
        $this->assertSame('20000.00', $pelanggan->fresh()->receivable_balance);
    }

    public function test_piutang_lunas_atau_tanpa_pelanggan_terdaftar_ditolak(): void
    {
        $pelanggan = Customer::factory()->create();
        $this->actingAs($this->kasir);

        $this->postJson(route('sales.store'), $this->payload([['method' => 'cash', 'amount' => '28000']], [
            'customer_id' => $pelanggan->id, 'payment_method' => 'receivable',
        ]))->assertJsonValidationErrors('payments');

        $this->postJson(route('sales.store'), $this->payload([], ['payment_method' => 'receivable']))
            ->assertJsonValidationErrors('customer_id');

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_penolakan_stok_dari_kasir_dikembalikan_sebagai_pesan_json(): void
    {
        $this->actingAs($this->kasir)
            ->postJson(route('sales.store'), $this->payload([['method' => 'cash', 'amount' => '500000']], [
                'items' => [['product_id' => $this->ayam->id, 'quantity' => '25', 'discount' => '0']],
                'discount' => '0',
            ]))
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Stok tidak mencukupi. Ayam Geprek di Gudang Utama: tersedia 20, diminta 25.']);
    }

    public function test_pajak_belum_termasuk_harga_ditambahkan_ke_total(): void
    {
        $this->aturPajak(termasuk: false);

        // Dasar 28.000, PPN 11% = 3.080, total 31.080.
        $this->actingAs($this->kasir)
            ->postJson(route('sales.store'), $this->payload([['method' => 'cash', 'amount' => '31080']]))
            ->assertCreated();

        $jual = Sale::firstOrFail();
        $this->assertSame('11.00', $jual->tax_rate);
        $this->assertFalse($jual->tax_inclusive);
        $this->assertSame('3080.00', $jual->tax);
        $this->assertSame('31080.00', $jual->grand_total);
        $this->assertSame('31080.00', $jual->paid_amount);
    }

    public function test_pajak_termasuk_harga_tidak_mengubah_total(): void
    {
        $this->aturPajak(termasuk: true);

        // Total tetap 28.000; PPN di dalamnya = 28.000 x 11/111 = 2.774,77.
        $this->actingAs($this->kasir)
            ->postJson(route('sales.store'), $this->payload([['method' => 'cash', 'amount' => '28000']]))
            ->assertCreated();

        $jual = Sale::firstOrFail();
        $this->assertTrue($jual->tax_inclusive);
        $this->assertSame('2774.77', $jual->tax);
        $this->assertSame('28000.00', $jual->grand_total);
    }

    public function test_pajak_dibulatkan_sama_dengan_pratinjau_kasir(): void
    {
        // Nilai acuan juga diperiksa di resources/js/pos/hitung.js (hitungPajak): harus sama persis.
        $service = app(SaleService::class);

        $this->aturPajak(termasuk: true, tarif: '11');
        $this->assertSame(990991, $service->pajak(10000000)['sen']);

        $this->aturPajak(termasuk: false, tarif: '11.5');
        $this->assertSame(1150, $service->pajak(10000)['sen']);
        $this->assertSame(115, $service->pajak(1000)['sen']);
        $this->assertSame(6, $service->pajak(50)['sen'], '50 sen x 11,5% = 5,75 dibulatkan setengah ke atas.');
    }

    public function test_pajak_tidak_dipungut_bila_dimatikan_atau_tarif_nol(): void
    {
        setting(['tax.enabled' => true, 'tax.rate' => '0', 'tax.inclusive' => false]);

        $this->actingAs($this->kasir)
            ->postJson(route('sales.store'), $this->payload([['method' => 'cash', 'amount' => '28000']]))
            ->assertCreated();

        $jual = Sale::firstOrFail();
        $this->assertSame('0.00', $jual->tax);
        $this->assertSame('28000.00', $jual->grand_total);
    }

    public function test_struk_menampilkan_pajak_dan_rincian_pembayaran_campuran(): void
    {
        $this->aturPajak(termasuk: false);
        $this->actingAs($this->kasir)->postJson(route('sales.store'), $this->payload([
            ['method' => 'qris', 'amount' => '11080'],
            ['method' => 'cash', 'amount' => '50000'],
        ]))->assertCreated();
        $jual = Sale::firstOrFail();

        $this->get(route('sales.receipt', $jual))
            ->assertOk()
            ->assertSeeInOrder(['PPN 11%', '3.080', 'TOTAL', '31.080', 'Campuran', 'QRIS', '11.080', 'Tunai', '20.000', 'Kembali', '30.000']);

        $teks = $this->getJson(route('sales.receipt.text', $jual))->assertOk()->json('teks');
        $this->assertMatchesRegularExpression('/PPN 11%\s+3\.080/', $teks);
        $this->assertMatchesRegularExpression('/QRIS\s+11\.080/', $teks);
    }

    public function test_simpan_dari_kasir_mengembalikan_tautan_struk_dan_detail(): void
    {
        $respon = $this->actingAs($this->kasir)
            ->postJson(route('sales.store'), $this->payload([['method' => 'cash', 'amount' => '28000']]))
            ->assertCreated();

        $jual = Sale::firstOrFail();
        $respon->assertJson([
            'number' => $jual->number,
            'receipt_url' => route('sales.receipt', $jual),
            'show_url' => route('sales.show', $jual),
        ]);
    }

    public function test_event_penjualan_dan_uang_masuk_dikirim_per_metode(): void
    {
        Event::fake([SaleCompleted::class, CustomerPaymentRecorded::class]);

        $this->actingAs($this->kasir)->postJson(route('sales.store'), $this->payload([
            ['method' => 'qris', 'amount' => '8000'],
            ['method' => 'cash', 'amount' => '20000'],
        ]))->assertCreated();
        $jual = Sale::firstOrFail();

        Event::assertDispatchedTimes(SaleCompleted::class, 1);
        Event::assertDispatched(SaleCompleted::class, fn (SaleCompleted $e) => $e->sale->is($jual));
        Event::assertDispatchedTimes(CustomerPaymentRecorded::class, 2);
        Event::assertDispatched(CustomerPaymentRecorded::class, fn (CustomerPaymentRecorded $e) => $e->sale->is($jual) && $e->payment->method === PaymentMethod::Qris);
    }

    public function test_event_tidak_dikirim_bila_penjualan_ditolak(): void
    {
        Event::fake([SaleCompleted::class, CustomerPaymentRecorded::class]);

        $this->actingAs($this->kasir)->postJson(route('sales.store'), $this->payload([['method' => 'cash', 'amount' => '500000']], [
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '25', 'discount' => '0']],
        ]))->assertUnprocessable();

        Event::assertNotDispatched(SaleCompleted::class);
        Event::assertNotDispatched(CustomerPaymentRecorded::class);
    }

    public function test_pembatalan_mengirim_event_sale_voided(): void
    {
        Event::fake([SaleVoided::class]);
        $this->actingAs($this->kasir)->postJson(route('sales.store'), $this->payload([['method' => 'cash', 'amount' => '28000']]));
        $jual = Sale::firstOrFail();

        $this->actingAs($this->buatPengguna(RoleName::Admin))
            ->post(route('sales.void', $jual), ['alasan' => 'Salah input pesanan'])
            ->assertSessionHasNoErrors();

        Event::assertDispatched(SaleVoided::class, fn (SaleVoided $e) => $e->sale->is($jual));
    }
}
