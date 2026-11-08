<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\StatusAbsensi;
use App\Models\Cuti;
use App\Models\JenisCuti;
use App\Models\TahapPersetujuan;
use App\Models\User;
use App\Services\Sdm\CutiService;
use Database\Seeders\KepegawaianSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class JenisCutiTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $pemilik;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->pemilik = $this->buatPengguna(RoleName::SuperAdmin);
    }

    private function payload(array $ubah = []): array
    {
        return array_merge([
            'nama' => 'Cuti Tahunan',
            'kuota_tahunan' => '12',
            'status_absensi' => StatusAbsensi::Cuti->value,
            'is_active' => '1',
            'tahap' => [['tipe' => 'peran', 'peran' => RoleName::Admin->value]],
        ], $ubah);
    }

    /** @return array<int, array{0:int, 1:?string, 2:?int}> [urutan, peran, approver_id] */
    private function alur(JenisCuti $jenis): array
    {
        return $jenis->tahap()->get()->map(fn (TahapPersetujuan $t) => [$t->urutan, $t->peran?->value, $t->approver_id])->all();
    }

    public function test_super_admin_menyimpan_jenis_cuti_dengan_tahap_peran_dan_orang(): void
    {
        $penyelia = $this->buatPengguna(RoleName::Kasir);

        $this->actingAs($this->pemilik)
            ->post(route('leave-types.store'), $this->payload(['tahap' => [
                ['tipe' => 'peran', 'peran' => RoleName::Admin->value],
                // Peran yang tertinggal di baris "orang" diabaikan.
                ['tipe' => 'orang', 'peran' => RoleName::Kasir->value, 'approver_id' => (string) $penyelia->id],
            ]]))
            ->assertRedirect(route('leave-types.index'))
            ->assertSessionHas('success', 'Jenis cuti Cuti Tahunan tersimpan.');

        $jenis = JenisCuti::sole();
        $this->assertSame(12, $jenis->kuota_tahunan);
        $this->assertSame(StatusAbsensi::Cuti, $jenis->status_absensi);
        $this->assertTrue($jenis->is_active);
        $this->assertSame([[1, 'Admin', null], [2, null, $penyelia->id]], $this->alur($jenis));
    }

    public function test_tanpa_kuota_dan_tanpa_tahap_tersimpan_sebagai_langsung_disetujui(): void
    {
        $this->actingAs($this->pemilik)
            ->post(route('leave-types.store'), $this->payload(['nama' => 'Sakit', 'kuota_tahunan' => '', 'status_absensi' => 'sakit', 'tahap' => []]))
            ->assertSessionHasNoErrors();

        $jenis = JenisCuti::sole();
        $this->assertNull($jenis->kuota_tahunan);
        $this->assertSame([], $this->alur($jenis));
    }

    public function test_mengubah_alur_tidak_mengubah_pengajuan_yang_sedang_berjalan(): void
    {
        $jenis = JenisCuti::factory()->alur([RoleName::Admin])->create(['nama' => 'Cuti Tahunan']);
        $cuti = app(CutiService::class)->ajukan($this->buatPengguna(RoleName::Kasir), [
            'jenis_cuti_id' => $jenis->id, 'tanggal_mulai' => '2026-11-10', 'tanggal_selesai' => '2026-11-10', 'alasan' => 'Keluarga',
        ]);

        $this->actingAs($this->pemilik)
            ->put(route('leave-types.update', $jenis), $this->payload(['tahap' => [
                ['tipe' => 'peran', 'peran' => RoleName::Admin->value],
                ['tipe' => 'peran', 'peran' => RoleName::SuperAdmin->value],
            ]]))
            ->assertRedirect(route('leave-types.index'));

        $this->assertSame([[1, 'Admin', null], [2, 'Super Admin', null]], $this->alur($jenis));
        $this->assertSame([[1, 'Admin']], $cuti->persetujuan()->get()->map(fn ($p) => [$p->urutan, $p->peran->value])->all());
    }

    public function test_jenis_cuti_dinonaktifkan_bila_kotak_aktif_tidak_dicentang(): void
    {
        $jenis = JenisCuti::factory()->create(['nama' => 'Izin']);

        $this->actingAs($this->pemilik)
            ->put(route('leave-types.update', $jenis), array_diff_key($this->payload(['nama' => 'Izin']), ['is_active' => true]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($jenis->refresh()->is_active);
    }

    public function test_tahap_orang_tanpa_approver_ditolak(): void
    {
        $this->actingAs($this->pemilik)
            ->post(route('leave-types.store'), $this->payload(['tahap' => [['tipe' => 'orang', 'approver_id' => '']]]))
            ->assertSessionHasErrors(['tahap.0.approver_id' => 'Pilih orang yang menyetujui tahap ini.']);

        $this->assertSame(0, JenisCuti::count());
    }

    public function test_approver_yang_sudah_nonaktif_ditolak(): void
    {
        $nonaktif = $this->buatPengguna(RoleName::Admin, ['is_active' => false]);

        $this->actingAs($this->pemilik)
            ->post(route('leave-types.store'), $this->payload(['tahap' => [['tipe' => 'orang', 'approver_id' => (string) $nonaktif->id]]]))
            ->assertSessionHasErrors(['tahap.0.approver_id' => 'Approver harus pengguna yang masih aktif.']);
    }

    public function test_orang_yang_sama_di_dua_tahap_ditolak(): void
    {
        $penyelia = $this->buatPengguna(RoleName::Admin);
        $tahap = ['tipe' => 'orang', 'approver_id' => (string) $penyelia->id];

        $this->actingAs($this->pemilik)
            ->post(route('leave-types.store'), $this->payload(['tahap' => [$tahap, $tahap]]))
            ->assertSessionHasErrors(['tahap.0.approver_id' => 'Orang yang sama dipakai di lebih dari satu tahap.']);
    }

    public function test_status_absensi_selain_cuti_izin_sakit_ditolak(): void
    {
        $this->actingAs($this->pemilik)
            ->post(route('leave-types.store'), $this->payload(['status_absensi' => StatusAbsensi::Hadir->value]))
            ->assertSessionHasErrors('status_absensi');
    }

    public function test_jenis_cuti_yang_sudah_dipakai_tidak_dapat_dihapus(): void
    {
        $jenis = JenisCuti::factory()->create(['nama' => 'Cuti Tahunan']);
        Cuti::factory()->for($jenis)->create();

        $this->actingAs($this->pemilik)
            ->delete(route('leave-types.destroy', $jenis))
            ->assertSessionHas('error', 'Jenis cuti Cuti Tahunan sudah dipakai 1 pengajuan. Nonaktifkan saja bila tidak dipakai lagi.');

        $this->assertModelExists($jenis);
    }

    public function test_jenis_cuti_yang_belum_dipakai_dapat_dihapus_beserta_alurnya(): void
    {
        $jenis = JenisCuti::factory()->alur([RoleName::Admin])->create();

        $this->actingAs($this->pemilik)->delete(route('leave-types.destroy', $jenis))->assertSessionHas('success');

        $this->assertModelMissing($jenis);
        $this->assertSame(0, TahapPersetujuan::count());
    }

    public function test_daftar_dan_form_menampilkan_alur_persetujuan(): void
    {
        $penyelia = $this->buatPengguna(RoleName::Admin, ['name' => 'Bu Rina']);
        $jenis = JenisCuti::factory()->alur([RoleName::Admin, $penyelia])->create(['nama' => 'Cuti Tahunan']);

        $this->actingAs($this->pemilik)
            ->get(route('leave-types.index'))
            ->assertOk()
            ->assertSeeTextInOrder(['Cuti Tahunan', '1. Peran Admin', '2. Bu Rina']);

        $this->actingAs($this->pemilik)->get(route('leave-types.create'))->assertOk();
        $this->actingAs($this->pemilik)->get(route('leave-types.edit', $jenis))->assertOk()->assertSee('Bu Rina');
    }

    public function test_seeder_membuat_jenis_cuti_bawaan_tanpa_menimpa_alur_yang_sudah_diubah(): void
    {
        $this->seed(KepegawaianSeeder::class);

        $tahunan = JenisCuti::where('nama', 'Cuti Tahunan')->sole();
        $this->assertSame(12, $tahunan->kuota_tahunan);
        $this->assertSame([[1, 'Admin', null], [2, 'Super Admin', null]], $this->alur($tahunan));
        $this->assertSame(['Cuti Tahunan', 'Izin', 'Sakit'], JenisCuti::orderBy('nama')->pluck('nama')->all());

        $tahunan->tahap()->where('urutan', 2)->delete();
        $this->seed(KepegawaianSeeder::class);

        $this->assertSame(3, JenisCuti::count());
        $this->assertSame([[1, 'Admin', null]], $this->alur($tahunan));
    }

    public function test_admin_tidak_dapat_mengatur_jenis_cuti(): void
    {
        $admin = $this->buatPengguna(RoleName::Admin);

        $this->actingAs($admin)->get(route('leave-types.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('leave-types.store'), $this->payload())->assertForbidden();

        $this->assertSame(0, JenisCuti::count());
    }
}
