<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\StatusAbsensi;
use App\Models\Absensi;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\ImportBatch;
use App\Models\Jadwal;
use App\Models\Shift;
use App\Models\ShiftRotation;
use App\Models\User;
use App\Services\Sdm\WorkCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Fase 13a: shift (toleransi, istirahat), radius & selfie absen, Pengaturan SDM, hari libur, impor fingerprint, rotasi shift. */
class JadwalAbsensiLanjutanTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $kasir;

    private Employee $karyawan;

    private User $hrd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        Storage::fake('local');
        $this->kasir = $this->buatPengguna(RoleName::Kasir, ['name' => 'Rina Kasir']);
        $this->karyawan = Employee::factory()->create(['name' => 'Rina Kasir', 'user_id' => $this->kasir->id, 'attendance_pin' => '1001']);
        $this->hrd = $this->buatPengguna(RoleName::Hrd);
    }

    private function jadwal(Employee $employee, string $tanggal, ?Shift $shift): Jadwal
    {
        return Jadwal::query()->create(['employee_id' => $employee->id, 'tanggal' => $tanggal, 'shift_id' => $shift?->id]);
    }

    private function impor(string $jenis, string $csv): ImportBatch
    {
        $this->actingAs($this->hrd)->post(route("imports.{$jenis}.preview"), ['file' => UploadedFile::fake()->createWithContent('data.csv', $csv)])
            ->assertSessionHasNoErrors()->assertRedirect();
        $batch = ImportBatch::query()->latest('id')->firstOrFail();
        $this->post(route("imports.{$jenis}.commit", $batch))->assertSessionHas('success');

        return $batch->refresh();
    }

    // ---------------- Shift & toleransi ----------------

    public function test_shift_menyimpan_toleransi_istirahat_dan_warna(): void
    {
        $this->actingAs($this->hrd)->post(route('shifts.store'), [
            'nama' => 'Pagi', 'jam_masuk' => '08:00', 'jam_pulang' => '17:00', 'late_tolerance_minutes' => '10',
            'early_leave_tolerance_minutes' => '', 'break_minutes' => '60', 'color' => 'success', 'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $shift = Shift::query()->sole();
        $this->assertSame(10, $shift->lateTolerance());
        $this->assertNull($shift->early_leave_tolerance_minutes);
        $this->assertSame(480, $shift->workMinutes());
        $this->get(route('shifts.index'))->assertOk()->assertSee('kerja 8 j');
    }

    public function test_menit_telat_dan_pulang_cepat_mengikuti_toleransi_shift(): void
    {
        $shift = Shift::factory()->jam('08:00', '16:00')->create(['late_tolerance_minutes' => 10, 'early_leave_tolerance_minutes' => 5]);
        $this->jadwal($this->karyawan, '2026-11-10', $shift);
        $this->jadwal($this->karyawan, '2026-11-11', $shift);

        $this->travelTo(CarbonImmutable::parse('2026-11-10 08:09'));
        $this->actingAs($this->kasir)->post(route('attendances.clock-in'))->assertSessionHas('success');
        $this->assertSame(StatusAbsensi::Hadir, Absensi::query()->sole()->status);

        $this->travelTo(CarbonImmutable::parse('2026-11-11 08:25'));
        $this->post(route('attendances.clock-in'));
        $this->travelTo(CarbonImmutable::parse('2026-11-11 15:30'));
        $this->post(route('attendances.clock-out'))->assertSessionHas('success');

        $hariKedua = Absensi::query()->whereDate('tanggal', '2026-11-11')->sole();
        $this->assertSame(StatusAbsensi::Terlambat, $hariKedua->status);
        $this->assertSame(25, $hariKedua->late_minutes);
        $this->assertSame(30, $hariKedua->early_leave_minutes);
        $this->assertSame($this->karyawan->id, $hariKedua->employee_id);
    }

    // ---------------- Radius & selfie ----------------

    public function test_absen_masuk_ditolak_di_luar_radius_cabang(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00'));
        Branch::query()->where('is_default', true)->update(['latitude' => -6.2, 'longitude' => 106.8166667, 'attendance_radius_m' => 100]);

        $this->actingAs($this->kasir)->get(route('ess.index'))->assertOk()->assertSee('Absen hanya dalam radius 100 m');

        $this->post(route('attendances.clock-in'))->assertSessionHas('error', fn (string $pesan) => str_contains($pesan, 'Lokasi Anda belum terbaca'));
        $this->post(route('attendances.clock-in'), ['lokasi' => '-6.210000,106.816667'])
            ->assertSessionHas('error', fn (string $pesan) => str_contains($pesan, 'di luar radius absen 100 m'));
        $this->assertSame(0, Absensi::count());

        $this->post(route('attendances.clock-in'), ['lokasi' => '-6.200300,106.816700'])->assertSessionHas('success');
        $this->assertLessThan(100, Absensi::query()->sole()->distance_m);
    }

    public function test_selfie_wajib_disimpan_privat_dan_hanya_dibuka_pemilik_atau_sdm(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00'));
        setting(['sdm.attendance.require_selfie' => true]);

        $this->actingAs($this->kasir)->post(route('attendances.clock-in'))->assertSessionHas('error', 'Ambil foto selfie dulu untuk absen.');
        $this->post(route('attendances.clock-in'), ['foto' => UploadedFile::fake()->image('selfie.jpg', 640, 480)])->assertSessionHas('success');

        $absensi = Absensi::query()->sole();
        Storage::disk('local')->assertExists($absensi->clock_in_photo);
        $this->get(route('attendances.photo', [$absensi, 'masuk']))->assertOk();
        $this->get(route('attendances.photo', [$absensi, 'pulang']))->assertNotFound();
        $this->actingAs($this->buatPengguna(RoleName::Kasir))->get(route('attendances.photo', [$absensi, 'masuk']))->assertForbidden();
        $this->actingAs($this->hrd)->get(route('attendances.index'))->assertSee('Foto masuk');
        $this->get(route('attendances.photo', [$absensi, 'masuk']))->assertOk();
    }

    public function test_titik_dan_radius_absen_disimpan_di_form_cabang(): void
    {
        $pemilik = $this->buatPengguna(RoleName::Pemilik);
        $cabang = Branch::query()->where('is_default', true)->sole();

        $this->actingAs($pemilik)->put(route('settings.branches.update', $cabang), [
            'code' => $cabang->code, 'name' => $cabang->name, 'is_active' => '1', 'is_default' => '1',
            'latitude' => '-6.2000000', 'longitude' => '106.8166667', 'attendance_radius_m' => '',
        ])->assertSessionHasErrors('attendance_radius_m');

        $this->put(route('settings.branches.update', $cabang), [
            'code' => $cabang->code, 'name' => $cabang->name, 'is_active' => '1', 'is_default' => '1',
            'latitude' => '-6.2000000', 'longitude' => '106.8166667', 'attendance_radius_m' => '150',
        ])->assertSessionHasNoErrors();

        $this->assertSame(150, $cabang->refresh()->attendance_radius_m);
        $this->assertEqualsWithDelta(-6.2, $cabang->latitude, 0.0000001);
    }

    // ---------------- Pengaturan SDM & hari libur ----------------

    public function test_pengaturan_sdm_mengubah_hari_kerja_dan_toleransi_bawaan(): void
    {
        $this->actingAs($this->kasir)->get(route('settings.sdm.edit'))->assertForbidden();

        $this->actingAs($this->hrd)->get(route('settings.sdm.edit'))->assertOk()->assertSee('Hari kerja per minggu');
        $this->put(route('settings.sdm.update'), [
            'workweek' => '5', 'default_in' => '08:00', 'default_out' => '17:00', 'late_tolerance' => '15', 'early_tolerance' => '0', 'require_selfie' => '0',
            'correction_days' => '30', 'period_start_day' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('settings.sdm.edit'));

        $this->assertSame('15', setting('sdm.attendance.late_tolerance'));
        $this->assertSame(15, Shift::factory()->create()->lateTolerance());

        $kalender = app(WorkCalendar::class)->days($this->karyawan, CarbonImmutable::parse('2026-11-13'), CarbonImmutable::parse('2026-11-15'));
        $this->assertSame(['work', 'off', 'off'], $kalender->pluck('type')->values()->all());
    }

    public function test_hari_libur_manual_ganda_ditolak_dan_dipakai_kalender_kerja(): void
    {
        $this->actingAs($this->hrd)->post(route('holidays.store'), ['date' => '2026-12-25', 'name' => 'Hari Raya Natal'])->assertSessionHasNoErrors();
        $this->post(route('holidays.store'), ['date' => '2026-12-25', 'name' => 'Natal lagi'])->assertSessionHasErrors('date');
        $this->get(route('holidays.index', ['tahun' => 2026]))->assertOk()->assertSee('Hari Raya Natal')->assertSee('Nasional');

        $hari = app(WorkCalendar::class)->day($this->karyawan, CarbonImmutable::parse('2026-12-25'));
        $this->assertSame('holiday', $hari['type']);
        $this->assertSame('Hari Raya Natal', $hari['holiday']);

        // Jadwal kerja pada hari libur tetap dihitung hari kerja.
        $this->jadwal($this->karyawan, '2026-12-25', Shift::factory()->create());
        $this->assertSame('work', app(WorkCalendar::class)->day($this->karyawan, CarbonImmutable::parse('2026-12-25'))['type']);
    }

    public function test_impor_hari_libur_dari_csv(): void
    {
        Holiday::factory()->create(['date' => '2026-01-01', 'name' => 'Tahun baru']);
        $cabang = Branch::query()->create(['code' => 'JBG', 'name' => 'Jabong', 'is_active' => true]);

        $batch = $this->impor('holidays', "Tanggal,Nama,Cabang\n01/01/2026,Tahun Baru Masehi,\n2026-08-17,Hari Kemerdekaan,\n20-10-2026,HUT Kota,JBG\n32/13/2026,Salah,\n");

        $this->assertSame([2, 1], [$batch->created_count, $batch->updated_count]);
        $this->assertSame(1, $batch->invalid_rows);
        $this->assertSame('Tahun Baru Masehi', Holiday::query()->whereDate('date', '2026-01-01')->sole()->name);
        $this->assertSame($cabang->id, Holiday::query()->whereDate('date', '2026-10-20')->sole()->branch_id);
        $this->assertSame(3, Holiday::count());
    }

    // ---------------- Impor fingerprint ----------------

    public function test_impor_fingerprint_mencatat_jam_masuk_pulang_tanpa_menimpa_izin(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-12 12:00'));
        $malam = Shift::factory()->jam('22:00', '06:00')->create(['nama' => 'Malam']);
        $budi = Employee::factory()->create(['code' => 'KRY-0002', 'name' => 'Budi']);
        $this->jadwal($budi, '2026-11-10', $malam);
        Absensi::factory()->tanpaJam(StatusAbsensi::Izin)->create(['user_id' => $this->kasir->id, 'tanggal' => '2026-11-11']);

        $csv = "PIN,Nama,Tanggal & Jam\n"
            ."1001,Rina,10/11/2026 07:58\n"
            ."1001,Rina,10/11/2026 16:05\n"
            ."1001,Rina,10/11/2026 12:01\n"
            ."1001,Rina,10/11/2026 7:58\n"
            ."1001,Rina,11/11/2026 08:00\n"
            ."KRY-0002,Budi,10/11/2026 21:55\n"
            ."KRY-0002,Budi,11/11/2026 06:10\n"
            ."9999,Siapa,10/11/2026 08:00\n";
        $batch = $this->impor('attendance_scans', $csv);

        // PIN tak dikenal dan scan ganda (07:58 = 7:58) dilewati.
        $this->assertSame(2, $batch->invalid_rows);
        $rina = Absensi::query()->where('employee_id', $this->karyawan->id)->whereDate('tanggal', '2026-11-10')->sole();
        $this->assertSame(['07:58', '16:05'], [$rina->clock_in->format('H:i'), $rina->clock_out->format('H:i')]);
        $this->assertSame('fingerprint', $rina->source);
        $this->assertSame(StatusAbsensi::Izin, Absensi::query()->where('employee_id', $this->karyawan->id)->whereDate('tanggal', '2026-11-11')->sole()->status);

        // Scan pulang shift malam keesokan paginya masuk ke tanggal mulai shift.
        $shiftMalam = Absensi::query()->where('employee_id', $budi->id)->sole();
        $this->assertSame('2026-11-10', $shiftMalam->tanggal->toDateString());
        $this->assertSame('2026-11-11 06:10', $shiftMalam->clock_out->format('Y-m-d H:i'));

        // Berkas yang sama diimpor ulang tidak menggandakan data.
        $this->impor('attendance_scans', $csv);
        $this->assertSame(3, Absensi::count());
    }

    // ---------------- Rotasi shift & jadwal bulanan ----------------

    public function test_pola_rotasi_diterapkan_ke_jadwal_karyawan(): void
    {
        $pagi = Shift::factory()->create(['nama' => 'Pagi']);
        $siang = Shift::factory()->jam('14:00', '22:00')->create(['nama' => 'Siang', 'color' => 'warning']);
        $budi = Employee::factory()->create(['name' => 'Budi']);
        $this->jadwal($budi, '2026-12-02', $siang);

        $this->actingAs($this->hrd)->post(route('shift-rotations.store'), [
            'name' => 'Rotasi 1-1-1', 'pattern' => [$pagi->id, $siang->id, 'libur'], 'is_active' => '1',
        ])->assertSessionHasNoErrors();
        $rotasi = ShiftRotation::query()->sole();
        $this->assertSame([$pagi->id, $siang->id, null], array_map(fn ($v) => $v === null ? null : (int) $v, $rotasi->pattern));
        $this->get(route('shift-rotations.index'))->assertOk()->assertSee('Rotasi 1-1-1');

        $this->post(route('shift-rotations.apply', $rotasi), [
            'employee_ids' => [$this->karyawan->id, $budi->id], 'start_date' => '2026-12-01', 'end_date' => '2026-12-06', 'offset' => '1',
        ])->assertRedirect(route('schedules.month', ['bulan' => '2026-12']))->assertSessionHas('success');

        $pola = fn (Employee $e) => Jadwal::query()->where('employee_id', $e->id)->orderBy('tanggal')->get()->map(fn (Jadwal $j) => $j->shift_id)->all();
        // offset 1: mulai dari hari ke-2 pola (Siang, Libur, Pagi, Siang, Libur, Pagi).
        $this->assertSame([$siang->id, null, $pagi->id, $siang->id, null, $pagi->id], $pola($this->karyawan));
        // Jadwal Budi 2 Des yang sudah ada tidak ditimpa.
        $this->assertSame([$siang->id, $siang->id, $pagi->id, $siang->id, null, $pagi->id], $pola($budi));
        $this->assertSame($this->kasir->id, Jadwal::query()->where('employee_id', $this->karyawan->id)->value('user_id'));

        $this->get(route('schedules.month', ['bulan' => '2026-12']))->assertOk()->assertSee('Budi')->assertSee('SI')->assertSee('PA');
    }

    public function test_rentang_rotasi_dibatasi_dan_butuh_izin_ubah_jadwal(): void
    {
        $rotasi = ShiftRotation::query()->create(['name' => 'R', 'pattern' => [null], 'is_active' => true]);

        $this->actingAs($this->hrd)->post(route('shift-rotations.apply', $rotasi), [
            'employee_ids' => [$this->karyawan->id], 'start_date' => '2026-01-01', 'end_date' => '2026-06-01',
        ])->assertSessionHasErrors('end_date');

        $this->actingAs($this->kasir)->post(route('shift-rotations.apply', $rotasi), [
            'employee_ids' => [$this->karyawan->id], 'start_date' => '2026-01-01', 'end_date' => '2026-01-02',
        ])->assertForbidden();
    }
}
