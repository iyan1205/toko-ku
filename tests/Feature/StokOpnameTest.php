<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class StokOpnameTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;
    private Product $a;
    private Product $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->a = Product::factory()->stok(100)->create(['name' => 'Ayam Geprek', 'sku' => 'AYM-1']);
        $this->b = Product::factory()->stok(50)->create(['name' => 'Nasi Putih', 'sku' => 'NSI-1']);
    }

    private function payload(array $items, array $ubah = []): array
    {
        return array_merge(['opname_date' => now()->format('Y-m-d'), 'note' => 'Opname rak', 'items' => $items], $ubah);
    }

    private function item(Product $p, ?string $fisik, ?string $alasan = null, ?string $sistem = null): array
    {
        return [$p->id => ['system_stock' => $sistem ?? (string) (float) $p->stock, 'physical_stock' => $fisik, 'reason' => $alasan]];
    }

    public function test_opname_mencatat_selisih_dan_membuat_mutasi_adjustment_hanya_untuk_yang_berselisih(): void
    {
        $this->actingAs($this->admin)
            ->post(route('stock-opnames.store'), $this->payload($this->item($this->a, '97', 'Rusak 3') + $this->item($this->b, '50')))
            ->assertSessionHasNoErrors();

        $opname = StockOpname::firstOrFail();
        $this->assertSame('OPN-' . now()->format('Ymd') . '-0001', $opname->number);
        $this->assertSame($this->admin->id, $opname->user_id);

        $this->assertSame(97.0, (float) $this->a->fresh()->stock);
        $this->assertSame(50.0, (float) $this->b->fresh()->stock);

        $this->assertCount(2, $opname->items);
        $itemA = $opname->items->firstWhere('product_id', $this->a->id);
        $this->assertSame(100.0, (float) $itemA->system_stock);
        $this->assertSame(97.0, (float) $itemA->physical_stock);
        $this->assertSame(-3.0, (float) $itemA->difference);
        $this->assertSame('Rusak 3', $itemA->reason);
        $this->assertSame(0.0, (float) $opname->items->firstWhere('product_id', $this->b->id)->difference);

        $this->assertDatabaseCount('stock_movements', 1);
        $mutasi = StockMovement::firstOrFail();
        $this->assertSame(StockMovementType::Adjustment, $mutasi->type);
        $this->assertSame($this->a->id, $mutasi->product_id);
        $this->assertSame(-3.0, (float) $mutasi->quantity);
        $this->assertSame(100.0, (float) $mutasi->stock_before);
        $this->assertSame(97.0, (float) $mutasi->stock_after);
        $this->assertSame('stock_opname', $mutasi->reference_type);
        $this->assertSame($opname->id, $mutasi->reference_id);
        $this->assertSame($this->admin->id, $mutasi->user_id);
        $this->assertStringContainsString($opname->number, $mutasi->note);
        $this->assertStringContainsString('Rusak 3', $mutasi->note);
    }

    public function test_selisih_lebih_menambah_stok(): void
    {
        $this->actingAs($this->admin)
            ->post(route('stock-opnames.store'), $this->payload($this->item($this->a, '104', 'Ketemu di gudang')));

        $this->assertSame(104.0, (float) $this->a->fresh()->stock);
        $this->assertSame(4.0, (float) StockMovement::firstOrFail()->quantity);
    }

    public function test_stok_fisik_nol_valid_untuk_barang_yang_habis(): void
    {
        $this->actingAs($this->admin)
            ->post(route('stock-opnames.store'), $this->payload($this->item($this->a, '0', 'Habis terjual tanpa tercatat')))
            ->assertSessionHasNoErrors();

        $this->assertSame(0.0, (float) $this->a->fresh()->stock);
        $this->assertSame(-100.0, (float) StockMovement::firstOrFail()->quantity);
    }

    public function test_nomor_opname_berurutan_per_tanggal(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('stock-opnames.store'), $this->payload($this->item($this->a, '100')));
        $this->post(route('stock-opnames.store'), $this->payload($this->item($this->a, '100')));
        $this->post(route('stock-opnames.store'), $this->payload($this->item($this->a, '100'), ['opname_date' => '2026-01-15']));

        $this->assertEqualsCanonicalizing(
            ['OPN-' . now()->format('Ymd') . '-0001', 'OPN-' . now()->format('Ymd') . '-0002', 'OPN-20260115-0001'],
            StockOpname::pluck('number')->all()
        );
    }

    public function test_produk_yang_cocok_tidak_butuh_alasan_dan_tidak_membuat_mutasi(): void
    {
        $this->actingAs($this->admin)
            ->post(route('stock-opnames.store'), $this->payload($this->item($this->a, '100')))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_opname_items', 1);
    }

    public function test_alasan_wajib_bila_ada_selisih_dan_tidak_ada_yang_tersimpan(): void
    {
        $this->actingAs($this->admin)
            ->post(route('stock-opnames.store'), $this->payload($this->item($this->a, '97', '') + $this->item($this->b, '50')))
            ->assertSessionHasErrors("items.{$this->a->id}.reason");

        $this->assertDatabaseCount('stock_opnames', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(100.0, (float) $this->a->fresh()->stock);
    }

    public function test_produk_tanpa_stok_fisik_dilewati(): void
    {
        $this->actingAs($this->admin)
            ->post(route('stock-opnames.store'), $this->payload($this->item($this->a, '97', 'Rusak') + $this->item($this->b, null)));

        $opname = StockOpname::firstOrFail();
        $this->assertCount(1, $opname->items);
        $this->assertSame($this->a->id, $opname->items->first()->product_id);
        $this->assertSame(50.0, (float) $this->b->fresh()->stock);
    }

    public function test_opname_tanpa_satu_pun_produk_dihitung_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->post(route('stock-opnames.store'), $this->payload($this->item($this->a, null) + $this->item($this->b, null)))
            ->assertSessionHasErrors('items');

        $this->assertDatabaseCount('stock_opnames', 0);
    }

    public function test_opname_ditolak_bila_stok_berubah_sejak_halaman_dibuka(): void
    {
        // Halaman dibuka saat stok 100; lalu terjadi pengurangan 10 sebelum opname disimpan.
        $dilihat = $this->item($this->a, '97', 'Rusak', '100');
        app(StockService::class)->catat($this->a, StockMovementType::Sale, -10, null, null, $this->admin);

        $this->actingAs($this->admin)
            ->post(route('stock-opnames.store'), $this->payload($dilihat + $this->item($this->b, '50')))
            ->assertSessionHas('error');

        $this->assertStringContainsString('Stok berubah sejak halaman dibuka', session('error'));
        $this->assertStringContainsString('Ayam Geprek', session('error'));
        $this->assertDatabaseCount('stock_opnames', 0);
        $this->assertSame(90.0, (float) $this->a->fresh()->stock);
        // Hanya mutasi penjualan simulasi di atas; opname yang ditolak tidak menambah apa pun.
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_tanggal_masa_depan_dan_stok_fisik_negatif_ditolak(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('stock-opnames.store'), $this->payload($this->item($this->a, '97', 'x'), ['opname_date' => now()->addDay()->format('Y-m-d')]))
            ->assertSessionHasErrors('opname_date');

        $this->post(route('stock-opnames.store'), $this->payload($this->item($this->a, '-5', 'x')))
            ->assertSessionHasErrors("items.{$this->a->id}.physical_stock");

        $this->assertDatabaseCount('stock_opnames', 0);
        $this->assertSame(100.0, (float) $this->a->fresh()->stock);
    }

    public function test_halaman_opname_menampilkan_produk_aktif_dan_menghormati_filter(): void
    {
        Product::factory()->stok(5)->create(['name' => 'Barang Nonaktif', 'is_active' => false]);

        $this->actingAs($this->admin)
            ->get(route('stock-opnames.create'))
            ->assertOk()
            ->assertSee('Ayam Geprek')
            ->assertDontSee('Barang Nonaktif');

        $this->get(route('stock-opnames.create', ['kategori' => $this->a->category_id]))
            ->assertSee('Ayam Geprek')
            ->assertDontSee('Nasi Putih');
    }

    public function test_daftar_dan_detail_opname(): void
    {
        $this->actingAs($this->admin)
            ->post(route('stock-opnames.store'), $this->payload($this->item($this->a, '97', 'Rusak 3') + $this->item($this->b, '50')));

        $opname = StockOpname::firstOrFail();

        $this->get(route('stock-opnames.index'))->assertOk()->assertSee($opname->number);

        $this->get(route('stock-opnames.show', $opname))
            ->assertOk()
            ->assertSee($opname->number)
            ->assertSee('Ayam Geprek')
            ->assertSee('-3')
            ->assertSee('Rusak 3')
            ->assertSee('Cocok');
    }

    public function test_kasir_tidak_dapat_mengakses_opname(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);
        $opname = StockOpname::create(['number' => 'OPN-X-0001', 'opname_date' => today(), 'user_id' => $this->admin->id]);

        $this->actingAs($kasir);
        $this->get(route('stock-opnames.index'))->assertForbidden();
        $this->get(route('stock-opnames.create'))->assertForbidden();
        $this->get(route('stock-opnames.show', $opname))->assertForbidden();
        $this->post(route('stock-opnames.store'), $this->payload($this->item($this->a, '97', 'x')))->assertForbidden();

        $this->assertSame(100.0, (float) $this->a->fresh()->stock);
    }
}
