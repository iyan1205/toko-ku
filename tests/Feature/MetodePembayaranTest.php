<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use App\Services\Settings\PaymentMethodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class MetodePembayaranTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $pemilik;

    private User $kasir;

    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->pemilik = $this->buatPengguna(RoleName::Pemilik);
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
        $this->produk = Product::factory()->stok(50)->create(['selling_price' => 10000]);
        Customer::umum();
    }

    /**
     * Semua metode dalam urutan bawaan, lalu diubah sesuai $ubah (kode => [label?, is_active?]).
     * $urutan (opsional) = daftar kode sesuai urutan tampil.
     *
     * @param  array<string, array{label?: string, is_active?: bool}>  $ubah
     * @param  list<string>|null  $urutan
     */
    private function payload(array $ubah = [], ?array $urutan = null): array
    {
        $kode = $urutan ?? array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::configurable());

        return ['methods' => array_map(fn (string $code) => [
            'code' => $code,
            'label' => $ubah[$code]['label'] ?? PaymentMethod::from($code)->defaultLabel(),
            'is_active' => ($ubah[$code]['is_active'] ?? true) ? '1' : '0',
        ], $kode)];
    }

    private function simpan(array $payload): void
    {
        $this->actingAs($this->pemilik)
            ->put(route('settings.payment-methods.update'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.payment-methods.edit'));

        // Request berikutnya membaca ulang pengaturan (sama seperti request baru di produksi).
        app()->forgetScopedInstances();
    }

    private function penjualan(string $metode): array
    {
        return [
            'items' => [['product_id' => $this->produk->id, 'quantity' => '1', 'discount' => '0']],
            'discount' => '0', 'payment_method' => $metode, 'paid_amount' => '10000',
        ];
    }

    public function test_bawaan_semua_metode_aktif_dengan_urutan_dan_nama_lama(): void
    {
        $this->assertSame(PaymentMethod::configurable(), PaymentMethod::untukPenjualan());
        $this->assertSame('QRIS', PaymentMethod::Qris->label());
    }

    public function test_campuran_bukan_metode_yang_bisa_diatur_atau_dipilih(): void
    {
        $this->assertNotContains(PaymentMethod::Mixed, PaymentMethod::untukPenjualan());
        $this->assertNotContains(PaymentMethod::Mixed, PaymentMethod::untukPembelian());

        $payload = $this->payload();
        $payload['methods'][0]['code'] = PaymentMethod::Mixed->value;

        $this->actingAs($this->pemilik)
            ->put(route('settings.payment-methods.update'), $payload)
            ->assertSessionHasErrors();
        $this->assertDatabaseMissing('payment_methods', ['code' => PaymentMethod::Mixed->value]);
    }

    public function test_kasir_hanya_menampilkan_metode_aktif_sesuai_urutan(): void
    {
        $this->simpan($this->payload(
            ['debit' => ['is_active' => false], 'credit' => ['is_active' => false]],
            ['qris', 'cash', 'transfer', 'debit', 'credit', 'ewallet', 'receivable'],
        ));

        $this->assertSame(
            [PaymentMethod::Qris, PaymentMethod::Cash, PaymentMethod::Transfer, PaymentMethod::EWallet, PaymentMethod::Receivable],
            PaymentMethod::untukPenjualan(),
        );

        $this->actingAs($this->kasir)->get(route('pos.index'))
            ->assertOk()
            ->assertSeeInOrder(['<option value="qris">QRIS</option>', '<option value="cash">Tunai</option>'], false)
            ->assertDontSee('<option value="debit">', false);
    }

    public function test_metode_pertama_yang_aktif_menjadi_pilihan_awal_kasir(): void
    {
        $this->simpan($this->payload(['cash' => ['is_active' => false]]));

        $this->actingAs($this->kasir)->get(route('pos.index'))
            ->assertOk()
            ->assertSee('metodeAwal\\u0022:\\u0022transfer', false);
    }

    public function test_penjualan_dengan_metode_nonaktif_ditolak(): void
    {
        $this->simpan($this->payload(['qris' => ['is_active' => false]]));

        $this->actingAs($this->kasir)
            ->post(route('sales.store'), $this->penjualan('qris'))
            ->assertSessionHasErrors('payment_method');

        $this->assertSame(0, Sale::count());
    }

    public function test_metode_dp_piutang_hanya_dari_metode_aktif(): void
    {
        $this->simpan($this->payload(['transfer' => ['is_active' => false]]));
        $pelanggan = Customer::factory()->create();

        $this->actingAs($this->kasir)
            ->post(route('sales.store'), $this->penjualan('receivable') + ['customer_id' => $pelanggan->id, 'dp_method' => 'transfer'])
            ->assertSessionHasErrors('dp_method');
    }

    public function test_nama_tampilan_dipakai_di_kasir_struk_dan_detail_penjualan(): void
    {
        $this->simpan($this->payload(['qris' => ['label' => 'QRIS BCA']]));

        $jual = app(SaleService::class)->buat($this->penjualan('qris'), $this->kasir);

        $this->actingAs($this->kasir)->get(route('pos.index'))->assertSee('<option value="qris">QRIS BCA</option>', false);
        $this->actingAs($this->kasir)->get(route('sales.receipt', $jual))->assertSee('QRIS BCA');
        $this->actingAs($this->kasir)->get(route('sales.show', $jual))->assertSee('QRIS BCA');
    }

    public function test_penjualan_lama_dengan_metode_yang_kini_nonaktif_tetap_bisa_dilihat(): void
    {
        $jual = app(SaleService::class)->buat($this->penjualan('debit'), $this->kasir);
        $this->simpan($this->payload(['debit' => ['is_active' => false]]));

        $this->actingAs($this->kasir)->get(route('sales.show', $jual))->assertOk()->assertSee('Debit');
        $this->actingAs($this->pemilik)->get(route('sales.index', ['metode' => 'debit']))->assertOk()->assertSee($jual->number);
    }

    public function test_minimal_satu_metode_selain_piutang_harus_aktif(): void
    {
        $semuaMati = collect(PaymentMethod::configurable())
            ->reject(fn (PaymentMethod $m) => $m === PaymentMethod::Receivable)
            ->mapWithKeys(fn (PaymentMethod $m) => [$m->value => ['is_active' => false]])
            ->all();

        $this->actingAs($this->pemilik)
            ->put(route('settings.payment-methods.update'), $this->payload($semuaMati))
            ->assertSessionHasErrors(['methods' => 'Aktifkan minimal satu metode selain Piutang.']);

        $this->assertCount(count(PaymentMethod::configurable()), PaymentMethod::untukPenjualan());
    }

    public function test_semua_metode_harus_dikirim_dan_tidak_boleh_ganda(): void
    {
        $payload = $this->payload();
        array_pop($payload['methods']);

        $this->actingAs($this->pemilik)
            ->put(route('settings.payment-methods.update'), $payload)
            ->assertSessionHasErrors('methods');

        $ganda = $this->payload();
        $ganda['methods'][1]['code'] = 'cash';

        $this->actingAs($this->pemilik)
            ->put(route('settings.payment-methods.update'), $ganda)
            ->assertSessionHasErrors('methods.1.code');
    }

    public function test_nama_tampilan_wajib_diisi(): void
    {
        $this->actingAs($this->pemilik)
            ->put(route('settings.payment-methods.update'), $this->payload(['cash' => ['label' => '  ']]))
            ->assertSessionHasErrors('methods.0.label');
    }

    public function test_perubahan_tercatat_di_log_aktivitas(): void
    {
        $this->simpan($this->payload(['ewallet' => ['label' => 'GoPay', 'is_active' => false]]));

        $log = Activity::query()->where('description', 'Metode pembayaran diubah')->sole();
        $this->assertSame('6. E-Wallet · aktif', $log->properties['old']['E-Wallet']);
        $this->assertSame('6. GoPay · nonaktif', $log->properties['attributes']['E-Wallet']);
    }

    public function test_admin_tanpa_izin_tidak_bisa_mengubah(): void
    {
        $admin = $this->buatPengguna(RoleName::Admin);

        $this->actingAs($admin)->get(route('settings.payment-methods.edit'))->assertForbidden();
        $this->actingAs($admin)
            ->put(route('settings.payment-methods.update'), $this->payload(['cash' => ['is_active' => false]]))
            ->assertForbidden();

        $this->assertContains(PaymentMethod::Cash, app(PaymentMethodService::class)->forSales());
    }
}
