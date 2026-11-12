<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\RoleName;
use App\Models\Absensi;
use App\Models\ApprovalFlow;
use App\Models\Employee;
use App\Models\JenisCuti;
use App\Models\OvertimeRequest;
use App\Models\ReimbursementRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Layanan Mandiri (ESS): beranda, absen, saldo cuti, pengajuan lembur & reimbursement, keputusan SDM. */
class LayananMandiriTest extends TestCase
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
        $this->travelTo(CarbonImmutable::parse('2026-11-12 08:00'));
        $this->kasir = $this->buatPengguna(RoleName::Kasir, ['name' => 'Rina Kasir']);
        $this->karyawan = Employee::factory()->create(['name' => 'Rina Kasir', 'user_id' => $this->kasir->id]);
        $this->hrd = $this->buatPengguna(RoleName::Hrd);
    }

    private function lembur(array $ubah = []): TestResponse
    {
        return $this->actingAs($this->kasir)->post(route('ess.overtime.store'), array_merge([
            'overtime_date' => '2026-11-12', 'start_time' => '17:00', 'end_time' => '20:30', 'reason' => 'Stok opname akhir bulan',
        ], $ubah));
    }

    public function test_akun_tanpa_data_karyawan_melihat_petunjuk(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Kasir))->get(route('ess.index'))->assertOk()
            ->assertSee('Akun Anda belum ditautkan ke data karyawan');
    }

    public function test_beranda_menampilkan_saldo_cuti_dan_absen_kembali_ke_beranda(): void
    {
        JenisCuti::factory()->kuota(12)->create(['nama' => 'Cuti Tahunan']);

        $this->actingAs($this->kasir)->get(route('ess.index'))->assertOk()
            ->assertSee('Halo, Rina Kasir')->assertSee('Cuti Tahunan')->assertSee('12 hari')->assertSee('Absen masuk');

        $this->post(route('attendances.clock-in'), ['kembali' => 'ess'])->assertRedirect(route('ess.index'));
        $this->assertNotNull(Absensi::where('user_id', $this->kasir->id)->sole()->clock_in);

        $this->get(route('ess.attendance'))->assertOk()->assertSee('November 2026');
        $this->get(route('ess.profile'))->assertOk()->assertSee($this->karyawan->code);
        $this->get(route('ess.payslips'))->assertOk()->assertSee('Slip gaji belum tersedia');
    }

    public function test_lembur_dihitung_lewat_tengah_malam_dan_jam_bentrok_ditolak(): void
    {
        $this->lembur(['start_time' => '21:00', 'end_time' => '01:30'])->assertSessionHasNoErrors();

        $lembur = OvertimeRequest::sole();
        $this->assertSame(270, $lembur->duration_minutes);
        $this->assertSame('2026-11-13 01:30', $lembur->ends_at->format('Y-m-d H:i'));
        $this->assertSame(DocumentStatus::Submitted, $lembur->documentStatus());
        $this->assertSame('LBR-202611-0001', $lembur->number);

        $this->lembur(['start_time' => '23:00', 'end_time' => '23:30'])->assertSessionHas('error');
        $this->assertSame(1, OvertimeRequest::count());

        $this->get(route('ess.overtime'))->assertOk()->assertSee('21:00–01:30 (+1)');
    }

    public function test_sdm_menyetujui_atau_menolak_langsung_dan_karyawan_bisa_membatalkan(): void
    {
        $this->lembur();
        $this->lembur(['overtime_date' => '2026-11-11']);
        $this->lembur(['overtime_date' => '2026-11-10']);
        [$a, $b, $c] = OvertimeRequest::orderBy('id')->get()->all();

        $this->actingAs($this->hrd)->get(route('overtime.index'))->assertOk()->assertSee('Rina Kasir')->assertSee('Setujui');
        $this->post(route('overtime.approve', $a->id))->assertSessionHasNoErrors();
        $this->post(route('overtime.reject', $b->id), ['alasan' => 'Tidak ada perintah lembur'])->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::Approved, $a->fresh()->documentStatus());
        $this->assertSame('Ditolak', $b->fresh()->statusLabel());
        $this->assertSame($this->hrd->id, $a->fresh()->approved_by);

        $this->actingAs($this->kasir)->post(route('ess.overtime.cancel', $c->id))->assertSessionHasNoErrors();
        $this->assertSame('Dibatalkan', $c->fresh()->statusLabel());
        $this->post(route('ess.overtime.cancel', $a->id))->assertSessionHas('error');
        $this->get(route('ess.overtime'))->assertSee('Tidak ada perintah lembur');
    }

    public function test_lembur_panjang_lewat_alur_persetujuan(): void
    {
        $flow = ApprovalFlow::create(['name' => 'Lembur > 3 jam', 'document_type' => 'overtime', 'conditions' => [['field' => 'hours', 'operator' => '>', 'value' => '3']], 'priority' => 100, 'is_active' => true]);
        $flow->steps()->create(['sequence' => 1, 'name' => 'HRD', 'approver_type' => 'role', 'role_id' => Role::findByName(RoleName::Hrd->value)->id]);

        $this->lembur(['start_time' => '17:00', 'end_time' => '18:00']);
        $pendek = OvertimeRequest::latest('id')->first();
        $this->assertNull($pendek->pendingApproval(), 'Satu jam: tidak cocok dengan alur, diputuskan SDM langsung.');

        $this->lembur(['overtime_date' => '2026-11-11', 'start_time' => '16:00', 'end_time' => '21:00']);
        $panjang = OvertimeRequest::latest('id')->first();
        $this->assertNotNull($panjang->pendingApproval());

        $this->actingAs($this->hrd)->post(route('overtime.approve', $panjang->id))->assertSessionHas('error');
        $this->post(route('approvals.approve', $panjang->pendingApproval()))->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::Approved, $panjang->fresh()->documentStatus());
    }

    public function test_reimbursement_dengan_bukti_yang_hanya_bisa_dilihat_pemilik_dan_sdm(): void
    {
        $this->actingAs($this->kasir)->post(route('ess.reimbursements.store'), [
            'expense_date' => '2026-11-11', 'category' => 'transport', 'amount' => '45000', 'reason' => 'Ojek antar barang ke cabang',
            'receipts' => [UploadedFile::fake()->image('struk.jpg', 600, 800)],
        ])->assertSessionHasNoErrors();

        $klaim = ReimbursementRequest::sole();
        $struk = $klaim->attachments()->sole();
        $this->assertSame('45000.00', (string) $klaim->amount);

        $this->get(route('attachments.show', $struk))->assertOk();
        $this->actingAs($this->buatPengguna(RoleName::Kasir))->get(route('attachments.show', $struk))->assertForbidden();
        $this->actingAs($this->hrd)->get(route('attachments.show', $struk))->assertOk();

        $this->get(route('reimbursements.index'))->assertOk()->assertSee('Transportasi')->assertSee('struk.jpg');
        $this->post(route('reimbursements.approve', $klaim->id))->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::Approved, $klaim->fresh()->documentStatus());
    }

    public function test_reimbursement_tanpa_bukti_ditolak_dan_hak_akses(): void
    {
        $this->actingAs($this->kasir)->post(route('ess.reimbursements.store'), [
            'expense_date' => '2026-11-11', 'category' => 'meal', 'amount' => '30000', 'reason' => 'Makan lembur',
        ])->assertSessionHasErrors('receipts');

        $this->get(route('overtime.index'))->assertForbidden();
        $this->get(route('reimbursements.index'))->assertForbidden();
        $this->actingAs($this->buatPengguna(RoleName::Purchasing))->get(route('ess.index'))->assertOk();
    }
}
