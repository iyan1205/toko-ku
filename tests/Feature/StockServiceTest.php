<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Exceptions\AturanBisnisException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private StockService $stok;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->user = $this->buatPengguna(RoleName::Admin);
        $this->stok = app(StockService::class);
    }

    public function test_stok_masuk_menambah_stok_dan_mencatat_mutasi_lengkap(): void
    {
        $produk = Product::factory()->create();

        $mutasi = $this->stok->catat($produk, StockMovementType::Purchase, 10, null, 'Beli dari supplier', $this->user);

        $this->assertSame(10.0, (float) $produk->fresh()->stock);
        $this->assertSame(10.0, (float) $produk->stock, 'Instance milik pemanggil harus ikut tersinkron.');
        $this->assertSame(StockMovementType::Purchase, $mutasi->type);
        $this->assertSame(10.0, (float) $mutasi->quantity);
        $this->assertSame(0.0, (float) $mutasi->stock_before);
        $this->assertSame(10.0, (float) $mutasi->stock_after);
        $this->assertSame($this->user->id, $mutasi->user_id);
        $this->assertSame('Beli dari supplier', $mutasi->note);
        $this->assertNotNull($mutasi->created_at);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_stok_keluar_memakai_jumlah_negatif(): void
    {
        $produk = Product::factory()->stok(10)->create();

        $mutasi = $this->stok->catat($produk, StockMovementType::Sale, -3, null, null, $this->user);

        $this->assertSame(7.0, (float) $produk->fresh()->stock);
        $this->assertSame(-3.0, (float) $mutasi->quantity);
        $this->assertSame(10.0, (float) $mutasi->stock_before);
        $this->assertSame(7.0, (float) $mutasi->stock_after);
    }

    public function test_mutasi_menyimpan_rujukan_dokumen_dengan_nama_pendek(): void
    {
        $produk = Product::factory()->create();
        $opname = StockOpname::create(['number' => 'OPN-TES-0001', 'opname_date' => today(), 'user_id' => $this->user->id]);

        $mutasi = $this->stok->catat($produk, StockMovementType::Adjustment, 5, $opname, null, $this->user);

        $this->assertSame('stock_opname', $mutasi->reference_type);
        $this->assertSame($opname->id, $mutasi->reference_id);
        $this->assertTrue($mutasi->fresh()->reference->is($opname));
    }

    public function test_penjualan_melebihi_stok_ditolak_dan_tidak_mengubah_apa_pun(): void
    {
        $produk = Product::factory()->stok(3)->create(['name' => 'Ayam Geprek']);

        try {
            $this->stok->catat($produk, StockMovementType::Sale, -5, null, null, $this->user);
            $this->fail('Seharusnya melempar AturanBisnisException.');
        } catch (AturanBisnisException $e) {
            $this->assertStringStartsWith('Stok tidak mencukupi.', $e->getMessage());
            $this->assertStringContainsString('Ayam Geprek', $e->getMessage());
            $this->assertStringContainsString('tersedia 3', $e->getMessage());
        }

        $this->assertSame(3.0, (float) $produk->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_stok_habis_pas_diperbolehkan_menjadi_nol(): void
    {
        $produk = Product::factory()->stok(3)->create();

        $this->stok->catat($produk, StockMovementType::Sale, -3, null, null, $this->user);

        $this->assertSame(0.0, (float) $produk->fresh()->stock);
    }

    public function test_stok_minus_hanya_boleh_bila_diaktifkan_di_konfigurasi(): void
    {
        config(['pos.allow_negative_stock' => true]);
        $produk = Product::factory()->stok(3)->create();

        $this->stok->catat($produk, StockMovementType::Sale, -5, null, null, $this->user);

        $this->assertSame(-2.0, (float) $produk->fresh()->stock);
    }

    public function test_perubahan_nol_ditolak(): void
    {
        $produk = Product::factory()->stok(3)->create();

        foreach ([0, 0.0004] as $nol) {
            try {
                $this->stok->catat($produk, StockMovementType::Adjustment, $nol, null, null, $this->user);
                $this->fail('Seharusnya ditolak.');
            } catch (AturanBisnisException $e) {
                $this->assertSame('Jumlah perubahan stok tidak boleh nol.', $e->getMessage());
            }
        }

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_jumlah_desimal_untuk_satuan_kg_dihitung_tepat(): void
    {
        $produk = Product::factory()->create();

        $this->stok->catat($produk, StockMovementType::Purchase, 2.5, null, null, $this->user);
        $this->stok->catat($produk, StockMovementType::Purchase, 0.25, null, null, $this->user);
        $this->stok->catat($produk, StockMovementType::Sale, -1.125, null, null, $this->user);

        $this->assertSame(1.625, (float) $produk->fresh()->stock);
    }

    public function test_saldo_mutasi_berantai_tanpa_celah(): void
    {
        $produk = Product::factory()->create();

        foreach ([10, -4, 7, -13, 6] as $delta) {
            $this->stok->catat($produk, StockMovementType::Adjustment, $delta, null, null, $this->user);
        }

        $baris = StockMovement::where('product_id', $produk->id)->orderBy('id')->get();
        $this->assertCount(5, $baris);

        $sebelumnya = 0.0;
        foreach ($baris as $m) {
            $this->assertSame($sebelumnya, (float) $m->stock_before);
            $this->assertSame((float) $m->stock_before + (float) $m->quantity, (float) $m->stock_after);
            $sebelumnya = (float) $m->stock_after;
        }

        $this->assertSame(6.0, (float) $produk->fresh()->stock);
    }

    public function test_riwayat_stok_tidak_dapat_diubah_atau_dihapus(): void
    {
        $mutasi = $this->stok->catat(Product::factory()->create(), StockMovementType::Purchase, 5, null, null, $this->user);

        try {
            $mutasi->update(['quantity' => 99]);
            $this->fail('Update seharusnya ditolak.');
        } catch (LogicException $e) {
            $this->assertSame('Riwayat stok tidak boleh diubah.', $e->getMessage());
        }

        try {
            $mutasi->delete();
            $this->fail('Hapus seharusnya ditolak.');
        } catch (LogicException $e) {
            $this->assertSame('Riwayat stok tidak boleh dihapus.', $e->getMessage());
        }

        $this->assertSame(5.0, (float) $mutasi->fresh()->quantity);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_kegagalan_di_tengah_transaksi_membatalkan_stok_dan_mutasi(): void
    {
        $produk = Product::factory()->stok(10)->create();

        try {
            DB::transaction(function () use ($produk) {
                $this->stok->catat($produk, StockMovementType::Sale, -4, null, null, $this->user);
                throw new RuntimeException('gagal setelah stok berubah');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(10.0, (float) $produk->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_penyesuaian_manual_arah_dan_jenis(): void
    {
        $produk = Product::factory()->stok(10)->create();

        $this->stok->sesuaikan($produk, StockMovementType::Adjustment, 'in', 5, 'Stok awal', $this->user);
        $this->assertSame(15.0, (float) $produk->fresh()->stock);

        $this->stok->sesuaikan($produk, StockMovementType::Adjustment, 'out', 2, 'Koreksi', $this->user);
        $this->assertSame(13.0, (float) $produk->fresh()->stock);

        // Rusak dan hilang selalu mengurangi, apa pun arah yang dikirim.
        $this->stok->sesuaikan($produk, StockMovementType::Damaged, 'in', 3, 'Pecah', $this->user);
        $this->stok->sesuaikan($produk, StockMovementType::Lost, 'in', 1, 'Hilang', $this->user);
        $this->assertSame(9.0, (float) $produk->fresh()->stock);
    }

    public function test_jenis_non_manual_tidak_dapat_dibuat_lewat_penyesuaian(): void
    {
        $this->expectException(AturanBisnisException::class);

        $this->stok->sesuaikan(Product::factory()->stok(10)->create(), StockMovementType::Sale, 'out', 1, 'x', $this->user);
    }
}
