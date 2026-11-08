<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\StatusCuti;
use App\Models\Cuti;
use App\Models\Jadwal;
use App\Models\JenisCuti;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class JadwalTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->travelTo('2026-11-11 09:00:00'); // Rabu; minggunya 09-11-2026 s/d 15-11-2026.
        $this->admin = $this->buatPengguna(RoleName::Admin, ['name' => 'Admin Toko']);
        $this->kasir = $this->buatPengguna(RoleName::Kasir, ['name' => 'Kasir Satu']);
    }

    /** @return array<int, array{0:string, 1:?int}> [tanggal, shift_id] urut tanggal */
    private function jadwalKasir(): array
    {
        return Jadwal::query()->where('user_id', $this->kasir->id)->orderBy('tanggal')->get()
            ->map(fn (Jadwal $j) => [$j->tanggal->toDateString(), $j->shift_id])->all();
    }

    public function test_admin_menyimpan_jadwal_seminggu_dengan_shift_libur_dan_sel_kosong(): void
    {
        $pagi = Shift::factory()->create();
        Jadwal::factory()->for($this->kasir)->for($pagi)->create(['tanggal' => '2026-11-10']);

        $this->actingAs($this->admin)
            ->put(route('schedules.update'), [
                'minggu' => '2026-11-11',
                'jadwal' => [
                    $this->kasir->id => ['2026-11-09' => (string) $pagi->id, '2026-11-10' => '', '2026-11-11' => 'libur'],
                ],
            ])
            ->assertRedirect(route('schedules.index', ['minggu' => '2026-11-09']))
            ->assertSessionHas('success', 'Jadwal 09-11-2026 s/d 15-11-2026 tersimpan.');

        $this->assertSame([['2026-11-09', $pagi->id], ['2026-11-11', null]], $this->jadwalKasir());
    }

    public function test_menyimpan_ulang_mengganti_shift_tanpa_membuat_jadwal_ganda(): void
    {
        $pagi = Shift::factory()->create();
        $siang = Shift::factory()->create();
        Jadwal::factory()->for($this->kasir)->for($pagi)->create(['tanggal' => '2026-11-09']);

        $this->actingAs($this->admin)->put(route('schedules.update'), [
            'minggu' => '2026-11-09',
            'jadwal' => [$this->kasir->id => ['2026-11-09' => (string) $siang->id]],
        ]);

        $this->assertSame([['2026-11-09', $siang->id]], $this->jadwalKasir());
    }

    /** @return array<string, array{0:bool, 1:string}> */
    public static function isianTidakSah(): array
    {
        return [
            'tanggal di luar minggu yang dibuka' => [false, '2026-11-16'],
            'pegawai nonaktif' => [true, '2026-11-09'],
        ];
    }

    #[DataProvider('isianTidakSah')]
    public function test_jadwal_menolak_isian_di_luar_grid(bool $pegawaiNonaktif, string $tanggal): void
    {
        $pegawai = $pegawaiNonaktif ? $this->buatPengguna(RoleName::Kasir, ['is_active' => false]) : $this->kasir;

        $this->actingAs($this->admin)
            ->put(route('schedules.update'), ['minggu' => '2026-11-09', 'jadwal' => [$pegawai->id => [$tanggal => 'libur']]])
            ->assertSessionHasErrors(['jadwal' => 'Data jadwal tidak valid. Muat ulang halaman lalu coba lagi.']);

        $this->assertSame(0, Jadwal::count());
    }

    public function test_jadwal_menolak_shift_yang_tidak_ada(): void
    {
        $this->actingAs($this->admin)
            ->put(route('schedules.update'), ['minggu' => '2026-11-09', 'jadwal' => [$this->kasir->id => ['2026-11-09' => '999']]])
            ->assertSessionHasErrors(["jadwal.{$this->kasir->id}.2026-11-09" => 'Shift yang dipilih tidak ditemukan. Muat ulang halaman lalu coba lagi.']);

        $this->assertSame(0, Jadwal::count());
    }

    public function test_salin_dari_minggu_lalu_hanya_mengisi_sel_yang_kosong(): void
    {
        $pagi = Shift::factory()->create();
        $siang = Shift::factory()->create();
        Jadwal::factory()->for($this->kasir)->for($pagi)->create(['tanggal' => '2026-11-02']);
        Jadwal::factory()->for($this->kasir)->for($siang)->create(['tanggal' => '2026-11-03']);
        Jadwal::factory()->libur()->for($this->kasir)->create(['tanggal' => '2026-11-09']);

        $this->actingAs($this->admin)
            ->post(route('schedules.copy'), ['minggu' => '2026-11-09'])
            ->assertRedirect(route('schedules.index', ['minggu' => '2026-11-09']))
            ->assertSessionHas('success', '1 jadwal disalin dari minggu lalu. Sel yang sudah terisi tidak diubah.');

        $this->assertSame(
            [['2026-11-02', $pagi->id], ['2026-11-03', $siang->id], ['2026-11-09', null], ['2026-11-10', $siang->id]],
            $this->jadwalKasir(),
        );
    }

    public function test_kasir_melihat_jadwal_semua_pegawai_tetapi_tidak_dapat_mengubah(): void
    {
        $siang = Shift::factory()->jam('14:00', '22:00')->create(['nama' => 'Siang']);
        Jadwal::factory()->for($this->admin)->for($siang)->create(['tanggal' => '2026-11-11']);

        $this->actingAs($this->kasir)
            ->get(route('schedules.index'))
            ->assertOk()
            ->assertViewHas('bisaUbah', false)
            ->assertSeeText(['Admin Toko', 'Siang', '14:00–22:00'])
            ->assertDontSee('name="jadwal[', false);

        $this->actingAs($this->kasir)
            ->put(route('schedules.update'), ['minggu' => '2026-11-09', 'jadwal' => [$this->kasir->id => ['2026-11-09' => 'libur']]])
            ->assertForbidden();

        $this->assertSame([], $this->jadwalKasir());
    }

    public function test_grid_menandai_cuti_yang_sudah_disetujui(): void
    {
        $jenis = JenisCuti::factory()->create(['nama' => 'Cuti Tahunan']);
        Cuti::factory()->for($this->kasir)->for($jenis)->tanggal('2026-11-10', '2026-11-11', 2)->create();
        Cuti::factory()->for($this->kasir)->for($jenis)->tanggal('2026-11-13', '2026-11-13', 1)->create(['status' => StatusCuti::Menunggu]);

        $this->actingAs($this->admin)
            ->get(route('schedules.index', ['minggu' => '2026-11-09']))
            ->assertOk()
            ->assertViewHas('cuti', fn ($cuti) => $cuti->all() === [
                "{$this->kasir->id}|2026-11-10" => 'Cuti Tahunan',
                "{$this->kasir->id}|2026-11-11" => 'Cuti Tahunan',
            ]);
    }
}
