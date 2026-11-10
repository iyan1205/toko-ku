<?php

namespace Tests\Feature;

use App\Enums\PurchaseStatus;
use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Transfer stok antar gudang: langsung selesai dalam satu langkah, bisa dibatalkan, ada surat jalan. */
class TransferStokTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $staf;

    private Warehouse $utama;

    private Warehouse $belakang;

    private Product $ayam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->staf = $this->buatPengguna(RoleName::StafGudang);
        $this->utama = Warehouse::default();
        $this->belakang = Warehouse::factory()->create(['name' => 'Gudang Belakang']);
        $this->ayam = Product::factory()->create(['name' => 'Ayam Geprek']);
        app(StockService::class)->catat($this->ayam, StockMovementType::Purchase, 10, null, null, $this->staf, $this->utama);
    }

    private function stokGudang(Warehouse $gudang): float
    {
        return (float) WarehouseStock::where('warehouse_id', $gudang->id)->where('product_id', $this->ayam->id)->value('quantity');
    }

    /** @param array<string, mixed> $ubah */
    private function payload(array $ubah = []): array
    {
        return array_merge([
            'from_warehouse_id' => $this->utama->id,
            'to_warehouse_id' => $this->belakang->id,
            'transfer_date' => now()->format('Y-m-d'),
            'note' => 'Isi ulang dapur belakang',
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '4']],
        ], $ubah);
    }

    public function test_transfer_langsung_memindahkan_stok_tanpa_mengubah_total(): void
    {
        $response = $this->actingAs($this->staf)->post(route('stock-transfers.store'), $this->payload());

        $transfer = StockTransfer::sole();
        $response->assertRedirect(route('stock-transfers.show', $transfer));
        $this->assertSame(PurchaseStatus::Completed, $transfer->status);
        $this->assertSame(6.0, $this->stokGudang($this->utama));
        $this->assertSame(4.0, $this->stokGudang($this->belakang));
        $this->assertSame(10.0, (float) $this->ayam->fresh()->stock);
        $this->assertSame(
            [[StockMovementType::TransferOut, $this->utama->id, -4.0], [StockMovementType::TransferIn, $this->belakang->id, 4.0]],
            StockMovement::where('reference_type', $transfer->getMorphClass())->orderBy('id')->get()
                ->map(fn ($m) => [$m->type, $m->warehouse_id, (float) $m->quantity])->all(),
        );
    }

    public function test_transfer_melebihi_stok_gudang_asal_ditolak_tanpa_perubahan(): void
    {
        $this->actingAs($this->staf)
            ->post(route('stock-transfers.store'), $this->payload(['items' => [['product_id' => $this->ayam->id, 'quantity' => '11']]]))
            ->assertSessionHas('error', 'Stok tidak mencukupi. Ayam Geprek di Gudang Utama: tersedia 10, diminta 11.');

        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertSame(10.0, $this->stokGudang($this->utama));
    }

    public function test_gudang_asal_dan_tujuan_harus_berbeda(): void
    {
        $this->actingAs($this->staf)
            ->post(route('stock-transfers.store'), $this->payload(['to_warehouse_id' => $this->utama->id]))
            ->assertSessionHasErrors('to_warehouse_id');

        $this->assertDatabaseCount('stock_transfers', 0);
    }

    public function test_pembatalan_mengembalikan_stok_ke_gudang_asal(): void
    {
        $admin = $this->buatPengguna(RoleName::Admin);
        $this->actingAs($this->staf)->post(route('stock-transfers.store'), $this->payload());
        $transfer = StockTransfer::sole();

        $this->actingAs($admin)
            ->post(route('stock-transfers.void', $transfer), ['alasan' => 'Salah pilih gudang'])
            ->assertRedirect(route('stock-transfers.show', $transfer));

        $this->assertSame(PurchaseStatus::Voided, $transfer->fresh()->status);
        $this->assertSame('Salah pilih gudang', $transfer->fresh()->void_reason);
        $this->assertSame(10.0, $this->stokGudang($this->utama));
        $this->assertSame(0.0, $this->stokGudang($this->belakang));
        $this->assertSame(10.0, (float) $this->ayam->fresh()->stock);
    }

    public function test_pembatalan_ditolak_bila_barang_sudah_keluar_dari_gudang_tujuan(): void
    {
        $admin = $this->buatPengguna(RoleName::Admin);
        $this->actingAs($this->staf)->post(route('stock-transfers.store'), $this->payload());
        $transfer = StockTransfer::sole();
        app(StockService::class)->catat($this->ayam, StockMovementType::Sale, -3, null, null, $admin, $this->belakang);

        $this->actingAs($admin)
            ->post(route('stock-transfers.void', $transfer), ['alasan' => 'Salah pilih gudang'])
            ->assertSessionHas('error');

        $this->assertSame(PurchaseStatus::Completed, $transfer->fresh()->status);
        $this->assertSame(1.0, $this->stokGudang($this->belakang));
    }

    public function test_daftar_detail_dan_surat_jalan_pdf(): void
    {
        $this->actingAs($this->staf)->post(route('stock-transfers.store'), $this->payload());
        $transfer = StockTransfer::sole();

        $this->get(route('stock-transfers.index'))->assertOk()->assertSee($transfer->number);
        $this->get(route('stock-transfers.show', $transfer))->assertOk()->assertSee('Ayam Geprek')->assertSee('Gudang Belakang');
        $this->get(route('stock-transfers.print', $transfer))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_kasir_tidak_bisa_membuat_transfer(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);

        $this->actingAs($kasir)->post(route('stock-transfers.store'), $this->payload())->assertForbidden();

        $this->assertDatabaseCount('stock_transfers', 0);
    }
}
