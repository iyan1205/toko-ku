<?php

namespace Tests\Feature;

use App\Enums\PerangkatAbsensi;
use App\Enums\RoleName;
use App\Enums\StatusAbsensi;
use App\Models\Absensi;
use App\Models\Jadwal;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class AbsensiTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private const UA_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';

    private User $admin;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin, ['name' => 'Admin Toko']);
        $this->kasir = $this->buatPengguna(RoleName::Kasir, ['name' => 'Kasir Satu']);
    }

    private function payloadManual(array $ubah = []): array
    {
        return array_merge([
            'user_id' => $this->kasir->id,
            'tanggal' => '2026-11-05',
            'status' => 'hadir',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
            'keterangan' => null,
        ], $ubah);
    }

    // ---------------- Absen masuk ----------------

    public function test_kasir_absen_masuk_mencatat_jam_perangkat_ip_dan_lokasi(): void
    {
        $this->travelTo('2026-11-07 07:55:10');

        $this->actingAs($this->kasir)
            ->withHeader('User-Agent', self::UA_IPHONE)
            ->post(route('attendances.clock-in'), ['lokasi' => '-6.914744,107.609810'])
            ->assertRedirect(route('attendances.index'))
            ->assertSessionHas('success', 'Absen masuk tercatat pukul 07:55 (Hadir).');

        $absensi = Absensi::sole();
        $this->assertSame($this->kasir->id, $absensi->user_id);
        $this->assertSame('2026-11-07', $absensi->tanggal->toDateString());
        $this->assertSame('2026-11-07 07:55:10', $absensi->clock_in->toDateTimeString());
        $this->assertNull($absensi->clock_out);
        $this->assertSame(StatusAbsensi::Hadir, $absensi->status);
        $this->assertSame(PerangkatAbsensi::Mobile, $absensi->device_type);
        $this->assertSame('127.0.0.1', $absensi->ip_address);
        $this->assertSame('-6.914744,107.609810', $absensi->location);
        $this->assertNull($absensi->recorded_by);
    }

    public function test_absen_masuk_lewat_toleransi_tercatat_terlambat(): void
    {
        config(['pos.absensi.jam_masuk' => '08:00', 'pos.absensi.toleransi_menit' => 15]);
        $kasirLain = $this->buatPengguna(RoleName::Kasir);

        $this->travelTo('2026-11-07 08:15:00');
        $this->actingAs($this->kasir)->post(route('attendances.clock-in'));

        $this->travelTo('2026-11-07 08:16:00');
        $this->actingAs($kasirLain)->post(route('attendances.clock-in'));

        $this->assertSame(StatusAbsensi::Hadir, Absensi::where('user_id', $this->kasir->id)->sole()->status);
        $this->assertSame(StatusAbsensi::Terlambat, Absensi::where('user_id', $kasirLain->id)->sole()->status);
    }

    public function test_absen_masuk_memakai_jam_masuk_shift_di_jadwal_hari_itu(): void
    {
        config(['pos.absensi.jam_masuk' => '08:00', 'pos.absensi.toleransi_menit' => 0]);
        $kasirPagi = $this->buatPengguna(RoleName::Kasir);
        Jadwal::factory()->for($kasirPagi)->for(Shift::factory()->jam('07:00', '15:00'))->create(['tanggal' => '2026-11-07']);
        Jadwal::factory()->for($this->kasir)->for(Shift::factory()->jam('14:00', '22:00'))->create(['tanggal' => '2026-11-07']);

        $this->travelTo('2026-11-07 07:30:00');
        $this->actingAs($kasirPagi)->post(route('attendances.clock-in'));

        $this->travelTo('2026-11-07 13:50:00');
        $this->actingAs($this->kasir)->post(route('attendances.clock-in'));

        $this->assertSame(StatusAbsensi::Terlambat, Absensi::where('user_id', $kasirPagi->id)->sole()->status);
        $this->assertSame(StatusAbsensi::Hadir, Absensi::where('user_id', $this->kasir->id)->sole()->status);
    }

    public function test_absen_masuk_di_hari_libur_tidak_dihitung_terlambat(): void
    {
        config(['pos.absensi.jam_masuk' => '08:00']);
        Jadwal::factory()->libur()->for($this->kasir)->create(['tanggal' => '2026-11-07']);

        $this->travelTo('2026-11-07 10:00:00');
        $this->actingAs($this->kasir)->post(route('attendances.clock-in'));

        $this->assertSame(StatusAbsensi::Hadir, Absensi::sole()->status);
    }

    public function test_halaman_absensi_menampilkan_shift_hari_ini(): void
    {
        $this->travelTo('2026-11-07 07:00:00');
        Jadwal::factory()->for($this->kasir)->for(Shift::factory()->jam('08:00', '16:00')->state(['nama' => 'Pagi']))->create(['tanggal' => '2026-11-07']);

        $this->actingAs($this->kasir)
            ->get(route('attendances.index'))
            ->assertOk()
            ->assertSeeTextInOrder(['Shift hari ini:', 'Pagi', '(08:00–16:00)']);
    }

    public function test_absen_masuk_kedua_kali_di_hari_yang_sama_ditolak(): void
    {
        $this->travelTo('2026-11-07 08:00:00');
        $this->actingAs($this->kasir)->post(route('attendances.clock-in'));

        $this->travelTo('2026-11-07 09:00:00');
        $this->actingAs($this->kasir)
            ->post(route('attendances.clock-in'))
            ->assertSessionHas('error', 'Anda sudah absen masuk hari ini pukul 08:00.');

        $this->assertSame(1, Absensi::count());
    }

    public function test_absen_masuk_ditolak_bila_hari_ini_sudah_dicatat_izin(): void
    {
        $this->travelTo('2026-11-07 08:00:00');
        Absensi::factory()->tanpaJam(StatusAbsensi::Izin)->for($this->kasir)->create(['tanggal' => '2026-11-07']);

        $this->actingAs($this->kasir)
            ->post(route('attendances.clock-in'))
            ->assertSessionHas('error', 'Hari ini Anda sudah tercatat Izin. Hubungi admin bila perlu diubah.');

        $this->assertSame(StatusAbsensi::Izin, Absensi::sole()->status);
    }

    public function test_absen_masuk_menolak_lokasi_yang_bukan_koordinat(): void
    {
        $this->actingAs($this->kasir)
            ->post(route('attendances.clock-in'), ['lokasi' => 'rumah saya'])
            ->assertSessionHasErrors(['lokasi' => 'Lokasi tidak valid. Muat ulang halaman lalu coba lagi.']);

        $this->assertSame(0, Absensi::count());
    }

    // ---------------- Absen pulang ----------------

    public function test_absen_pulang_mencatat_jam_pulang_dan_tidak_bisa_diulang(): void
    {
        $this->travelTo('2026-11-07 08:00:00');
        $this->actingAs($this->kasir)->post(route('attendances.clock-in'));

        $this->travelTo('2026-11-07 17:05:00');
        $this->actingAs($this->kasir)
            ->post(route('attendances.clock-out'))
            ->assertRedirect(route('attendances.index'))
            ->assertSessionHas('success', 'Absen pulang tercatat pukul 17:05. Terima kasih!');

        $absensi = Absensi::sole();
        $this->assertSame('2026-11-07 17:05:00', $absensi->clock_out->toDateTimeString());
        $this->assertSame(545, $absensi->durasiMenit());

        $this->travelTo('2026-11-07 18:00:00');
        $this->actingAs($this->kasir)
            ->post(route('attendances.clock-out'))
            ->assertSessionHas('error', 'Anda sudah absen pulang hari ini pukul 17:05.');

        $this->assertSame('2026-11-07 17:05:00', $absensi->refresh()->clock_out->toDateTimeString());
    }

    public function test_absen_pulang_tanpa_absen_masuk_hari_ini_ditolak(): void
    {
        $this->travelTo('2026-11-07 17:00:00');
        // Catatan kemarin yang lupa ditutup tidak ikut ditutup oleh absen pulang hari ini.
        Absensi::factory()->for($this->kasir)->create(['tanggal' => '2026-11-06', 'clock_in' => '2026-11-06 08:00:00']);

        $this->actingAs($this->kasir)
            ->post(route('attendances.clock-out'))
            ->assertSessionHas('error', 'Anda belum absen masuk hari ini.');

        $this->assertNull(Absensi::sole()->clock_out);
    }

    // ---------------- Daftar ----------------

    public function test_kasir_hanya_melihat_absensinya_sendiri(): void
    {
        $this->travelTo('2026-11-07 12:00:00');
        Absensi::factory()->for($this->kasir)->create();
        Absensi::factory()->for($this->admin)->create();

        $this->actingAs($this->kasir)
            ->get(route('attendances.index', ['pegawai' => $this->admin->id]))
            ->assertOk()
            ->assertSee('Absen Pulang')
            ->assertDontSee('Catat absensi manual')
            ->assertViewHas('absensi', fn (LengthAwarePaginator $p) => $p->pluck('user_id')->all() === [$this->kasir->id]);
    }

    public function test_admin_melihat_absensi_semua_pegawai_dan_rekap_status(): void
    {
        $this->travelTo('2026-11-07 12:00:00');
        Absensi::factory()->for($this->kasir)->create(['status' => StatusAbsensi::Terlambat]);
        Absensi::factory()->tanpaJam(StatusAbsensi::Sakit)->for($this->admin)->create();
        // Di luar periode bawaan (bulan berjalan): tidak ikut tampil maupun direkap.
        Absensi::factory()->for($this->kasir)->create(['tanggal' => '2026-10-31']);

        $this->actingAs($this->admin)
            ->get(route('attendances.index'))
            ->assertOk()
            ->assertSee('Kasir Satu')
            ->assertSee('Catat absensi manual')
            ->assertViewHas('absensi', fn (LengthAwarePaginator $p) => $p->total() === 2)
            ->assertViewHas('rekap', fn ($rekap) => $rekap['terlambat'] === 1 && $rekap['sakit'] === 1 && $rekap['hadir'] === 0);
    }

    public function test_pengguna_tanpa_izin_absensi_tidak_dapat_membuka_atau_absen(): void
    {
        $tanpaPeran = User::factory()->create();

        $this->actingAs($tanpaPeran)->get(route('attendances.index'))->assertForbidden();
        $this->actingAs($tanpaPeran)->post(route('attendances.clock-in'))->assertForbidden();
        $this->assertSame(0, Absensi::count());
    }

    public function test_menu_absensi_tampil_untuk_kasir(): void
    {
        $this->actingAs($this->kasir)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('attendances.index'));
    }

    // ---------------- Catatan manual (admin) ----------------

    public function test_kasir_tidak_dapat_mencatat_atau_mengubah_absensi_manual(): void
    {
        $absensi = Absensi::factory()->for($this->kasir)->create();

        $this->actingAs($this->kasir)->get(route('attendances.create'))->assertForbidden();
        $this->actingAs($this->kasir)->post(route('attendances.store'), $this->payloadManual())->assertForbidden();
        $this->actingAs($this->kasir)->put(route('attendances.update', $absensi), $this->payloadManual())->assertForbidden();
        $this->actingAs($this->kasir)->delete(route('attendances.destroy', $absensi))->assertForbidden();

        $this->assertSame(1, Absensi::count());
    }

    public function test_admin_mencatat_izin_tanpa_jam_meski_jam_dikirim(): void
    {
        $this->travelTo('2026-11-07 09:00:00');

        $this->actingAs($this->admin)
            ->post(route('attendances.store'), $this->payloadManual([
                'tanggal' => '2026-11-09', // izin boleh dicatat di muka
                'status' => 'izin',
                'keterangan' => 'Acara keluarga',
            ]))
            ->assertRedirect(route('attendances.index', ['dari' => '2026-11-09', 'sampai' => '2026-11-09']))
            ->assertSessionHasNoErrors();

        $absensi = Absensi::sole();
        $this->assertSame(StatusAbsensi::Izin, $absensi->status);
        $this->assertSame('2026-11-09', $absensi->tanggal->toDateString());
        $this->assertNull($absensi->clock_in);
        $this->assertNull($absensi->clock_out);
        $this->assertSame('Acara keluarga', $absensi->keterangan);
        $this->assertSame($this->admin->id, $absensi->recorded_by);
    }

    public function test_admin_mengoreksi_jam_pulang_yang_lupa_diabsen(): void
    {
        $this->travelTo('2026-11-07 09:00:00');
        $absensi = Absensi::factory()->for($this->kasir)->create([
            'tanggal' => '2026-11-06',
            'clock_in' => '2026-11-06 08:10:00',
            'device_type' => PerangkatAbsensi::Mobile,
        ]);

        $this->actingAs($this->admin)
            ->put(route('attendances.update', $absensi), $this->payloadManual([
                'tanggal' => '2026-11-06',
                'jam_masuk' => '08:10',
                'jam_pulang' => '17:30',
                'keterangan' => 'Lupa absen pulang',
            ]))
            ->assertSessionHasNoErrors();

        $absensi->refresh();
        $this->assertSame('2026-11-06 08:10:00', $absensi->clock_in->toDateTimeString());
        $this->assertSame('2026-11-06 17:30:00', $absensi->clock_out->toDateTimeString());
        $this->assertSame($this->admin->id, $absensi->recorded_by);
        $this->assertSame(PerangkatAbsensi::Mobile, $absensi->device_type, 'Data perangkat saat absen tetap tersimpan.');
    }

    public function test_catatan_manual_ditolak_bila_pegawai_sudah_punya_absensi_di_tanggal_itu(): void
    {
        $this->travelTo('2026-11-07 09:00:00');
        Absensi::factory()->for($this->kasir)->create(['tanggal' => '2026-11-05', 'clock_in' => '2026-11-05 08:00:00']);

        $this->actingAs($this->admin)
            ->post(route('attendances.store'), $this->payloadManual(['status' => 'sakit']))
            ->assertSessionHasErrors(['user_id' => 'Pegawai ini sudah punya catatan absensi pada tanggal tersebut. Ubah catatan yang ada.']);

        $this->assertSame(1, Absensi::count());
    }

    public function test_catatan_hadir_menolak_jam_pulang_sebelum_jam_masuk(): void
    {
        $this->travelTo('2026-11-07 09:00:00');

        $this->actingAs($this->admin)
            ->post(route('attendances.store'), $this->payloadManual(['jam_masuk' => '17:00', 'jam_pulang' => '08:00']))
            ->assertSessionHasErrors(['jam_pulang' => 'Jam pulang harus setelah jam masuk.']);

        $this->assertSame(0, Absensi::count());
    }

    public function test_catatan_hadir_menolak_tanggal_mendatang_dan_tanpa_jam_masuk(): void
    {
        $this->travelTo('2026-11-07 09:00:00');

        $this->actingAs($this->admin)
            ->post(route('attendances.store'), $this->payloadManual(['tanggal' => '2026-11-08', 'jam_masuk' => '']))
            ->assertSessionHasErrors([
                'tanggal' => 'Kehadiran tidak dapat dicatat untuk tanggal yang belum lewat.',
                'jam_masuk',
            ]);

        $this->assertSame(0, Absensi::count());
    }

    public function test_admin_membuka_form_catat_dan_ubah_absensi(): void
    {
        $absensi = Absensi::factory()->for($this->kasir)->create(['keterangan' => 'Datang lewat pintu belakang']);

        $this->actingAs($this->admin)
            ->get(route('attendances.create'))
            ->assertOk()
            ->assertSee('Catat absensi manual')
            ->assertSee('Kasir Satu');

        $this->actingAs($this->admin)
            ->get(route('attendances.edit', $absensi))
            ->assertOk()
            ->assertSee('Ubah absensi')
            ->assertSee('Datang lewat pintu belakang')
            ->assertSee('value="08:00"', false);
    }

    public function test_admin_dapat_menghapus_absensi(): void
    {
        $absensi = Absensi::factory()->for($this->kasir)->create();

        $this->actingAs($this->admin)
            ->from(route('attendances.index'))
            ->delete(route('attendances.destroy', $absensi))
            ->assertRedirect(route('attendances.index'));

        $this->assertModelMissing($absensi);
    }
}
