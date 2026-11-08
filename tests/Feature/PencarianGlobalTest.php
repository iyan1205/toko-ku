<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class PencarianGlobalTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        Customer::umum();
    }

    public function test_kasir_hanya_menemukan_penjualan_miliknya_sendiri(): void
    {
        $produk = Product::factory()->stok(10)->create();
        $kasir = $this->buatPengguna(RoleName::Kasir);
        $milikSendiri = $this->jual($kasir, $produk);
        $milikLain = $this->jual($this->buatPengguna(RoleName::Kasir), $produk);

        $this->actingAs($kasir)
            ->getJson(route('search', ['q' => 'POS-']))
            ->assertOk()
            ->assertJsonFragment(['title' => $milikSendiri->number])
            ->assertJsonMissing(['title' => $milikLain->number]);
    }

    public function test_kelompok_hasil_mengikuti_hak_akses(): void
    {
        Product::factory()->create(['name' => 'Ayam Geprek']);
        Customer::factory()->create(['name' => 'Ayam Jago Catering']);

        $this->actingAs($this->buatPengguna(RoleName::Admin))
            ->getJson(route('search', ['q' => 'ayam']))
            ->assertOk()
            ->assertJsonPath('groups.*.label', ['Produk', 'Pelanggan'])
            ->assertJsonPath('groups.0.items.0.title', 'Ayam Geprek');

        $this->actingAs($this->buatPengguna(RoleName::Kasir))
            ->getJson(route('search', ['q' => 'ayam']))
            ->assertOk()
            ->assertJsonPath('groups.*.label', ['Produk']);
    }

    public function test_kata_kunci_kurang_dari_dua_huruf_ditolak(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Admin))
            ->getJson(route('search', ['q' => 'a']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }

    public function test_tamu_tidak_bisa_mencari(): void
    {
        $this->getJson(route('search', ['q' => 'ayam']))->assertUnauthorized();
    }

    private function jual(User $kasir, Product $produk): Sale
    {
        return app(SaleService::class)->buat([
            'customer_id' => null,
            'items' => [['product_id' => $produk->id, 'quantity' => '1', 'discount' => '0']],
            'discount' => '0',
            'payment_method' => 'cash',
            'paid_amount' => '10000',
        ], $kasir);
    }
}
