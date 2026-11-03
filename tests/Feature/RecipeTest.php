<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Category;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\RecipeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class RecipeTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $superAdmin;
    private User $admin;
    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->superAdmin = $this->buatPengguna(RoleName::SuperAdmin);
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
    }

    private function bahan(string $nama, float $harga): Product
    {
        return Product::factory()->create([
            'name' => $nama,
            'purchase_price' => $harga,
            'selling_price' => 0,
            'unit_id' => Unit::where('name', 'PCS')->firstOrCreate(['name' => 'PCS'])->id,
        ]);
    }

    // ---------------- Seeder bahan baku ----------------

    public function test_seeder_bahan_baku_masuk_database(): void
    {
        $this->artisan('db:seed', ['--class' => 'Database\Seeders\BahanBakuSeeder']);

        $this->assertDatabaseHas('products', ['name' => 'Ayam Karkas 1/9 Potong', 'purchase_price' => 5000]);
        $this->assertDatabaseHas('products', ['name' => 'Minyak Resto', 'purchase_price' => 225000]);
        $this->assertDatabaseHas('products', ['name' => 'Sosis 12 pcs', 'purchase_price' => 65000]);

        // 26 bahan baku + 3 operasional = 29 item.
        $this->assertSame(29, Category::where('name', 'Bahan Baku')->first()->products()->count()
            + Category::where('name', 'Operasional')->first()->products()->count());
    }

    public function test_seeder_bahan_baku_idempotent(): void
    {
        $this->artisan('db:seed', ['--class' => 'Database\Seeders\BahanBakuSeeder']);
        $this->artisan('db:seed', ['--class' => 'Database\Seeders\BahanBakuSeeder']);

        // Tidak ada duplikat walau dijalankan dua kali.
        $this->assertSame(29, Category::where('name', 'Bahan Baku')->first()->products()->count()
            + Category::where('name', 'Operasional')->first()->products()->count());
    }

    // ---------------- Perhitungan biaya ----------------

    public function test_biaya_produk_berdasarkan_harga_bahan_terkini(): void
    {
        $bahan = $this->bahan('Ayam Karkas', 45000);
        $produk = Product::factory()->create(['name' => 'Ayam Geprek', 'selling_price' => 10000]);

        $recipe = (new RecipeService)->simpan($produk, [['product_id' => $bahan->id, 'quantity' => '1']]);

        $this->assertSame(45000.0, (new RecipeService)->hitungBiaya($recipe));

        // Harga bahan naik -> biaya ikut naik, tanpa ubah resep.
        $bahan->update(['purchase_price' => 50000]);
        $this->assertSame(50000.0, (new RecipeService)->hitungBiaya($recipe->fresh()));
    }

    public function test_biaya_berkala_sebagian_bahan(): void
    {
        $bahan = $bahan = $this->bahan('Ayam Karkas', 45000);
        $produk = Product::factory()->create(['name' => 'Ayam Geprek', 'selling_price' => 10000]);

        // 1/9 potong ayam = 0,111.
        $recipe = (new RecipeService)->simpan($produk, [['product_id' => $bahan->id, 'quantity' => '0.111']]);

        $this->assertEqualsWithDelta(4995.0, (new RecipeService)->hitungBiaya($recipe), 0.01);
    }

    public function test_banyak_bahan_diakumulasikan(): void
    {
        $ayam = $this->bahan('Ayam Karkas', 45000);
        $tepung = $this->bahan('Tepung ORI', 19500);
        $produk = Product::factory()->create(['name' => 'Ayam Geprek', 'selling_price' => 15000]);

        $recipe = (new RecipeService)->simpan($produk, [
            ['product_id' => $ayam->id, 'quantity' => '0.111'],
            ['product_id' => $tepung->id, 'quantity' => '0.05'],
        ]);

        // 0,111 × 45.000 = 4.995 + 0,05 × 19.500 = 975 → total 5.970
        $this->assertEqualsWithDelta(5970.0, (new RecipeService)->hitungBiaya($recipe), 0.01);
    }

    // ---------------- Hak akses ----------------

    public function test_admin_dapat_membuat_dan_melihat_resep(): void
    {
        $bahan = $this->bahan('Ayam Karkas', 45000);
        $produk = Product::factory()->create(['name' => 'Ayam Geprek', 'selling_price' => 10000]);

        // Form buat resep.
        $this->actingAs($this->superAdmin)
            ->post(route('recipes.store'), [
                'product_id' => $produk->id,
                'notes' => 'Resep ayam geprek',
                'items' => [['product_id' => $bahan->id, 'quantity' => '0.111', 'notes' => '']],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $recipe = $produk->recipe()->first();
        $this->assertNotNull($recipe);
        $this->assertSame('Resep ayam geprek', $recipe->notes);
        $this->assertSame(1, $recipe->items()->count());

        // Detail.
        $this->actingAs($this->superAdmin)
            ->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('Ayam Geprek')
            ->assertSee('Ayam Karkas');
    }

    public function test_kasir_tidak_bisa_membuat_resep(): void
    {
        $bahan = $this->bahan('Ayam Karkas', 45000);
        $produk = Product::factory()->create(['name' => 'Ayam Geprek']);

        $this->actingAs($this->kasir)
            ->post(route('recipes.store'), [
                'product_id' => $produk->id,
                'items' => [['product_id' => $bahan->id, 'quantity' => '1', 'notes' => '']],
            ])
            ->assertForbidden();
    }

    public function test_kasir_tidak_bisa_melihat_detail_resep(): void
    {
        $bahan = $this->bahan('Ayam Karkas', 45000);
        $produk = Product::factory()->create(['name' => 'Ayam Geprek']);
        $recipe = (new RecipeService)->simpan($produk, [['product_id' => $bahan->id, 'quantity' => '1']]);

        // Kasir tidak punya izin view-recipes (informasi biaya hanya untuk Admin/Super Admin).
        $this->actingAs($this->kasir)
            ->get(route('recipes.show', $recipe))
            ->assertForbidden();
    }

    // ---------------- Validasi ----------------

    public function test_bahan_tidak_boleh_sama_dengan_produk_jualnya(): void
    {
        $produk = Product::factory()->create(['name' => 'Ayam Geprek']);

        $this->actingAs($this->superAdmin)
            ->post(route('recipes.store'), [
                'product_id' => $produk->id,
                'items' => [['product_id' => $produk->id, 'quantity' => '1', 'notes' => '']],
            ])
            ->assertSessionHasErrors('items.0.product_id');
    }

    public function test_bahan_tidak_boleh_ganda(): void
    {
        $bahan = $this->bahan('Ayam Karkas', 45000);
        $produk = Product::factory()->create(['name' => 'Ayam Geprek']);

        $this->actingAs($this->superAdmin)
            ->post(route('recipes.store'), [
                'product_id' => $produk->id,
                'items' => [
                    ['product_id' => $bahan->id, 'quantity' => '0.5', 'notes' => ''],
                    ['product_id' => $bahan->id, 'quantity' => '0.3', 'notes' => ''],
                ],
            ])
            ->assertSessionHasErrors('items.1.product_id');
    }

    public function test_jumlah_nol_ditolak(): void
    {
        $bahan = $this->bahan('Ayam Karkas', 45000);
        $produk = Product::factory()->create(['name' => 'Ayam Geprek']);

        $this->actingAs($this->superAdmin)
            ->post(route('recipes.store'), [
                'product_id' => $produk->id,
                'items' => [['product_id' => $bahan->id, 'quantity' => '0', 'notes' => '']],
            ])
            ->assertSessionHasErrors('items.0.quantity');
    }

    // ---------------- Menu sidebar ----------------

    public function test_menu_resep_muncul_untuk_admin(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('recipes.index'))
            ->assertOk()
            ->assertSee('Resep (Bill of Materials)')
            ->assertSee('Buat Resep');
    }

    public function test_menu_resep_tidak_muncul_untuk_kasir(): void
    {
        $this->actingAs($this->kasir)
            ->get(route('recipes.index'))
            ->assertForbidden();
    }
}
