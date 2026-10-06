<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class PenyesuaianStokTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;
    private User $kasir;
    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
        $this->produk = Product::factory()->stok(10)->create(['purchase_price' => 8000]);
    }

    private function payload(array $ubah = []): array
    {
        return array_merge([
            'product_id' => $this->produk->id,
            'type' => 'adjustment',
            'direction' => 'in',
            'quantity' => '5',
            'note' => 'Stok awal',
        ], $ubah);
    }

    public function test_admin_menambah_stok_lewat_penyesuaian(): void
    {
        $this->actingAs($this->admin)
            ->post(route('stock.adjust.store'), $this->payload())
            ->assertRedirect(route('stock.movements', ['produk' => $this->produk->id]))
            ->assertSessionHas('success');

        $this->assertSame(15.0, (float) $this->produk->fresh()->stock);

        $mutasi = $this->produk->stockMovements()->firstOrFail();
        $this->assertSame(StockMovementType::Adjustment, $mutasi->type);
        $this->assertSame($this->admin->id, $mutasi->user_id);
        $this->assertSame('Stok awal', $mutasi->note);
    }

    public function test_admin_mengurangi_stok_lewat_penyesuaian(): void
    {
        $this->actingAs($this->admin)
            ->post(route('stock.adjust.store'), $this->payload(['direction' => 'out', 'quantity' => '4', 'note' => 'Koreksi hitung']));

        $this->assertSame(6.0, (float) $this->produk->fresh()->stock);
    }

    public function test_barang_rusak_dan_hilang_mengurangi_stok_tanpa_arah(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('stock.adjust.store'), ['product_id' => $this->produk->id, 'type' => 'damaged', 'quantity' => '2', 'note' => 'Botol pecah'])
            ->assertSessionHasNoErrors();
        $this->post(route('stock.adjust.store'), ['product_id' => $this->produk->id, 'type' => 'lost', 'quantity' => '1', 'note' => 'Tidak ditemukan'])
            ->assertSessionHasNoErrors();

        $this->assertSame(7.0, (float) $this->produk->fresh()->stock);
        $this->assertEqualsCanonicalizing(
            [StockMovementType::Damaged, StockMovementType::Lost],
            $this->produk->stockMovements()->get()->pluck('type')->all()
        );
    }

    public function test_validasi_alasan_jumlah_arah_dan_jenis(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('stock.adjust.store'), $this->payload(['note' => '']))->assertSessionHasErrors('note');
        $this->post(route('stock.adjust.store'), $this->payload(['quantity' => '0']))->assertSessionHasErrors('quantity');
        $this->post(route('stock.adjust.store'), $this->payload(['quantity' => '-3']))->assertSessionHasErrors('quantity');
        $this->post(route('stock.adjust.store'), $this->payload(['direction' => null]))->assertSessionHasErrors('direction');
        $this->post(route('stock.adjust.store'), $this->payload(['type' => 'sale']))->assertSessionHasErrors('type');

        $this->assertSame(10.0, (float) $this->produk->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_produk_yang_sudah_dihapus_ditolak(): void
    {
        $this->produk->delete();

        $this->actingAs($this->admin)
            ->post(route('stock.adjust.store'), $this->payload())
            ->assertSessionHasErrors('product_id');
    }

    public function test_pengurangan_melebihi_stok_ditolak_dengan_pesan_jelas(): void
    {
        $this->actingAs($this->admin)
            ->post(route('stock.adjust.store'), $this->payload(['direction' => 'out', 'quantity' => '50']))
            ->assertSessionHas('error');

        $this->assertStringStartsWith('Stok tidak mencukupi.', session('error'));
        $this->assertSame(10.0, (float) $this->produk->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_kasir_melihat_stok_tanpa_nilai_persediaan_dan_tanpa_akses_mutasi(): void
    {
        $this->actingAs($this->kasir)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee($this->produk->name)
            ->assertDontSee('Nilai persediaan')
            ->assertDontSee('Rp 80.000')
            ->assertDontSee('Penyesuaian stok');

        $this->get(route('stock.movements'))->assertForbidden();
        $this->get(route('stock.adjust.create'))->assertForbidden();
        $this->post(route('stock.adjust.store'), $this->payload())->assertForbidden();

        $this->assertSame(10.0, (float) $this->produk->fresh()->stock);
    }

    public function test_admin_melihat_nilai_persediaan(): void
    {
        $this->actingAs($this->admin)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('Nilai persediaan')
            ->assertSee('Rp 80.000');
    }

    public function test_riwayat_mutasi_dapat_difilter_per_produk_jenis_dan_tanggal(): void
    {
        $lain = Product::factory()->stok(5)->create();
        $stok = app(StockService::class);

        $stok->catat($this->produk, StockMovementType::Purchase, 3, null, 'Beli sambal', $this->admin);
        $stok->catat($lain, StockMovementType::Damaged, -1, null, 'Pecah teh', $this->admin);

        $this->actingAs($this->admin);

        $this->get(route('stock.movements', ['produk' => $this->produk->id]))
            ->assertOk()->assertSee('Beli sambal')->assertDontSee('Pecah teh');

        $this->get(route('stock.movements', ['jenis' => 'damaged']))
            ->assertSee('Pecah teh')->assertDontSee('Beli sambal');

        $this->get(route('stock.movements', ['dari' => now()->addDay()->format('Y-m-d')]))
            ->assertSee('Belum ada mutasi stok yang cocok');

        $this->get(route('stock.movements', ['dari' => '2026-10-10', 'sampai' => '2026-10-01']))
            ->assertSessionHasErrors('sampai');
    }

    public function test_detail_produk_menampilkan_mutasi_terakhir_hanya_untuk_pengelola_stok(): void
    {
        app(StockService::class)->catat($this->produk, StockMovementType::Purchase, 3, null, null, $this->admin);

        $this->actingAs($this->admin)->get(route('products.show', $this->produk))
            ->assertOk()->assertSee('Mutasi terakhir')->assertSee('Pembelian');

        $this->actingAs($this->kasir)->get(route('products.show', $this->produk))
            ->assertOk()->assertDontSee('Mutasi terakhir');
    }
}
