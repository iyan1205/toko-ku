<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\RoleName;
use App\Enums\StatusAbsensi;
use App\Enums\StatusCuti;
use App\Models\Absensi;
use App\Models\ApprovalFlow;
use App\Models\AttendanceCorrection;
use App\Models\Cuti;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Jadwal;
use App\Models\JenisCuti;
use App\Models\OvertimeRequest;
use App\Models\OvertimeRule;
use App\Models\Shift;
use App\Models\User;
use App\Services\Sdm\AbsensiService;
use App\Services\Sdm\AttendanceRecapService;
use App\Services\Sdm\OvertimeCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Fase 13b: koreksi absensi dengan persetujuan, aturan & upah lembur, rekap absensi per periode. */
class KoreksiLemburRekapTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $kasir;

    private Employee $karyawan;

    private User $hrd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00'));
        $this->kasir = $this->buatPengguna(RoleName::Kasir, ['name' => 'Rina Kasir']);
        $this->karyawan = Employee::factory()->create(['name' => 'Rina Kasir', 'user_id' => $this->kasir->id, 'join_date' => '2025-01-01', 'base_salary' => '3460000']);
        $this->hrd = $this->buatPengguna(RoleName::Hrd);
    }

    private function koreksi(array $ubah = []): AttendanceCorrection
    {
        $this->actingAs($this->kasir)->post(route('ess.corrections.store'), array_merge([
            'attendance_date' => '2026-11-11', 'clock_in' => '08:00', 'clock_out' => '17:00', 'reason' => 'Lupa absen, rapat di luar kantor',
        ], $ubah))->assertSessionHasNoErrors()->assertSessionHas('success');

        return AttendanceCorrection::query()->latest('id')->firstOrFail();
    }

    private function lembur(string $tanggal, string $mulai, int $menit): OvertimeRequest
    {
        $start = CarbonImmutable::parse("{$tanggal} {$mulai}");
        $lembur = new OvertimeRequest(['overtime_date' => $tanggal, 'starts_at' => $start, 'ends_at' => $start->addMinutes($menit), 'duration_minutes' => $menit, 'reason' => 'Stok opname']);
        $lembur->employee_id = $this->karyawan->id;
        $lembur->branch_id = $this->karyawan->branch_id ?? 1;
        $lembur->assignDocumentNumber();
        $lembur->forceFill(['status' => DocumentStatus::Approved->value])->save();

        return $lembur;
    }

    // ---------------- Koreksi absensi ----------------

    public function test_koreksi_disetujui_sdm_menerapkan_jam_ke_absensi(): void
    {
        $shift = Shift::factory()->jam('08:00', '17:00')->create();
        Jadwal::query()->create(['employee_id' => $this->karyawan->id, 'tanggal' => '2026-11-11', 'shift_id' => $shift->id]);

        $this->actingAs($this->kasir)->get(route('ess.attendance'))->assertOk()->assertSee('Alpha')->assertSee('Ajukan koreksi');
        $koreksi = $this->koreksi(['clock_in' => '08:20']);
        $this->assertSame(DocumentStatus::Submitted, $koreksi->documentStatus());
        $this->assertSame('Tidak ada absen', $koreksi->originalLabel());
        $this->assertSame(0, Absensi::count());

        $this->actingAs($this->hrd)->get(route('attendance-corrections.index'))->assertOk()->assertSee($koreksi->number)->assertSee('08:20–17:00');
        $this->post(route('attendance-corrections.approve', $koreksi->id))->assertSessionHas('success');

        $absensi = Absensi::query()->sole();
        $this->assertSame(['08:20', '17:00'], [$absensi->clock_in->format('H:i'), $absensi->clock_out->format('H:i')]);
        $this->assertSame(StatusAbsensi::Terlambat, $absensi->status);
        $this->assertSame(20, $absensi->late_minutes);
        $this->assertSame('correction', $absensi->source);
        $this->assertSame($absensi->id, $koreksi->refresh()->absensi_id);
        $this->assertSame($this->kasir->id, $absensi->user_id);
    }

    public function test_koreksi_hanya_jam_pulang_mempertahankan_jam_masuk(): void
    {
        Absensi::factory()->create(['user_id' => $this->kasir->id, 'tanggal' => '2026-11-11', 'clock_in' => '2026-11-11 08:00:00', 'clock_out' => null]);

        $koreksi = $this->koreksi(['clock_in' => '', 'clock_out' => '16:30']);
        $this->assertSame('08:00–—', $koreksi->originalLabel());

        $this->actingAs($this->hrd)->post(route('attendance-corrections.reject', $koreksi->id), ['alasan' => 'Tidak sesuai CCTV'])->assertSessionHas('success');
        $this->assertNull(Absensi::query()->sole()->clock_out);
        $this->assertTrue($koreksi->refresh()->is_rejected);

        $lagi = $this->koreksi(['clock_in' => '', 'clock_out' => '16:30']);
        $this->actingAs($this->hrd)->post(route('attendance-corrections.approve', $lagi->id));
        $absensi = Absensi::query()->sole();
        $this->assertSame(['08:00', '16:30'], [$absensi->clock_in->format('H:i'), $absensi->clock_out->format('H:i')]);
    }

    public function test_koreksi_ditolak_untuk_tanggal_cuti_terlalu_lama_dan_ganda(): void
    {
        Absensi::factory()->tanpaJam(StatusAbsensi::Cuti)->create(['user_id' => $this->kasir->id, 'tanggal' => '2026-11-10']);
        $kirim = fn (array $ubah) => $this->actingAs($this->kasir)->post(route('ess.corrections.store'), array_merge([
            'attendance_date' => '2026-11-11', 'clock_in' => '08:00', 'clock_out' => '17:00', 'reason' => 'Lupa absen pagi',
        ], $ubah));

        $kirim(['attendance_date' => '2026-11-10'])->assertSessionHas('error', fn ($p) => str_contains($p, 'tercatat Cuti'));
        $kirim(['attendance_date' => '2026-11-13'])->assertSessionHasErrors('attendance_date');
        $kirim(['attendance_date' => '2026-09-01'])->assertSessionHas('error', fn ($p) => str_contains($p, 'paling lama 30 hari'));
        $kirim(['clock_in' => '', 'clock_out' => ''])->assertSessionHasErrors('clock_in');
        $kirim([])->assertSessionHas('success');
        $kirim([])->assertSessionHas('error', 'Sudah ada koreksi yang menunggu persetujuan untuk tanggal ini.');

        $koreksi = AttendanceCorrection::query()->sole();
        $this->post(route('ess.corrections.cancel', $koreksi->id))->assertSessionHas('success');
        $this->assertSame(DocumentStatus::Cancelled, $koreksi->refresh()->documentStatus());
    }

    public function test_koreksi_lewat_alur_persetujuan_diterapkan_saat_disetujui(): void
    {
        $flow = ApprovalFlow::create(['name' => 'Koreksi absensi', 'document_type' => 'attendance_correction', 'conditions' => [], 'priority' => 100, 'is_active' => true]);
        $flow->steps()->create(['sequence' => 1, 'name' => 'HRD', 'approver_type' => 'role', 'role_id' => Role::findByName(RoleName::Hrd->value)->id]);

        $koreksi = $this->koreksi();
        $this->assertNotNull($koreksi->pendingApproval());
        $this->actingAs($this->hrd)->post(route('attendance-corrections.approve', $koreksi->id))->assertSessionHas('error');

        $this->post(route('approvals.approve', $koreksi->pendingApproval()))->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::Approved, $koreksi->refresh()->documentStatus());
        $this->assertSame('17:00', Absensi::query()->sole()->clock_out->format('H:i'));
    }

    // ---------------- Upah lembur ----------------

    public function test_upah_lembur_mengikuti_pp35_hari_kerja_dan_hari_libur(): void
    {
        $calc = app(OvertimeCalculator::class);

        // Upah sejam = 3.460.000 / 173 = 20.000. Hari kerja 3 jam = 1×1,5 + 2×2 = 5,5 jam.
        $kerja = $calc->calculate($this->karyawan, CarbonImmutable::parse('2026-11-11'), 180);
        $this->assertSame(['work', '5.5', '20000.00', '110000.00'], [$kerja['day_type'], $kerja['weighted_hours'], $kerja['hourly'], $kerja['amount']]);

        // Hari libur nasional, 6 hari kerja: 9 jam = 7×2 + 1×3 + 1×4 = 21 jam.
        Holiday::factory()->create(['date' => '2026-11-20']);
        $libur = $calc->calculate($this->karyawan, CarbonImmutable::parse('2026-11-20'), 540);
        $this->assertSame(['holiday', '21', '420000.00'], [$libur['day_type'], $libur['weighted_hours'], $libur['amount']]);

        // 5 hari kerja, Sabtu = istirahat: 10 jam = 8×2 + 1×3 + 1×4 = 23 jam.
        setting(['sdm.workweek' => 5]);
        $sabtu = app(OvertimeCalculator::class)->calculate($this->karyawan, CarbonImmutable::parse('2026-11-14'), 600);
        $this->assertSame(['rest', '23', '460000.00'], [$sabtu['day_type'], $sabtu['weighted_hours'], $sabtu['amount']]);
    }

    public function test_aturan_lembur_baru_berlaku_mulai_tanggalnya(): void
    {
        $this->actingAs($this->hrd)->post(route('settings.sdm.overtime-rules.store'), [
            'effective_from' => '2026-12-01', 'mode' => 'multiplier', 'divisor' => '173', 'workday_tiers' => '1:1,5; 2', 'rest_day_tiers_6' => '*:2', 'rest_day_tiers_5' => '*:2',
        ])->assertSessionHasErrors('workday_tiers');

        $this->post(route('settings.sdm.overtime-rules.store'), [
            'effective_from' => '2026-12-01', 'mode' => 'flat', 'flat_rate' => '25000', 'rounding_minutes' => '30', 'min_minutes' => '60',
        ])->assertSessionHasNoErrors();
        $this->get(route('settings.sdm.edit'))->assertOk()->assertSee('Rp 25.000 per jam')->assertSee('1 jam × 1,5 · sisa × 2');

        $calc = app(OvertimeCalculator::class);
        $this->assertSame('110000.00', $calc->calculate($this->karyawan, CarbonImmutable::parse('2026-11-30'), 180)['amount']);
        // Flat: 100 menit dibulatkan ke 90 menit × 25.000/jam = 37.500; di bawah 60 menit tidak dibayar.
        $this->assertSame('37500.00', $calc->calculate($this->karyawan, CarbonImmutable::parse('2026-12-02'), 100)['amount']);
        $this->assertSame('0.00', $calc->calculate($this->karyawan, CarbonImmutable::parse('2026-12-02'), 45)['amount']);

        $this->karyawan->update(['overtime_eligible' => false]);
        $this->assertSame('Karyawan tidak mendapat upah lembur.', $calc->calculate($this->karyawan, CarbonImmutable::parse('2026-11-30'), 180)['reason']);

        $baru = OvertimeRule::query()->whereDate('effective_from', '2026-12-01')->sole();
        $this->delete(route('settings.sdm.overtime-rules.destroy', $baru))->assertSessionHas('success');
        $lama = OvertimeRule::query()->sole();
        $this->delete(route('settings.sdm.overtime-rules.destroy', $lama))->assertSessionHas('error');
    }

    public function test_gaji_pokok_hanya_diubah_pemegang_izin_penggajian(): void
    {
        $payload = fn (array $ubah) => array_merge(['name' => $this->karyawan->name, 'code' => $this->karyawan->code, 'employment_type' => $this->karyawan->employment_type->value, 'is_active' => '1'], $ubah);
        $admin = $this->buatPengguna(RoleName::Admin);
        $admin->givePermissionTo('sdm.employees.update');

        $this->actingAs($admin)->get(route('employees.edit', $this->karyawan))->assertOk()->assertDontSee('Gaji pokok per bulan');
        $this->put(route('employees.update', $this->karyawan), $payload(['base_salary' => '9000000']))->assertSessionHasNoErrors();
        $this->assertSame('3460000.00', $this->karyawan->refresh()->base_salary);

        $this->actingAs($this->hrd)->put(route('employees.update', $this->karyawan), $payload(['base_salary' => '4000000', 'overtime_eligible' => '0']))->assertSessionHasNoErrors();
        $this->assertSame('4000000.00', $this->karyawan->refresh()->base_salary);
        $this->assertFalse($this->karyawan->overtime_eligible);
    }

    // ---------------- Rekap absensi ----------------

    public function test_rekap_bulanan_menghitung_hadir_telat_alpha_cuti_dan_lembur(): void
    {
        $shift = Shift::factory()->jam('08:00', '16:00')->create(['late_tolerance_minutes' => 0]);
        foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'] as $tgl) {
            Jadwal::query()->create(['employee_id' => $this->karyawan->id, 'tanggal' => $tgl, 'shift_id' => $shift->id]);
        }
        $absen = fn (string $tgl, string $masuk, ?string $pulang) => Absensi::factory()->create([
            'user_id' => $this->kasir->id, 'tanggal' => $tgl, 'clock_in' => "{$tgl} {$masuk}", 'clock_out' => $pulang ? "{$tgl} {$pulang}" : null,
        ]);
        $absen('2026-11-02', '07:55', '16:05');
        $absen('2026-11-03', '08:30', '15:30');   // telat 30, pulang cepat 30
        $absen('2026-11-04', '08:00', null);      // tidak absen pulang
        Absensi::factory()->tanpaJam(StatusAbsensi::Sakit)->create(['user_id' => $this->kasir->id, 'tanggal' => '2026-11-07']);
        $tahunan = JenisCuti::factory()->create(['nama' => 'Cuti Tahunan', 'status_absensi' => StatusAbsensi::Cuti]);
        Cuti::factory()->create(['user_id' => $this->kasir->id, 'jenis_cuti_id' => $tahunan->id, 'tanggal_mulai' => '2026-11-09', 'tanggal_selesai' => '2026-11-10', 'jumlah_hari' => 2, 'status' => StatusCuti::Disetujui]);
        Holiday::factory()->create(['date' => '2026-11-11', 'name' => 'Libur Contoh']);
        $this->lembur('2026-11-02', '16:00', 180);
        // Factory tidak menghitung menit telat/pulang cepat; hitung seperti saat absen sungguhan.
        Absensi::query()->whereNotNull('clock_in')->get()->each(function (Absensi $a) {
            app(AbsensiService::class)->hitungUlang($a);
            $a->save();
        });

        $recap = app(AttendanceRecapService::class);
        [$from, $to] = $recap->period('2026-11');
        $s = $recap->summarize([$this->karyawan], $from, $to, true)[$this->karyawan->id];

        // Hari kerja sebulan (pembagi penggajian) = 26 Senin–Sabtu − 1 hari libur. Sampai hari ini (12 Nov):
        // 5 & 6 Nov berjadwal tanpa absen + 12 Nov = alpha.
        $this->assertSame(24, $s['work_days']);
        $this->assertSame([2, 1, 30, 1, 30, 1], [$s['present'], $s['late'], $s['late_minutes'], $s['early_count'], $s['early_minutes'], $s['missing_out']]);
        $this->assertSame([1, 2, 1, 3], [$s['sick'], $s['leave'], $s['holiday'], $s['absent']]);
        $this->assertSame(180, $s['overtime_minutes']);
        $this->assertSame('110000.00', $s['overtime_pay']);
        $this->assertSame(15, $s['future']); // 13–30 Nov tanpa 3 hari Minggu

        $this->actingAs($this->hrd)->get(route('attendance-recap.index', ['bulan' => '2026-11']))->assertOk()->assertSee('Rina Kasir')->assertSee('Rp 110.000');
        $this->get(route('attendance-recap.show', [$this->karyawan, 'bulan' => '2026-11']))->assertOk()->assertSee('Libur Contoh')->assertSee('Cuti Tahunan');

        $admin = $this->buatPengguna(RoleName::Admin);
        $this->actingAs($admin)->get(route('attendance-recap.index', ['bulan' => '2026-11']))->assertOk()->assertDontSee('Upah lembur');
        $this->actingAs($this->kasir)->get(route('attendance-recap.index'))->assertForbidden();
    }

    public function test_periode_rekap_mengikuti_awal_periode_dan_ekspor(): void
    {
        setting(['sdm.attendance.period_start_day' => 26]);
        [$from, $to] = app(AttendanceRecapService::class)->period('2026-11');
        $this->assertSame(['2026-10-26', '2026-11-25'], [$from->toDateString(), $to->toDateString()]);

        Excel::fake();
        $this->actingAs($this->hrd)->get(route('attendance-recap.export', ['bulan' => '2026-11']))->assertOk();
        Excel::assertDownloaded('rekap-absensi-2026-11.xlsx', fn ($export) => $export->array()[0][1] === 'Rina Kasir');
        $this->get(route('attendance-recap.export', ['bulan' => '2026-11', 'format' => 'pdf']))->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
