<?php

namespace Tests\Feature;

use App\Enums\CashSessionStatus;
use App\Enums\RoleName;
use App\Models\Branch;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\SaleService;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/**
 * Sesi kasir: buka dengan modal awal di gudang penjualan, penjualan & pelunasan tertaut ke sesi,
 * tutup dengan uang fisik (selisih), verifikasi atasan, opsi wajib sesi, dan hak akses.
 */
class SesiKasirTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $kasir;

    private User $admin;

    private Warehouse $utama;

    private Warehouse $depan;

    private Product $ayam; // 10.000; stok 20 di Gudang Utama, 5 di Gudang Depan

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->utama = Warehouse::default();
        $this->depan = Warehouse::factory()->create(['name' => 'Gudang Depan']);
        $this->ayam = Product::factory()->stok(20)->create(['name' => 'Ayam Geprek', 'sku' => 'AYM-1', 'selling_price' => 10000]);
        (new WarehouseStock)->forceFill(['warehouse_id' => $this->depan->id, 'product_id' => $this->ayam->id, 'quantity' => 5])->save();
        $this->ayam->forceFill(['stock' => 25])->save();
        Customer::umum();
    }

    private function buka(User $user, Warehouse $gudang, string $modal = '100000'): CashSession
    {
        $this->actingAs($user)
            ->post(route('pos.sessions.store'), ['warehouse_id' => $gudang->id, 'opening_cash' => $modal])
            ->assertSessionHasNoErrors();

        return CashSession::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    /** @param list<array{method: string, amount: string}> $bayar */
    private function jual(User $user, string $jumlah = '1', array $bayar = [['method' => 'cash', 'amount' => '10000']]): Sale
    {
        $this->actingAs($user)->postJson(route('sales.store'), [
            'items' => [['product_id' => $this->ayam->id, 'quantity' => $jumlah, 'discount' => '0']],
            'discount' => '0',
            'payments' => $bayar,
        ])->assertCreated();

        return Sale::query()->latest('id')->firstOrFail();
    }

    private function stokGudang(Warehouse $gudang): float
    {
        return (float) WarehouseStock::where('warehouse_id', $gudang->id)->where('product_id', $this->ayam->id)->value('quantity');
    }

    public function test_buka_sesi_dengan_modal_awal_di_gudang_pilihan(): void
    {
        $sesi = $this->buka($this->kasir, $this->depan, '150000');

        $this->assertSame(CashSessionStatus::Open, $sesi->status);
        $this->assertSame($this->depan->id, $sesi->warehouse_id);
        $this->assertSame('150000.00', $sesi->opening_cash);
        $this->assertSame('SES-'.now()->format('Ymd').'-0001', $sesi->number);
    }

    public function test_satu_kasir_hanya_boleh_punya_satu_sesi_terbuka(): void
    {
        $this->buka($this->kasir, $this->utama);

        $this->actingAs($this->kasir)
            ->post(route('pos.sessions.store'), ['warehouse_id' => $this->utama->id, 'opening_cash' => '0'])
            ->assertSessionHas('error', fn (string $pesan) => str_contains($pesan, 'masih punya sesi kasir terbuka'));

        $this->assertSame(1, CashSession::query()->open()->count());
    }

    public function test_gudang_nonaktif_tidak_bisa_dipakai_membuka_sesi(): void
    {
        $tutup = Warehouse::factory()->nonaktif()->create();

        $this->actingAs($this->kasir)
            ->post(route('pos.sessions.store'), ['warehouse_id' => $tutup->id, 'opening_cash' => '0'])
            ->assertSessionHasErrors('warehouse_id');
    }

    public function test_penjualan_dalam_sesi_memakai_gudang_sesi_dan_tertaut_ke_sesi(): void
    {
        $sesi = $this->buka($this->kasir, $this->depan);
        $jual = $this->jual($this->kasir, '2', [['method' => 'cash', 'amount' => '20000']]);

        $this->assertSame($sesi->id, $jual->cash_session_id);
        $this->assertSame($this->depan->id, $jual->warehouse_id);
        $this->assertSame(3.0, $this->stokGudang($this->depan));
        $this->assertSame(20.0, $this->stokGudang($this->utama));
        $this->assertSame($sesi->id, $jual->payments()->sole()->cash_session_id);
    }

    public function test_stok_gudang_sesi_yang_kurang_ditolak_walau_gudang_lain_cukup(): void
    {
        $this->buka($this->kasir, $this->depan);

        $this->actingAs($this->kasir)->postJson(route('sales.store'), [
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '6', 'discount' => '0']],
            'payments' => [['method' => 'cash', 'amount' => '60000']],
        ])->assertUnprocessable()->assertJsonFragment(['message' => 'Stok tidak mencukupi. Ayam Geprek di Gudang Depan: tersedia 5, diminta 6.']);
    }

    public function test_pencarian_produk_kasir_menampilkan_stok_gudang_penjualan(): void
    {
        $this->actingAs($this->kasir)->getJson(route('pos.products', ['q' => 'AYM-1']))
            ->assertOk()->assertJsonPath('0.stock', 20)->assertJsonPath('0.exact', true);

        $this->buka($this->kasir, $this->depan);

        $this->getJson(route('pos.products', ['q' => 'Ayam']))
            ->assertOk()->assertJsonPath('0.stock', 5)->assertJsonPath('0.exact', false);
    }

    public function test_tanpa_sesi_penjualan_memakai_gudang_bawaan_cabang_aktif(): void
    {
        $cabang = Branch::query()->create(['code' => 'CB2', 'name' => 'Cabang Jabong', 'is_active' => true]);
        $this->depan->forceFill(['branch_id' => $cabang->id])->save();

        $this->actingAs($this->kasir)->withSession([CurrentBranch::SESSION_KEY => $cabang->id]);
        $jual = $this->jual($this->kasir);

        $this->assertNull($jual->cash_session_id);
        $this->assertSame($this->depan->id, $jual->warehouse_id);
        $this->assertSame(4.0, $this->stokGudang($this->depan));
    }

    public function test_wajib_sesi_menolak_penjualan_tanpa_sesi(): void
    {
        setting(['pos.require_session' => true]);

        $this->actingAs($this->kasir)->postJson(route('sales.store'), [
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '1', 'discount' => '0']],
            'payments' => [['method' => 'cash', 'amount' => '10000']],
        ])->assertUnprocessable()->assertJsonFragment(['message' => 'Buka sesi kasir dulu sebelum menjual.']);

        $this->assertDatabaseCount('sales', 0);

        $this->buka($this->kasir, $this->utama);
        $this->jual($this->kasir);
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_tutup_sesi_menghitung_tunai_seharusnya_dan_selisih(): void
    {
        $sesi = $this->buka($this->kasir, $this->utama, '100000');
        $this->jual($this->kasir, '2', [['method' => 'cash', 'amount' => '50000']]);           // tunai masuk 20.000
        $this->jual($this->kasir, '1', [['method' => 'qris', 'amount' => '10000']]);           // QRIS 10.000
        $batal = $this->jual($this->kasir, '1', [['method' => 'cash', 'amount' => '10000']]);  // dibatalkan: tidak dihitung
        $this->actingAs($this->admin)->post(route('sales.void', $batal), ['alasan' => 'Pelanggan batal beli']);

        // Uang fisik 119.000: seharusnya 120.000, kurang 1.000.
        $this->actingAs($this->kasir)
            ->post(route('pos.sessions.close', $sesi), ['closing_cash' => '119000', 'closing_note' => 'Kurang seribu'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $pesan) => str_contains($pesan, 'kurang Rp 1.000'));

        $sesi->refresh();
        $this->assertSame(CashSessionStatus::Closed, $sesi->status);
        $this->assertSame('120000.00', $sesi->expected_cash);
        $this->assertSame('119000.00', $sesi->closing_cash);
        $this->assertSame('-1000.00', $sesi->cash_difference);
        $this->assertSame(2, $sesi->summary['sales_count']);
        $this->assertSame(1, $sesi->summary['voided_count']);
        $this->assertSame('30000.00', $sesi->summary['sales_total']);
        $this->assertSame(
            ['cash' => '20000.00', 'qris' => '10000.00'],
            collect($sesi->summary['methods'])->pluck('net', 'method')->all(),
        );
    }

    public function test_rekap_sesi_yang_ditutup_tidak_berubah_oleh_transaksi_sesudahnya(): void
    {
        $sesi = $this->buka($this->kasir, $this->utama, '0');
        $this->jual($this->kasir);
        $this->post(route('pos.sessions.close', $sesi), ['closing_cash' => '10000']);

        $sesudah = $this->jual($this->kasir);

        $this->assertNull($sesudah->cash_session_id, 'Penjualan setelah sesi ditutup tidak masuk ke sesi itu.');
        $this->get(route('pos.sessions.show', $sesi))->assertOk()->assertSee('Rp 10.000');
        $this->assertSame(1, $sesi->fresh()->summary['sales_count']);
    }

    public function test_pelunasan_piutang_oleh_kasir_bersesi_masuk_rekap_tunai(): void
    {
        $pelanggan = Customer::factory()->create();
        $jual = app(SaleService::class)->buat([
            'customer_id' => $pelanggan->id,
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '1', 'discount' => '0']],
            'payment_method' => 'receivable', 'paid_amount' => '0',
        ], $this->admin);

        $sesi = $this->buka($this->admin, $this->utama, '0');
        $this->actingAs($this->admin)
            ->post(route('receivables.store', $jual), ['amount' => '4000', 'method' => 'cash', 'paid_at' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $this->post(route('pos.sessions.close', $sesi), ['closing_cash' => '4000'])->assertSessionHasNoErrors();
        $this->assertSame('4000.00', $sesi->fresh()->expected_cash);
        $this->assertSame('0.00', $sesi->fresh()->cash_difference);
    }

    public function test_sesi_yang_sudah_ditutup_tidak_bisa_ditutup_lagi(): void
    {
        $sesi = $this->buka($this->kasir, $this->utama);
        $this->post(route('pos.sessions.close', $sesi), ['closing_cash' => '100000']);

        $this->post(route('pos.sessions.close', $sesi), ['closing_cash' => '0'])->assertForbidden();
        $this->assertSame('100000.00', $sesi->fresh()->closing_cash);
    }

    public function test_kasir_hanya_melihat_dan_menutup_sesinya_sendiri(): void
    {
        $milikLain = $this->buka($this->buatPengguna(RoleName::Kasir), $this->utama);
        $milikSendiri = $this->buka($this->kasir, $this->depan);

        $this->actingAs($this->kasir);
        $this->get(route('pos.sessions.index'))->assertOk()->assertSee($milikSendiri->number)->assertDontSee($milikLain->number);
        $this->get(route('pos.sessions.show', $milikLain))->assertForbidden();
        $this->post(route('pos.sessions.close', $milikLain), ['closing_cash' => '0'])->assertForbidden();
        $this->assertTrue($milikLain->fresh()->isOpen());
    }

    public function test_atasan_melihat_semua_sesi_menutup_dan_memverifikasi(): void
    {
        $sesi = $this->buka($this->kasir, $this->utama);

        $this->actingAs($this->admin);
        $this->get(route('pos.sessions.index'))->assertOk()->assertSee($sesi->number)->assertSee($this->kasir->name);

        $this->post(route('pos.sessions.verify', $sesi))->assertForbidden(); // masih terbuka

        $this->post(route('pos.sessions.close', $sesi), ['closing_cash' => '100000'])->assertSessionHasNoErrors();
        $this->assertSame($this->admin->id, $sesi->fresh()->closed_by);

        $this->post(route('pos.sessions.verify', $sesi), ['verification_note' => 'Setoran diterima'])->assertSessionHasNoErrors();
        $sesi->refresh();
        $this->assertNotNull($sesi->verified_at);
        $this->assertSame($this->admin->id, $sesi->verified_by);

        $this->post(route('pos.sessions.verify', $sesi))->assertForbidden(); // sudah diverifikasi
    }

    public function test_kasir_tidak_bisa_memverifikasi_sesi(): void
    {
        $sesi = $this->buka($this->kasir, $this->utama);
        $this->post(route('pos.sessions.close', $sesi), ['closing_cash' => '100000']);

        $this->post(route('pos.sessions.verify', $sesi))->assertForbidden();
        $this->assertNull($sesi->fresh()->verified_at);
    }

    public function test_layar_kasir_menampilkan_status_sesi(): void
    {
        $this->actingAs($this->kasir)->get(route('pos.index'))
            ->assertOk()->assertSee('Tanpa sesi')->assertSee('Buka sesi');

        $sesi = $this->buka($this->kasir, $this->depan);

        $this->get(route('pos.index'))
            ->assertOk()->assertSee('Sesi '.$sesi->number)->assertSee('Gudang Depan')->assertDontSee('Tanpa sesi');
    }
}
