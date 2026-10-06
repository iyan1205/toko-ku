<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class PeriksaStokTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    public function test_stok_yang_hanya_berubah_lewat_service_dinyatakan_konsisten(): void
    {
        $this->siapkanPeran();
        $user = $this->buatPengguna(RoleName::Admin);
        $produk = Product::factory()->create();
        $stok = app(StockService::class);

        $stok->catat($produk, StockMovementType::Purchase, 10, null, null, $user);
        $stok->catat($produk, StockMovementType::Sale, -4, null, null, $user);

        $this->artisan('stok:periksa')
            ->expectsOutputToContain('konsisten')
            ->assertExitCode(0);
    }

    public function test_stok_yang_diubah_langsung_di_database_terdeteksi(): void
    {
        $this->siapkanPeran();
        $user = $this->buatPengguna(RoleName::Admin);
        $produk = Product::factory()->create(['sku' => 'CURANG-1']);

        app(StockService::class)->catat($produk, StockMovementType::Purchase, 10, null, null, $user);
        DB::table('products')->where('id', $produk->id)->update(['stock' => 999]);

        $this->artisan('stok:periksa')
            ->expectsOutputToContain('CURANG-1')
            ->assertExitCode(1);
    }

    public function test_stok_tanpa_riwayat_mutasi_terdeteksi(): void
    {
        Product::factory()->stok(25)->create(['sku' => 'TANPA-RIWAYAT']);

        $this->artisan('stok:periksa')
            ->expectsOutputToContain('TANPA-RIWAYAT')
            ->assertExitCode(1);
    }
}
