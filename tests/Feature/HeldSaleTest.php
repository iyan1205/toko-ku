<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\HeldSale;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class HeldSaleTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $kasir;
    private Product $ayam;
    private Product $nasi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
        $this->ayam = Product::factory()->stok(10)->create(['name' => 'Ayam Geprek', 'selling_price' => 10000]);
        $this->nasi = Product::factory()->stok(10)->create(['name' => 'Nasi Putih', 'selling_price' => 5000]);
    }

    private function payload(array $ubah = []): array
    {
        return array_merge([
            'customer_id' => null,
            'label' => 'Meja 3',
            'items' => [
                ['product_id' => $this->ayam->id, 'quantity' => '2', 'discount' => '0'],
                ['product_id' => $this->nasi->id, 'quantity' => '1', 'discount' => '500'],
            ],
            'discount' => '1000',
        ], $ubah);
    }

    public function test_kasir_dapat_menahan_dan_memanggil_kembali_transaksi(): void
    {
        $this->actingAs($this->kasir)
            ->postJson(route('pos.hold.store'), $this->payload())
            ->assertCreated()
            ->assertJsonFragment(['label' => 'Meja 3']);

        $tahan = HeldSale::firstOrFail();
        $this->assertSame($this->kasir->id, $tahan->user_id);
        $this->assertSame(2, count($tahan->cart['items']));
        $this->assertSame(1000.0, (float) $tahan->cart['discount']);

        $hasil = $this->getJson(route('pos.hold.show', $tahan))->assertOk()->json();

        $this->assertCount(2, $hasil['items']);
        $this->assertSame(0, $hasil['produk_hilang']);
        $this->assertEquals(1000, $hasil['discount']);
        $baris = collect($hasil['items'])->firstWhere('product_id', $this->ayam->id);
        $this->assertSame('Ayam Geprek', $baris['name']);
        $this->assertSame(10000.0, $baris['unit_price']);
    }

    public function test_daftar_tahan_berisi_ringkasan_untuk_kasir_yang_bersangkutan(): void
    {
        $this->actingAs($this->kasir)->postJson(route('pos.hold.store'), $this->payload());

        $daftar = $this->getJson(route('pos.hold.index'))->assertOk()->json();

        $this->assertCount(1, $daftar);
        $this->assertSame('Meja 3', $daftar[0]['label']);
        $this->assertSame(2, $daftar[0]['items']);
        $this->assertSame('Umum', $daftar[0]['customer']);
    }

    public function test_tahan_dengan_pelanggan_menampilkan_nama_pelanggan_saat_dipanggil(): void
    {
        $pelanggan = Customer::factory()->create(['name' => 'Budi Santoso']);

        $this->actingAs($this->kasir)->postJson(route('pos.hold.store'), $this->payload(['customer_id' => $pelanggan->id]));
        $tahan = HeldSale::firstOrFail();

        $daftar = $this->getJson(route('pos.hold.index'))->json();
        $this->assertSame('Budi Santoso', $daftar[0]['customer']);

        $hasil = $this->getJson(route('pos.hold.show', $tahan))->json();
        $this->assertSame('Budi Santoso', $hasil['customer']['name']);
        $this->assertFalse($hasil['customer']['is_default']);
    }

    public function test_produk_yang_dihapus_setelah_ditahan_dilaporkan_hilang_saat_dipanggil(): void
    {
        $this->actingAs($this->kasir)->postJson(route('pos.hold.store'), $this->payload());
        $tahan = HeldSale::firstOrFail();

        $this->nasi->delete();

        $hasil = $this->getJson(route('pos.hold.show', $tahan))->assertOk()->json();

        $this->assertCount(1, $hasil['items']);
        $this->assertSame(1, $hasil['produk_hilang']);
        $this->assertSame($this->ayam->id, $hasil['items'][0]['product_id']);
    }

    public function test_kasir_tidak_dapat_mengakses_atau_menghapus_tahan_milik_kasir_lain(): void
    {
        $this->actingAs($this->kasir)->postJson(route('pos.hold.store'), $this->payload());
        $tahan = HeldSale::firstOrFail();

        $kasirLain = $this->buatPengguna(RoleName::Kasir);
        $this->actingAs($kasirLain);

        $this->getJson(route('pos.hold.show', $tahan))->assertForbidden();
        $this->deleteJson(route('pos.hold.destroy', $tahan))->assertForbidden();
        $this->assertDatabaseCount('held_sales', 1);

        $this->assertEmpty($this->getJson(route('pos.hold.index'))->json(), 'Daftar tahan kasir lain harus kosong.');
    }

    public function test_hapus_tahan_milik_sendiri(): void
    {
        $this->actingAs($this->kasir)->postJson(route('pos.hold.store'), $this->payload());
        $tahan = HeldSale::firstOrFail();

        $this->deleteJson(route('pos.hold.destroy', $tahan))->assertOk()->assertJson(['deleted' => true]);

        $this->assertDatabaseCount('held_sales', 0);
    }

    public function test_batas_maksimal_transaksi_ditahan(): void
    {
        $this->actingAs($this->kasir);

        for ($i = 0; $i < HeldSale::MAKS_PER_KASIR; $i++) {
            $this->postJson(route('pos.hold.store'), $this->payload(['label' => "Meja {$i}"]))->assertCreated();
        }

        $this->postJson(route('pos.hold.store'), $this->payload(['label' => 'Meja ekstra']))
            ->assertStatus(422);

        $this->assertDatabaseCount('held_sales', HeldSale::MAKS_PER_KASIR);
    }

    public function test_validasi_keranjang_kosong_dan_produk_tidak_valid(): void
    {
        $this->actingAs($this->kasir);

        $this->postJson(route('pos.hold.store'), $this->payload(['items' => []]))->assertStatus(422);
        $this->postJson(route('pos.hold.store'), $this->payload(['items' => [['product_id' => 99999, 'quantity' => '1']]]))->assertStatus(422);

        $this->assertDatabaseCount('held_sales', 0);
    }

    public function test_kasir_lain_memiliki_daftar_tahan_terpisah(): void
    {
        $kasirLain = $this->buatPengguna(RoleName::Kasir);

        $this->actingAs($this->kasir)->postJson(route('pos.hold.store'), $this->payload(['label' => 'Punya A']));
        $this->actingAs($kasirLain)->postJson(route('pos.hold.store'), $this->payload(['label' => 'Punya B']));

        $daftarA = $this->actingAs($this->kasir)->getJson(route('pos.hold.index'))->json();
        $daftarB = $this->actingAs($kasirLain)->getJson(route('pos.hold.index'))->json();

        $this->assertCount(1, $daftarA);
        $this->assertCount(1, $daftarB);
        $this->assertSame('Punya A', $daftarA[0]['label']);
        $this->assertSame('Punya B', $daftarB[0]['label']);
    }
}
