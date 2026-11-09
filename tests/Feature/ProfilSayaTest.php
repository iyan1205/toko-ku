<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class ProfilSayaTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->kasir = $this->buatPengguna(RoleName::Kasir, ['email' => 'kasir@toko.test']);
    }

    public function test_pengguna_mengubah_nama_dan_nomor_hp_sendiri(): void
    {
        $this->actingAs($this->kasir)->get(route('profile.edit'))->assertOk()->assertSee('Profil Saya');

        $this->actingAs($this->kasir)
            ->put(route('profile.update'), ['name' => 'Rina Kasir', 'email' => 'KASIR@toko.test', 'phone' => '0812 3456 7890'])
            ->assertRedirect(route('profile.edit'));

        $kasir = $this->kasir->fresh();
        $this->assertSame('Rina Kasir', $kasir->name);
        $this->assertSame('kasir@toko.test', $kasir->email);
        $this->assertSame('0812 3456 7890', $kasir->phone);
    }

    public function test_email_yang_dipakai_pengguna_lain_ditolak(): void
    {
        User::factory()->create(['email' => 'lain@toko.test']);

        $this->actingAs($this->kasir)
            ->put(route('profile.update'), ['name' => 'Rina', 'email' => 'lain@toko.test'])
            ->assertSessionHasErrors(['email' => 'Email ini sudah terdaftar.']);
    }

    public function test_ganti_kata_sandi_wajib_dengan_kata_sandi_saat_ini_yang_benar(): void
    {
        $this->actingAs($this->kasir)
            ->put(route('profile.password'), ['current_password' => 'salah', 'password' => 'baru12345', 'password_confirmation' => 'baru12345'])
            ->assertSessionHasErrors(['current_password' => 'Kata sandi saat ini salah.']);
        $this->assertTrue(Hash::check('password', $this->kasir->fresh()->password));

        $this->actingAs($this->kasir)
            ->put(route('profile.password'), ['current_password' => 'password', 'password' => 'baru12345', 'password_confirmation' => 'baru12345'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('baru12345', $this->kasir->fresh()->password));
        $this->assertDatabaseHas('activity_log', ['event' => 'password_changed', 'causer_id' => $this->kasir->id]);
    }
}
