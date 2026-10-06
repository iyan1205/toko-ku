<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class AutentikasiTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
    }

    public function test_halaman_login_tampil_dalam_bahasa_indonesia(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Masuk ke akun Anda')
            ->assertSee('Kata sandi');
    }

    public function test_pengguna_aktif_dapat_login_dan_diarahkan_ke_dashboard(): void
    {
        $user = $this->buatPengguna(RoleName::Kasir, ['email' => 'kasir@toko.test']);

        $this->post('/login', ['email' => 'kasir@toko.test', 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_gagal_dengan_kata_sandi_salah(): void
    {
        $this->buatPengguna(RoleName::Kasir, ['email' => 'kasir@toko.test']);

        $this->from('/login')
            ->post('/login', ['email' => 'kasir@toko.test', 'password' => 'salah'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'Email atau kata sandi salah.']);

        $this->assertGuest();
    }

    public function test_pengguna_nonaktif_tidak_dapat_login(): void
    {
        $this->buatPengguna(RoleName::Kasir, ['email' => 'off@toko.test', 'is_active' => false]);

        $this->from('/login')
            ->post('/login', ['email' => 'off@toko.test', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Akun Anda dinonaktifkan. Hubungi administrator.']);

        $this->assertGuest();
    }

    public function test_pengguna_yang_sudah_dihapus_tidak_dapat_login(): void
    {
        $user = $this->buatPengguna(RoleName::Kasir, ['email' => 'hapus@toko.test']);
        $user->delete();

        $this->post('/login', ['email' => 'hapus@toko.test', 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_sesi_berakhir_bila_akun_dinonaktifkan_saat_sedang_login(): void
    {
        $user = $this->buatPengguna(RoleName::Kasir);
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->update(['is_active' => false]);

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_pengguna_dapat_logout(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Kasir))
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_tamu_diarahkan_ke_login_saat_membuka_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_registrasi_publik_tidak_tersedia(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
