<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Branch;
use App\Models\User;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class CabangTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $pemilik;

    private Branch $utama;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->pemilik = $this->buatPengguna(RoleName::Pemilik);
        $this->utama = Branch::query()->where('is_default', true)->sole();
    }

    private function payload(array $ubah = []): array
    {
        return array_merge([
            'code' => 'jbg',
            'name' => 'Cabang Jabong',
            'address' => 'Jl. Jabong No. 1',
            'phone' => '0812-1111-2222',
            'is_active' => '1',
            'is_default' => '0',
        ], $ubah);
    }

    private function cabang(array $atribut = []): Branch
    {
        return Branch::query()->create(array_merge(['code' => 'CB'.Branch::count(), 'name' => 'Cabang '.Branch::count(), 'is_active' => true], $atribut));
    }

    public function test_aplikasi_langsung_punya_cabang_utama(): void
    {
        $this->assertSame('Cabang Utama', $this->utama->name);
        $this->assertTrue($this->utama->is_active);
    }

    public function test_menambah_cabang_dengan_kode_huruf_besar(): void
    {
        $this->actingAs($this->pemilik)
            ->post(route('settings.branches.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.branches.index'));

        $baru = Branch::query()->where('name', 'Cabang Jabong')->sole();
        $this->assertSame('JBG', $baru->code);
        $this->assertFalse($baru->is_default);
    }

    public function test_kode_cabang_yang_sudah_dihapus_tidak_bisa_dipakai_lagi(): void
    {
        $this->cabang(['code' => 'JBG'])->delete();

        $this->actingAs($this->pemilik)
            ->post(route('settings.branches.store'), $this->payload())
            ->assertSessionHasErrors(['code' => 'Kode cabang sudah dipakai (termasuk oleh cabang yang sudah dihapus).']);
    }

    public function test_menjadikan_cabang_lain_utama_mencabut_status_utama_sebelumnya(): void
    {
        $jabong = $this->cabang(['is_active' => false]);

        $this->actingAs($this->pemilik)
            ->put(route('settings.branches.update', $jabong), $this->payload(['code' => $jabong->code, 'is_active' => '0', 'is_default' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertTrue($jabong->fresh()->is_default);
        $this->assertTrue($jabong->fresh()->is_active, 'Cabang utama selalu aktif.');
        $this->assertFalse($this->utama->fresh()->is_default);
        $this->assertSame(1, Branch::query()->where('is_default', true)->count());
    }

    public function test_cabang_utama_tidak_bisa_dinonaktifkan(): void
    {
        $this->actingAs($this->pemilik)
            ->put(route('settings.branches.update', $this->utama), $this->payload(['code' => 'UTAMA', 'name' => 'Pusat', 'is_active' => '0']))
            ->assertSessionHas('error', 'Cabang utama tidak bisa dinonaktifkan. Jadikan cabang lain sebagai cabang utama terlebih dahulu.');

        $this->assertTrue($this->utama->fresh()->is_active);
        $this->assertSame('Cabang Utama', $this->utama->fresh()->name, 'Tidak ada perubahan yang tersimpan.');
    }

    public function test_cabang_utama_tidak_bisa_dihapus(): void
    {
        $this->actingAs($this->pemilik)
            ->delete(route('settings.branches.destroy', $this->utama))
            ->assertSessionHas('error', 'Cabang utama tidak bisa dihapus. Jadikan cabang lain sebagai cabang utama terlebih dahulu.');

        $this->assertNotSoftDeleted($this->utama);
    }

    public function test_cabang_biasa_dihapus_secara_lunak(): void
    {
        $jabong = $this->cabang();

        $this->actingAs($this->pemilik)
            ->delete(route('settings.branches.destroy', $jabong))
            ->assertRedirect(route('settings.branches.index'));

        $this->assertSoftDeleted($jabong);
    }

    public function test_admin_tanpa_izin_tidak_bisa_mengelola_cabang(): void
    {
        $admin = $this->buatPengguna(RoleName::Admin);

        $this->actingAs($admin)->get(route('settings.branches.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('settings.branches.store'), $this->payload())->assertForbidden();

        $this->assertSame(1, Branch::count());
    }

    // ---------------- Cabang aktif di topbar ----------------

    public function test_tanpa_pilihan_cabang_aktif_adalah_cabang_utama(): void
    {
        $this->cabang(['name' => 'Cabang Jabong']);

        $this->actingAs($this->buatPengguna(RoleName::Kasir))
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Cabang aktif: Cabang Utama')
            ->assertSee('Cabang Jabong');
    }

    public function test_semua_pengguna_bisa_memilih_cabang_aktif(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);
        $jabong = $this->cabang(['name' => 'Cabang Jabong']);

        $this->actingAs($kasir)
            ->from(route('profile.edit'))
            ->put(route('current-branch.update'), ['branch_id' => $jabong->id])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas(CurrentBranch::SESSION_KEY, $jabong->id);

        $this->actingAs($kasir)->get(route('profile.edit'))->assertSee('Cabang aktif: Cabang Jabong');
    }

    public function test_cabang_nonaktif_tidak_bisa_dipilih(): void
    {
        $tutup = $this->cabang(['is_active' => false]);

        $this->actingAs($this->pemilik)
            ->put(route('current-branch.update'), ['branch_id' => $tutup->id])
            ->assertSessionHasErrors('branch_id')
            ->assertSessionMissing(CurrentBranch::SESSION_KEY);
    }

    public function test_cabang_pilihan_yang_dinonaktifkan_kembali_ke_cabang_utama(): void
    {
        $jabong = $this->cabang();
        $this->actingAs($this->pemilik)->put(route('current-branch.update'), ['branch_id' => $jabong->id]);

        $jabong->update(['is_active' => false]);

        $this->actingAs($this->pemilik)->get(route('profile.edit'))->assertSee('Cabang aktif: Cabang Utama');
    }
}
