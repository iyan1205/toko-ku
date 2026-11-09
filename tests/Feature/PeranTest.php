<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Cuti;
use App\Models\JenisCuti;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class PeranTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->super = $this->buatPengguna(RoleName::SuperAdmin);
    }

    public function test_peran_baru_dibuat_lalu_diarahkan_ke_pengaturan_hak_akses(): void
    {
        $response = $this->actingAs($this->super)
            ->post(route('roles.store'), ['name' => ' Supervisor Shift ', 'description' => 'Mengawasi kasir per shift']);

        $peran = Role::findByName('Supervisor Shift');
        $response->assertRedirect(route('permissions.index', ['peran' => $peran->id]));
        $this->assertSame('Mengawasi kasir per shift', $peran->description);
        $this->assertSame(0, $peran->permissions()->count());
        $this->assertDatabaseHas('activity_log', ['log_name' => 'hak-akses', 'description' => 'Peran "Supervisor Shift" ditambahkan']);
    }

    public function test_nama_peran_harus_unik(): void
    {
        $this->actingAs($this->super)
            ->post(route('roles.store'), ['name' => 'Kasir'])
            ->assertSessionHasErrors(['name' => 'Nama peran ini sudah dipakai.']);
    }

    public function test_ganti_nama_peran_ikut_memperbarui_alur_persetujuan_cuti(): void
    {
        $peran = Role::create(['name' => 'Supervisor', 'guard_name' => 'web']);
        $tahap = JenisCuti::factory()->create()->tahap()->create(['urutan' => 1, 'peran' => 'Supervisor']);
        $persetujuan = Cuti::factory()->create()->persetujuan()->create(['urutan' => 1, 'peran' => 'Supervisor']);

        $this->actingAs($this->super)
            ->put(route('roles.update', $peran), ['name' => 'Kepala Shift'])
            ->assertRedirect(route('roles.index'));

        $this->assertSame('Kepala Shift', $peran->fresh()->name);
        $this->assertSame('Kepala Shift', $tahap->fresh()->peran);
        $this->assertSame('Kepala Shift', $persetujuan->fresh()->peran);
    }

    public function test_nama_peran_bawaan_tidak_bisa_diganti_tetapi_keterangannya_bisa(): void
    {
        $kasir = Role::findByName(RoleName::Kasir->value);

        $this->actingAs($this->super)
            ->put(route('roles.update', $kasir), ['name' => 'Kasir Toko'])
            ->assertSessionHas('error', 'Nama peran bawaan tidak dapat diganti. Keterangan dan hak aksesnya tetap bisa diubah.');
        $this->assertSame('Kasir', $kasir->fresh()->name);

        $this->actingAs($this->super)
            ->put(route('roles.update', $kasir), ['name' => 'Kasir', 'description' => 'Kasir shift pagi dan siang'])
            ->assertRedirect(route('roles.index'));
        $this->assertSame('Kasir shift pagi dan siang', $kasir->fresh()->description);
    }

    public function test_peran_super_admin_tidak_bisa_diubah_bahkan_oleh_super_admin(): void
    {
        $peran = Role::findByName(RoleName::SuperAdmin->value);

        $this->actingAs($this->buatPengguna(RoleName::Pemilik))->get(route('roles.edit', $peran))->assertForbidden();

        $this->actingAs($this->super)
            ->put(route('roles.update', $peran), ['name' => 'Super Admin', 'description' => 'Diubah'])
            ->assertSessionHas('error');
        $this->assertNotSame('Diubah', $peran->fresh()->description);
    }

    public function test_peran_yang_masih_dipakai_pengguna_atau_alur_cuti_tidak_bisa_dihapus(): void
    {
        $dipakaiPengguna = Role::create(['name' => 'Supervisor', 'guard_name' => 'web']);
        User::factory()->create()->assignRole($dipakaiPengguna);
        $dipakaiAlur = Role::create(['name' => 'Kepala Gudang', 'guard_name' => 'web']);
        JenisCuti::factory()->create()->tahap()->create(['urutan' => 1, 'peran' => 'Kepala Gudang']);

        $this->actingAs($this->super)->delete(route('roles.destroy', $dipakaiPengguna))
            ->assertSessionHas('error', 'Peran "Supervisor" masih dipakai 1 pengguna. Pindahkan pengguna tersebut ke peran lain dulu.');
        $this->actingAs($this->super)->delete(route('roles.destroy', $dipakaiAlur))
            ->assertSessionHas('error', 'Peran "Kepala Gudang" masih dipakai di alur persetujuan jenis cuti. Ubah alurnya dulu.');

        $this->assertModelExists($dipakaiPengguna);
        $this->assertModelExists($dipakaiAlur);
    }

    public function test_peran_buatan_sendiri_yang_tidak_dipakai_bisa_dihapus_dan_tercatat(): void
    {
        $peran = Role::create(['name' => 'Magang', 'guard_name' => 'web']);

        $this->actingAs($this->super)
            ->delete(route('roles.destroy', $peran))
            ->assertRedirect(route('roles.index'));

        $this->assertModelMissing($peran);
        $this->assertSame('Peran "Magang" dihapus', Activity::query()->where('log_name', 'hak-akses')->latest('id')->value('description'));
    }

    public function test_peran_bawaan_tidak_bisa_dihapus(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Pemilik))
            ->delete(route('roles.destroy', Role::findByName(RoleName::Kasir->value)))
            ->assertForbidden();
    }

    public function test_hanya_pemegang_izin_peran_yang_boleh_membuka_halaman_peran(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Admin))->get(route('roles.index'))->assertForbidden();
        $this->actingAs($this->buatPengguna(RoleName::Pemilik))->get(route('roles.index'))->assertOk()->assertSee('Staf Gudang');
    }
}
