<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Exceptions\AturanBisnisException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Stok per gudang lewat StockService, pemeriksaan stok:periksa, dan aturan halaman Gudang/Lokasi. */
class MultiGudangTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;

    private Warehouse $utama;

    private Warehouse $belakang;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->utama = Warehouse::default();
        $this->belakang = Warehouse::factory()->create(['code' => 'BLK', 'name' => 'Gudang Belakang']);
    }

    private function stokGudang(Warehouse $gudang, Product $produk): float
    {
        return (float) WarehouseStock::where('warehouse_id', $gudang->id)->where('product_id', $produk->id)->value('quantity');
    }

    // ---------------- StockService per gudang ----------------

    public function test_stok_dicatat_per_gudang_dan_stok_produk_adalah_total_semua_gudang(): void
    {
        $produk = Product::factory()->create();
        $stok = app(StockService::class);

        $stok->catat($produk, StockMovementType::Purchase, 10, null, null, $this->admin, $this->utama);
        $stok->catat($produk, StockMovementType::Purchase, 4, null, null, $this->admin, $this->belakang);

        $this->assertSame(10.0, $this->stokGudang($this->utama, $produk));
        $this->assertSame(4.0, $this->stokGudang($this->belakang, $produk));
        $this->assertSame(14.0, (float) $produk->fresh()->stock);
    }

    public function test_kartu_stok_mencatat_saldo_gudang_bukan_total(): void
    {
        $produk = Product::factory()->create();
        $stok = app(StockService::class);
        $stok->catat($produk, StockMovementType::Purchase, 10, null, null, $this->admin, $this->utama);

        $mutasi = $stok->catat($produk, StockMovementType::Purchase, 4, null, null, $this->admin, $this->belakang);

        $this->assertSame($this->belakang->id, $mutasi->warehouse_id);
        $this->assertSame(0.0, (float) $mutasi->stock_before);
        $this->assertSame(4.0, (float) $mutasi->stock_after);
    }

    public function test_stok_keluar_ditolak_bila_gudangnya_kurang_walau_total_cukup(): void
    {
        $produk = Product::factory()->create(['name' => 'Ayam Geprek']);
        $stok = app(StockService::class);
        $stok->catat($produk, StockMovementType::Purchase, 10, null, null, $this->admin, $this->utama);
        $stok->catat($produk, StockMovementType::Purchase, 2, null, null, $this->admin, $this->belakang);

        try {
            $stok->catat($produk, StockMovementType::Sale, -3, null, null, $this->admin, $this->belakang);
            $this->fail('Stok gudang yang kurang seharusnya ditolak.');
        } catch (AturanBisnisException $e) {
            $this->assertSame('Stok tidak mencukupi. Ayam Geprek di Gudang Belakang: tersedia 2, diminta 3.', $e->getMessage());
        }

        $this->assertSame(2.0, $this->stokGudang($this->belakang, $produk));
        $this->assertSame(12.0, (float) $produk->fresh()->stock);
        $this->assertSame(2, StockMovement::where('product_id', $produk->id)->count());
    }

    public function test_mutasi_tanpa_gudang_memakai_gudang_utama(): void
    {
        $produk = Product::factory()->create();

        $mutasi = app(StockService::class)->catat($produk, StockMovementType::Purchase, 5, null, null, $this->admin);

        $this->assertSame($this->utama->id, $mutasi->warehouse_id);
        $this->assertSame(5.0, $this->stokGudang($this->utama, $produk));
    }

    // ---------------- stok:periksa ----------------

    public function test_periksa_stok_menyatakan_konsisten_untuk_stok_di_beberapa_gudang(): void
    {
        $produk = Product::factory()->create();
        $stok = app(StockService::class);
        $stok->catat($produk, StockMovementType::Purchase, 10, null, null, $this->admin, $this->utama);
        $stok->catat($produk, StockMovementType::Purchase, 4, null, null, $this->admin, $this->belakang);

        $this->artisan('stok:periksa')
            ->expectsOutputToContain('konsisten')
            ->assertExitCode(0);
    }

    public function test_periksa_stok_mendeteksi_stok_gudang_yang_diubah_langsung_walau_total_tetap(): void
    {
        $produk = Product::factory()->create(['sku' => 'PINDAH-DIAM', 'name' => 'Ayam']);
        $stok = app(StockService::class);
        $stok->catat($produk, StockMovementType::Purchase, 10, null, null, $this->admin, $this->utama);
        $stok->catat($produk, StockMovementType::Purchase, 4, null, null, $this->admin, $this->belakang);
        DB::table('warehouse_stocks')->where('warehouse_id', $this->utama->id)->where('product_id', $produk->id)->update(['quantity' => 8]);
        DB::table('warehouse_stocks')->where('warehouse_id', $this->belakang->id)->where('product_id', $produk->id)->update(['quantity' => 6]);

        $this->artisan('stok:periksa')
            ->expectsOutputToContain('PINDAH-DIAM (Ayam) di Gudang Belakang: stok gudang 6, jumlah mutasi 4, saldo mutasi terakhir 4')
            ->assertExitCode(1);
    }

    // ---------------- Halaman Gudang/Lokasi ----------------

    public function test_menambah_gudang_dengan_kode_huruf_besar(): void
    {
        $this->actingAs($this->admin)
            ->post(route('warehouses.store'), ['code' => ' dpn ', 'name' => 'Gudang Depan', 'is_active' => '1'])
            ->assertRedirect(route('warehouses.index'));

        $this->assertDatabaseHas('warehouses', ['code' => 'DPN', 'name' => 'Gudang Depan', 'is_active' => true, 'is_default' => false]);
    }

    public function test_gudang_yang_masih_berisi_stok_tidak_bisa_dinonaktifkan(): void
    {
        $produk = Product::factory()->create();
        app(StockService::class)->catat($produk, StockMovementType::Purchase, 3, null, null, $this->admin, $this->belakang);

        $this->actingAs($this->admin)
            ->from(route('warehouses.edit', $this->belakang))
            ->put(route('warehouses.update', $this->belakang), ['code' => 'BLK', 'name' => 'Gudang Belakang'])
            ->assertRedirect(route('warehouses.edit', $this->belakang))
            ->assertSessionHas('error', 'Gudang Gudang Belakang masih berisi stok untuk 1 produk dan tidak bisa dinonaktifkan. Pindahkan stoknya dulu lewat Transfer Stok.');

        $this->assertTrue($this->belakang->fresh()->is_active);
    }

    public function test_menjadikan_gudang_lain_utama_mencabut_status_utama_sebelumnya(): void
    {
        $this->actingAs($this->admin)
            ->put(route('warehouses.update', $this->belakang), ['code' => 'BLK', 'name' => 'Gudang Belakang', 'is_active' => '1', 'is_default' => '1'])
            ->assertRedirect(route('warehouses.index'));

        $this->assertTrue($this->belakang->fresh()->is_default);
        $this->assertFalse($this->utama->fresh()->is_default);
        $this->assertSame(1, Warehouse::where('is_default', true)->count());
    }

    public function test_gudang_utama_tidak_bisa_dihapus(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('warehouses.destroy', $this->utama))
            ->assertSessionHas('error', 'Gudang utama tidak bisa dihapus. Jadikan gudang lain sebagai gudang utama terlebih dahulu.');

        $this->assertNotSoftDeleted($this->utama);
    }

    public function test_staf_gudang_bisa_melihat_tetapi_tidak_bisa_menambah_gudang(): void
    {
        $staf = $this->buatPengguna(RoleName::StafGudang);

        $this->actingAs($staf)->get(route('warehouses.index'))->assertOk()->assertSee('Gudang Belakang');
        $this->actingAs($staf)->post(route('warehouses.store'), ['code' => 'X1', 'name' => 'Gudang X'])->assertForbidden();

        $this->assertDatabaseMissing('warehouses', ['code' => 'X1']);
    }
}
