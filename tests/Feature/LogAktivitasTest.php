<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class LogAktivitasTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
    }

    public function test_login_berhasil_dan_gagal_tercatat_tanpa_kata_sandi(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir, ['email' => 'kasir@toko.test']);

        $this->post('/login', ['email' => 'kasir@toko.test', 'password' => 'salah-sekali']);
        $this->post('/login', ['email' => 'kasir@toko.test', 'password' => 'password']);

        $gagal = Activity::query()->where('event', 'login_failed')->sole();
        $this->assertSame('kasir@toko.test', $gagal->properties['email']);
        $this->assertStringNotContainsString('salah-sekali', json_encode($gagal->properties));

        $berhasil = Activity::query()->where('event', 'login')->sole();
        $this->assertTrue($berhasil->causer->is($kasir));
    }

    public function test_perubahan_produk_mencatat_nilai_lama_dan_baru_yang_berubah_saja(): void
    {
        $produk = Product::factory()->create(['name' => 'Ayam Geprek', 'selling_price' => 15000]);

        $this->actingAs($this->buatPengguna(RoleName::Admin));
        $produk->update(['selling_price' => 17000]);

        $log = Activity::query()->where('subject_type', $produk->getMorphClass())->where('event', 'updated')->sole();
        $this->assertSame('Produk "Ayam Geprek" diubah', $log->description);
        $this->assertSame(['selling_price'], array_keys($log->properties['attributes']));
        $this->assertEquals(15000, $log->properties['old']['selling_price']);
        $this->assertEquals(17000, $log->properties['attributes']['selling_price']);
    }

    public function test_ganti_kata_sandi_pengguna_tidak_menyimpan_kata_sandi_di_log(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);
        $jumlah = Activity::count();

        $kasir->update(['password' => 'rahasia-baru-1']);

        $this->assertSame($jumlah, Activity::count());
    }

    public function test_halaman_log_hanya_untuk_pemegang_izin_dan_bisa_difilter(): void
    {
        activity('auth')->log('Login');
        activity('hak-akses')->log('Hak akses peran "Kasir" diubah');

        $this->actingAs($this->buatPengguna(RoleName::Admin))->get(route('activity-log.index'))->assertForbidden();

        $this->actingAs($this->buatPengguna(RoleName::Pemilik))
            ->get(route('activity-log.index', ['kelompok' => 'hak-akses']))
            ->assertOk()
            ->assertSee('Hak akses peran &quot;Kasir&quot; diubah', false)
            ->assertDontSee('<p class="text-slate-900">Login</p>', false);
    }
}
