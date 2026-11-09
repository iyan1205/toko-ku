<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Exceptions\AturanBisnisException;
use App\Models\User;
use App\Services\Access\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class PenggunaTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->super = $this->buatPengguna(RoleName::SuperAdmin);
    }

    private function dataValid(array $ubah = []): array
    {
        return array_merge([
            'name' => 'Budi Santoso',
            'email' => 'budi@toko.test',
            'phone' => '081234567890',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'role' => RoleName::Kasir->value,
            'is_active' => '1',
        ], $ubah);
    }

    public function test_super_admin_dapat_menambah_pengguna_beserta_perannya(): void
    {
        $this->actingAs($this->super)
            ->post(route('users.store'), $this->dataValid())
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('success');

        $user = User::where('email', 'budi@toko.test')->firstOrFail();

        $this->assertTrue($user->hasRole('Kasir'));
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('rahasia123', $user->password));
        $this->assertNotSame('rahasia123', $user->password);
    }

    public function test_validasi_menolak_data_tidak_valid_dengan_pesan_indonesia(): void
    {
        $this->buatPengguna(RoleName::Kasir, ['email' => 'budi@toko.test']);

        $this->actingAs($this->super)
            ->post(route('users.store'), $this->dataValid(['password' => 'pendek', 'password_confirmation' => 'beda', 'role' => 'Hacker']))
            ->assertSessionHasErrors(['email', 'password', 'role']);

        $this->assertSame('Email ini sudah terdaftar.', session('errors')->first('email'));
    }

    public function test_admin_tidak_dapat_menambah_pengguna(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Admin))
            ->post(route('users.store'), $this->dataValid())
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'budi@toko.test']);
    }

    public function test_pengguna_dapat_diubah_tanpa_mengganti_kata_sandi(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir, ['email' => 'lama@toko.test']);
        $hashLama = $kasir->password;

        $this->actingAs($this->super)
            ->put(route('users.update', $kasir), $this->dataValid([
                'email' => 'baru@toko.test', 'password' => '', 'password_confirmation' => '',
                'role' => RoleName::Admin->value,
            ]))
            ->assertRedirect(route('users.index'));

        $kasir->refresh();
        $this->assertSame('baru@toko.test', $kasir->email);
        $this->assertTrue($kasir->hasRole('Admin'));
        $this->assertFalse($kasir->hasRole('Kasir'));
        $this->assertSame($hashLama, $kasir->password);
    }

    public function test_pengguna_dapat_dinonaktifkan_dan_diaktifkan_kembali(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);

        $this->actingAs($this->super)->patch(route('users.toggle', $kasir));
        $this->assertFalse($kasir->fresh()->is_active);

        $this->patch(route('users.toggle', $kasir));
        $this->assertTrue($kasir->fresh()->is_active);
    }

    public function test_penghapusan_bersifat_soft_delete(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);

        $this->actingAs($this->super)
            ->delete(route('users.destroy', $kasir))
            ->assertRedirect(route('users.index'));

        $this->assertSoftDeleted('users', ['id' => $kasir->id]);
    }

    public function test_tidak_dapat_menghapus_atau_menonaktifkan_akun_sendiri(): void
    {
        $this->buatPengguna(RoleName::SuperAdmin); // Super Admin kedua, agar yang tertolak murni aturan "akun sendiri"

        $this->actingAs($this->super)->from(route('users.index'))
            ->delete(route('users.destroy', $this->super))
            ->assertSessionHas('error', 'Anda tidak dapat menghapus akun Anda sendiri.');

        $this->patch(route('users.toggle', $this->super))
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted('users', ['id' => $this->super->id]);
        $this->assertTrue($this->super->fresh()->is_active);
    }

    public function test_tidak_dapat_mengubah_peran_sendiri(): void
    {
        $this->buatPengguna(RoleName::SuperAdmin);

        $this->actingAs($this->super)->from(route('users.edit', $this->super))
            ->put(route('users.update', $this->super), $this->dataValid([
                'name' => $this->super->name, 'email' => $this->super->email,
                'password' => '', 'password_confirmation' => '',
                'role' => RoleName::Kasir->value,
            ]))
            ->assertSessionHas('error');

        $this->assertTrue($this->super->fresh()->hasRole('Super Admin'));
    }

    public function test_super_admin_aktif_terakhir_tidak_boleh_dihapus_atau_dinonaktifkan(): void
    {
        $aktor = $this->buatPengguna(RoleName::Admin); // bukan Super Admin, memanggil service langsung
        $service = app(UserService::class);

        try {
            $service->hapus($this->super, $aktor);
            $this->fail('Seharusnya melempar AturanBisnisException.');
        } catch (AturanBisnisException $e) {
            $this->assertSame('Minimal harus ada satu Super Admin aktif.', $e->getMessage());
        }

        $this->expectException(AturanBisnisException::class);
        $service->ubahStatus($this->super, $aktor);
    }

    public function test_daftar_pengguna_dapat_dicari_dan_difilter(): void
    {
        $this->buatPengguna(RoleName::Kasir, ['name' => 'Siti Aminah']);
        $this->buatPengguna(RoleName::Kasir, ['name' => 'Joko Widodo', 'is_active' => false]);

        $this->actingAs($this->super)
            ->get(route('users.index', ['cari' => 'Siti']))
            ->assertSee('Siti Aminah')
            ->assertDontSee('Joko Widodo');

        $this->get(route('users.index', ['status' => 'nonaktif']))
            ->assertSee('Joko Widodo')
            ->assertDontSee('Siti Aminah');
    }

    public function test_reset_kata_sandi_mengganti_sandi_dan_tercatat_tanpa_isi_sandinya(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);

        $this->actingAs($this->super)
            ->put(route('users.password', $kasir), ['password' => 'baru12345', 'password_confirmation' => 'baru12345'])
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('baru12345', $kasir->fresh()->password));
        $log = Activity::query()->where('event', 'password_reset')->sole();
        $this->assertSame("Kata sandi {$kasir->name} direset", $log->description);
        $this->assertStringNotContainsString('baru12345', json_encode($log->properties));
    }

    public function test_reset_kata_sandi_menolak_sandi_lemah_atau_tidak_cocok(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);

        $this->actingAs($this->super)
            ->put(route('users.password', $kasir), ['password' => 'baru12345', 'password_confirmation' => 'lain12345'])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $kasir->fresh()->password));
    }

    public function test_selain_super_admin_tidak_bisa_mengubah_akun_super_admin(): void
    {
        $pemilik = $this->buatPengguna(RoleName::Pemilik);

        $this->actingAs($pemilik)->get(route('users.edit', $this->super))->assertForbidden();
        $this->actingAs($pemilik)
            ->put(route('users.password', $this->super), ['password' => 'baru12345', 'password_confirmation' => 'baru12345'])
            ->assertForbidden();
        $this->assertTrue(Hash::check('password', $this->super->fresh()->password));
    }

    public function test_selain_super_admin_tidak_bisa_memberi_peran_super_admin(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Pemilik))
            ->post(route('users.store'), $this->dataValid(['role' => RoleName::SuperAdmin->value]))
            ->assertSessionHasErrors(['role' => 'Hanya Super Admin yang dapat memberikan peran Super Admin.']);

        $this->assertDatabaseMissing('users', ['email' => 'budi@toko.test']);
    }

    public function test_peran_buatan_sendiri_bisa_diberikan_ke_pengguna(): void
    {
        Role::create(['name' => 'Supervisor', 'guard_name' => 'web']);

        $this->actingAs($this->super)
            ->post(route('users.store'), $this->dataValid(['role' => 'Supervisor']))
            ->assertSessionHasNoErrors();

        $this->assertTrue(User::where('email', 'budi@toko.test')->sole()->hasRole('Supervisor'));
    }

    public function test_pergantian_peran_tercatat_di_log_aktivitas(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);

        $this->actingAs($this->super)->put(route('users.update', $kasir), [
            'name' => $kasir->name, 'email' => $kasir->email, 'role' => RoleName::StafGudang->value, 'is_active' => '1',
        ]);

        $log = Activity::query()->where('event', 'role_changed')->sole();
        $this->assertSame(['peran' => 'Kasir'], $log->properties['old']);
        $this->assertSame(['peran' => 'Staf Gudang'], $log->properties['attributes']);
    }
}
