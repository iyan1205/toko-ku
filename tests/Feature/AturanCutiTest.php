<?php

namespace Tests\Feature;

use App\Enums\Gender;
use App\Enums\RoleName;
use App\Enums\StatusAbsensi;
use App\Enums\StatusCuti;
use App\Models\Absensi;
use App\Models\ApprovalFlow;
use App\Models\Cuti;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\JenisCuti;
use App\Models\User;
use App\Services\Sdm\AttendanceRecapService;
use App\Services\Sdm\CutiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Fase 13c: aturan jenis cuti, saldo per masa kerja, persetujuan cuti lewat mesin persetujuan, kalender cuti tim. */
class AturanCutiTest extends TestCase
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
        $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00'));
        $this->kasir = $this->buatPengguna(RoleName::Kasir, ['name' => 'Rina Kasir']);
        $this->karyawan = Employee::factory()->create(['name' => 'Rina Kasir', 'user_id' => $this->kasir->id, 'gender' => Gender::Female, 'join_date' => '2021-01-04']);
        $this->hrd = $this->buatPengguna(RoleName::Hrd);
    }

    private function ajukan(JenisCuti $jenis, string $mulai, string $selesai, array $ubah = []): TestResponse
    {
        return $this->actingAs($this->kasir)->post(route('leaves.store'), array_merge([
            'jenis_cuti_id' => $jenis->id, 'tanggal_mulai' => $mulai, 'tanggal_selesai' => $selesai, 'alasan' => 'Keperluan keluarga',
        ], $ubah));
    }

    public function test_hari_kerja_saja_tidak_menghitung_minggu_dan_hari_libur(): void
    {
        $jenis = JenisCuti::factory()->kuota(12)->create(['working_days_only' => true]);
        Holiday::factory()->create(['date' => '2026-11-16', 'name' => 'Libur Contoh']);

        // Jumat 13 – Selasa 17 Nov: Minggu 15 libur mingguan, Senin 16 hari libur → 3 hari kerja.
        $this->ajukan($jenis, '2026-11-13', '2026-11-17')->assertSessionHas('success');
        $cuti = Cuti::query()->sole();
        $this->assertSame([3, StatusCuti::Disetujui, $this->karyawan->id], [$cuti->jumlah_hari, $cuti->status, $cuti->employee_id]);
        $this->assertSame(['2026-11-13', '2026-11-14', '2026-11-17'], Absensi::query()->orderBy('tanggal')->get()->map(fn ($a) => $a->tanggal->toDateString())->all());
        $this->assertSame(9, app(CutiService::class)->sisaKuota($this->kasir, $jenis, 2026));

        $this->ajukan($jenis, '2026-11-15', '2026-11-15')->assertSessionHas('error', fn ($p) => str_contains($p, 'tidak berisi hari kerja'));
    }

    public function test_saldo_tahunan_mengikuti_masa_kerja(): void
    {
        $tahunan = JenisCuti::factory()->create([
            'nama' => 'Cuti Tahunan', 'kuota_tahunan' => 12, 'min_service_months' => 12,
            'quota_tiers' => [['months' => 12, 'days' => 12], ['months' => 60, 'days' => 15]],
        ]);
        $service = app(CutiService::class);

        $this->assertSame(15, $service->sisaKuota($this->kasir, $tahunan, 2026));

        $baru = $this->buatPengguna(RoleName::Kasir);
        Employee::factory()->create(['user_id' => $baru->id, 'join_date' => '2026-03-01']);
        $this->assertSame(0, $service->sisaKuota($baru, $tahunan, 2026));
        $this->actingAs($baru)->post(route('leaves.store'), ['jenis_cuti_id' => $tahunan->id, 'tanggal_mulai' => '2026-11-20', 'tanggal_selesai' => '2026-11-20', 'alasan' => 'Liburan'])
            ->assertSessionHas('error', 'Cuti Tahunan bisa diambil setelah masa kerja 12 bulan.');

        $this->actingAs($this->kasir)->get(route('ess.leaves'))->assertOk()->assertSee('15');
    }

    public function test_aturan_jenis_kelamin_batas_hari_dan_surat_dokter(): void
    {
        $istri = JenisCuti::factory()->create(['nama' => 'Istri Melahirkan', 'gender' => Gender::Male, 'max_days_per_request' => 2]);
        $nikah = JenisCuti::factory()->create(['nama' => 'Cuti Menikah', 'max_days_per_request' => 3]);
        $sakit = JenisCuti::factory()->create(['nama' => 'Sakit', 'status_absensi' => StatusAbsensi::Sakit, 'requires_document' => true, 'document_after_days' => 1]);

        $this->actingAs($this->kasir)->get(route('leaves.create'))->assertOk()->assertDontSee('Istri Melahirkan');
        $this->ajukan($istri, '2026-11-13', '2026-11-13')->assertSessionHas('error', 'Istri Melahirkan hanya untuk karyawan Laki-laki.');
        $this->ajukan($nikah, '2026-11-13', '2026-11-18')->assertSessionHas('error', fn ($p) => str_contains($p, 'paling lama 3 hari per pengajuan'));

        $this->ajukan($sakit, '2026-11-13', '2026-11-13')->assertSessionHas('success');
        $this->ajukan($sakit, '2026-11-17', '2026-11-18')->assertSessionHas('error', fn ($p) => str_contains($p, 'wajib melampirkan surat keterangan'));
        $this->ajukan($sakit, '2026-11-17', '2026-11-18', ['dokumen' => [UploadedFile::fake()->image('surat.jpg')]])->assertSessionHas('success');

        $cuti = Cuti::query()->latest('id')->first();
        $this->assertSame(1, $cuti->attachments()->count());
        $this->get(route('leaves.show', $cuti))->assertOk()->assertSee('surat.jpg');
    }

    public function test_cuti_tanpa_upah_masuk_rekap_terpisah(): void
    {
        $tanpaUpah = JenisCuti::factory()->create(['nama' => 'Cuti Tanpa Upah', 'is_paid' => false, 'category' => 'unpaid']);
        $this->ajukan($tanpaUpah, '2026-11-09', '2026-11-10')->assertSessionHas('success');

        $s = app(AttendanceRecapService::class)->summarize([$this->karyawan], CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-10'))[$this->karyawan->id];
        $this->assertSame([2, 0], [$s['unpaid_leave'], $s['leave']]);
    }

    public function test_cuti_lewat_mesin_persetujuan_atasan_lalu_hrd(): void
    {
        $atasan = $this->buatPengguna(RoleName::Admin, ['name' => 'Bu Atasan']);
        $this->karyawan->update(['supervisor_id' => Employee::factory()->create(['user_id' => $atasan->id])->id]);
        $jenis = JenisCuti::factory()->kuota(12)->alur([RoleName::Admin])->create(['nama' => 'Cuti Tahunan']);
        $flow = ApprovalFlow::create(['name' => 'Cuti panjang', 'document_type' => 'leave', 'conditions' => [['field' => 'days', 'operator' => '>=', 'value' => '3']], 'priority' => 100, 'is_active' => true]);
        $flow->steps()->create(['sequence' => 1, 'name' => 'Atasan', 'approver_type' => 'supervisor']);
        $flow->steps()->create(['sequence' => 2, 'name' => 'HRD', 'approver_type' => 'role', 'role_id' => Role::findByName(RoleName::Hrd->value)->id]);
        $this->assertSame('Jumlah hari minimal 3 hari', $flow->conditionSummary());

        // 1 hari: tidak cocok dengan alur → tahap lama jenis cuti.
        $this->ajukan($jenis, '2026-11-13', '2026-11-13');
        $pendek = Cuti::query()->sole();
        $this->assertNull($pendek->pendingApproval());
        $this->assertSame(1, $pendek->tahap_aktif);

        $this->ajukan($jenis, '2026-11-17', '2026-11-19')->assertSessionHas('success', fn ($p) => str_contains($p, 'Menunggu persetujuan Atasan'));
        $cuti = Cuti::query()->latest('id')->first();
        $pending = $cuti->pendingApproval();
        $this->assertSame($atasan->id, $pending->current_user_id);
        $this->assertSame(StatusCuti::Menunggu, $cuti->status);
        $this->assertSame(0, $cuti->persetujuan()->count());

        $this->actingAs($this->hrd)->post(route('approvals.approve', $pending))->assertSessionHas('error');
        $this->actingAs($atasan)->get(route('leaves.show', $cuti))->assertOk()->assertSee('Persetujuan');
        $this->post(route('approvals.approve', $pending))->assertSessionHasNoErrors();
        $this->actingAs($this->hrd)->post(route('approvals.approve', $pending->refresh()))->assertSessionHasNoErrors();

        $this->assertSame(StatusCuti::Disetujui, $cuti->refresh()->status);
        $this->assertSame(3, Absensi::query()->where('employee_id', $this->karyawan->id)->where('status', StatusAbsensi::Cuti)->count());
    }

    public function test_tanpa_atasan_tahap_atasan_dilewati_dan_pembatalan_menutup_persetujuan(): void
    {
        $jenis = JenisCuti::factory()->kuota(12)->create();
        $flow = ApprovalFlow::create(['name' => 'Semua cuti', 'document_type' => 'leave', 'conditions' => [], 'priority' => 100, 'is_active' => true]);
        $flow->steps()->create(['sequence' => 1, 'name' => 'Atasan', 'approver_type' => 'supervisor']);
        $flow->steps()->create(['sequence' => 2, 'name' => 'HRD', 'approver_type' => 'role', 'role_id' => Role::findByName(RoleName::Hrd->value)->id]);

        $this->ajukan($jenis, '2026-11-17', '2026-11-17');
        $cuti = Cuti::query()->sole();
        $this->assertSame([2, 'HRD'], [$cuti->pendingApproval()->current_step, $cuti->pendingApproval()->currentStep()['name']]);

        $this->post(route('leaves.cancel', $cuti))->assertSessionHas('success');
        $this->assertSame(StatusCuti::Dibatalkan, $cuti->refresh()->status);
        $this->assertNull($cuti->pendingApproval());
    }

    public function test_atasan_langsung_hanya_untuk_dokumen_karyawan_dan_format_kondisi(): void
    {
        $pemilik = $this->buatPengguna(RoleName::Pemilik);
        $this->actingAs($pemilik)->post(route('settings.approval-flows.store'), [
            'name' => 'PO', 'document_type' => 'purchase_order', 'priority' => 1, 'is_active' => '1',
            'steps' => [['name' => 'Atasan', 'approver_type' => 'supervisor']],
        ])->assertSessionHasErrors('steps.0.approver_type');

        $flow = ApprovalFlow::create(['name' => 'Lembur', 'document_type' => 'overtime', 'conditions' => [['field' => 'hours', 'operator' => '>', 'value' => '3.00']], 'priority' => 1, 'is_active' => true]);
        $this->assertSame('Jam lembur lebih dari 3 jam', $flow->conditionSummary());
        $jenis = JenisCuti::factory()->create(['nama' => 'Sakit']);
        $flow->update(['document_type' => 'leave', 'conditions' => [['field' => 'leave_type', 'operator' => '=', 'value' => (string) $jenis->id]]]);
        $this->assertSame('Jenis cuti sama dengan Sakit', $flow->refresh()->conditionSummary());
        $this->get(route('settings.approval-flows.create'))->assertOk()->assertSee('Atasan langsung pengaju');
    }

    public function test_jenis_cuti_standar_dan_pindah_tahap_ke_alur_persetujuan(): void
    {
        $lama = JenisCuti::factory()->kuota(12)->alur([RoleName::Admin])->create(['nama' => 'Cuti Tahunan', 'category' => 'other']);

        $this->actingAs($this->hrd)->post(route('leave-types.standard'))->assertSessionHas('success');
        $this->assertSame(11, JenisCuti::count());
        $this->assertSame([12, 'annual'], [$lama->refresh()->min_service_months, $lama->category]);
        $this->assertSame(Gender::Female, JenisCuti::query()->where('nama', 'Cuti Melahirkan')->sole()->gender);
        $this->post(route('leave-types.standard'))->assertSessionHas('success', 'Jenis cuti standar: 0 ditambahkan, 0 dilengkapi aturannya.');

        $this->post(route('leave-types.to-flow', $lama))->assertForbidden();
        $this->actingAs($this->buatPengguna(RoleName::Pemilik))->post(route('leave-types.to-flow', $lama))->assertRedirect();
        $flow = ApprovalFlow::query()->where('document_type', 'leave')->sole();
        $this->assertSame([['field' => 'leave_type', 'operator' => '=', 'value' => number_format($lama->id, 2, '.', '')]], $flow->conditions);
        $this->assertSame(0, $lama->tahap()->count());
        $this->assertSame('Peran Admin', $flow->steps->first()->approverLabel());
    }

    public function test_kalender_cuti_tim_per_departemen(): void
    {
        $dapur = Department::factory()->create(['name' => 'Dapur']);
        $kasir = Department::factory()->create(['name' => 'Kasir']);
        $this->karyawan->update(['department_id' => $kasir->id]);
        $rekan = Employee::factory()->create(['name' => 'Rekan Kasir', 'department_id' => $kasir->id, 'user_id' => $this->buatPengguna(RoleName::Kasir)->id]);
        Employee::factory()->create(['name' => 'Koki Dapur', 'department_id' => $dapur->id]);
        $jenis = JenisCuti::factory()->create(['nama' => 'Tahunan']);
        Cuti::factory()->create(['user_id' => $rekan->user_id, 'employee_id' => $rekan->id, 'jenis_cuti_id' => $jenis->id, 'tanggal_mulai' => '2026-11-20', 'tanggal_selesai' => '2026-11-21', 'jumlah_hari' => 2, 'status' => StatusCuti::Disetujui]);

        $this->actingAs($this->kasir)->get(route('leaves.calendar', ['bulan' => '2026-11']))->assertOk()
            ->assertSee('Rekan Kasir')->assertDontSee('Koki Dapur')->assertSee('Tahunan · Disetujui', false);
        $this->actingAs($this->hrd)->get(route('leaves.calendar', ['bulan' => '2026-11']))->assertOk()->assertSee('Koki Dapur');
    }
}
