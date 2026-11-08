<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Jadwal;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class ShiftTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
    }

    public function test_admin_menambah_shift(): void
    {
        $this->actingAs($this->admin)
            ->from(route('shifts.index'))
            ->post(route('shifts.store'), ['nama' => 'Malam', 'jam_masuk' => '22:00', 'jam_pulang' => '06:00', 'is_active' => '1'])
            ->assertRedirect(route('shifts.index'))
            ->assertSessionHas('success', 'Shift Malam berhasil ditambahkan.');

        $shift = Shift::sole();
        $this->assertSame('Malam', $shift->nama);
        $this->assertSame('22:00', $shift->jam_masuk);
        $this->assertSame('06:00', $shift->jam_pulang);
        $this->assertTrue($shift->is_active);
        $this->assertSame('22:00–06:00 (+1)', $shift->rentang());
    }

    public function test_shift_dinonaktifkan_bila_kotak_aktif_tidak_dicentang(): void
    {
        $shift = Shift::factory()->create(['nama' => 'Pagi']);

        $this->actingAs($this->admin)
            ->put(route('shifts.update', $shift), ['nama' => 'Pagi', 'jam_masuk' => '08:00', 'jam_pulang' => '16:00'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($shift->refresh()->is_active);
    }

    public function test_jam_pulang_sama_dengan_jam_masuk_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->post(route('shifts.store'), ['nama' => 'Aneh', 'jam_masuk' => '08:00', 'jam_pulang' => '08:00'])
            ->assertSessionHasErrors(['jam_pulang' => 'Jam pulang tidak boleh sama dengan jam masuk.']);

        $this->assertSame(0, Shift::count());
    }

    public function test_shift_yang_dipakai_jadwal_tidak_dapat_dihapus(): void
    {
        $shift = Shift::factory()->create(['nama' => 'Pagi']);
        Jadwal::factory()->for($shift)->create();

        $this->actingAs($this->admin)
            ->delete(route('shifts.destroy', $shift))
            ->assertSessionHas('error', 'Shift Pagi masih dipakai di 1 jadwal. Nonaktifkan saja bila tidak dipakai lagi.');

        $this->assertModelExists($shift);
    }

    public function test_shift_tanpa_jadwal_dapat_dihapus(): void
    {
        $shift = Shift::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('shifts.destroy', $shift))
            ->assertSessionHas('success');

        $this->assertModelMissing($shift);
    }

    public function test_halaman_shift_menampilkan_daftar_untuk_admin(): void
    {
        Shift::factory()->jam('14:00', '22:00')->create(['nama' => 'Siang']);

        $this->actingAs($this->admin)
            ->get(route('shifts.index'))
            ->assertOk()
            ->assertSeeText(['Siang', '14:00–22:00']);
    }

    public function test_kasir_tidak_dapat_mengelola_shift(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);

        $this->actingAs($kasir)->get(route('shifts.index'))->assertForbidden();
        $this->actingAs($kasir)
            ->post(route('shifts.store'), ['nama' => 'Pagi', 'jam_masuk' => '08:00', 'jam_pulang' => '16:00'])
            ->assertForbidden();

        $this->assertSame(0, Shift::count());
    }
}
