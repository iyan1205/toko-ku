<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\TerminationType;
use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeDocument;
use App\Models\EmployeeOffboarding;
use App\Models\FixedAsset;
use App\Models\User;
use App\Notifications\HrExpiryReminder;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Assets\AssetCategoryService;
use App\Services\Assets\FixedAssetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Struktur organisasi, dokumen karyawan dengan masa berlaku + pengingat, dan offboarding. */
class OffboardingDokumenTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $hrd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->hrd = $this->buatPengguna(RoleName::Hrd);
        $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00'));
        Storage::fake('local');
    }

    public function test_bagan_organisasi_mengikuti_atasan_dan_saringan_departemen(): void
    {
        $dapur = Department::factory()->create(['name' => 'Dapur']);
        $pemilik = Employee::factory()->create(['name' => 'Pak Pemilik']);
        $kepala = Employee::factory()->create(['name' => 'Kepala Dapur', 'supervisor_id' => $pemilik->id, 'department_id' => $dapur->id]);
        Employee::factory()->create(['name' => 'Juru Masak', 'supervisor_id' => $kepala->id, 'department_id' => $dapur->id]);
        Employee::factory()->nonaktif()->create(['name' => 'Sudah Keluar', 'supervisor_id' => $kepala->id]);

        $this->actingAs($this->hrd)->get(route('org-chart.index'))->assertOk()
            ->assertSeeInOrder(['Pak Pemilik', 'Kepala Dapur', 'Juru Masak'])->assertSee('1 bawahan')->assertDontSee('Sudah Keluar');

        // Saring departemen: atasan di luar saringan tidak tampil, bawahannya naik menjadi puncak.
        $this->get(route('org-chart.index', ['departemen' => $dapur->id]))->assertOk()->assertSee('Kepala Dapur')->assertDontSee('Pak Pemilik');

        $this->actingAs($this->buatPengguna(RoleName::Kasir))->get(route('org-chart.index'))->assertForbidden();
    }

    public function test_dokumen_dengan_berkas_dan_pengingat_kontrak_serta_dokumen_yang_akan_berakhir(): void
    {
        Notification::fake();
        $rina = Employee::factory()->create(['name' => 'Rina']);
        $this->actingAs($this->hrd)->post(route('employees.documents.store', $rina), [
            'type' => 'driving_license', 'number' => 'SIM-123', 'issued_at' => '2021-11-20', 'expires_at' => '2026-11-20',
            'file' => UploadedFile::fake()->create('sim.pdf', 50, 'application/pdf'),
        ])->assertSessionHas('success');

        $sim = EmployeeDocument::sole();
        $this->assertSame('SIM', $sim->name, 'Nama kosong = label jenisnya.');
        $this->assertSame(1, $sim->attachments()->count());
        $this->get(route('employees.show', ['employee' => $rina, 'tab' => 'dokumen']))->assertOk()->assertSee('SIM-123')->assertSee('8 hari lagi');
        $this->get(route('attachments.download', $sim->attachments()->first()))->assertOk();

        $this->post(route('employees.documents.store', $rina), ['type' => 'ktp', 'issued_at' => '2026-12-01'])->assertSessionHasErrors('issued_at');

        // Pengingat: SIM berakhir 8 hari lagi + kontrak berakhir 20 hari lagi.
        $kontrak = new EmployeeContract(['employment_type' => 'contract', 'start_date' => '2026-06-01', 'end_date' => '2026-12-02']);
        $rina->contracts()->save($kontrak);
        $this->artisan('sdm:pengingat')->assertSuccessful();
        Notification::assertSentTo($this->hrd, HrExpiryReminder::class, fn ($n) => $n->summary['contracts'] === 1 && $n->summary['documents'] === 1
            && $n->body() === '1 kontrak berakhir dalam 30 hari, 1 dokumen kedaluwarsa dalam 30 hari.');
        Notification::assertNotSentTo($this->buatPengguna(RoleName::Admin), HrExpiryReminder::class);

        $this->delete(route('employees.documents.destroy', [$rina, $sim]))->assertSessionHas('success');
        $this->assertSoftDeleted($sim);
        $this->assertSame(0, $sim->attachments()->count());
    }

    public function test_offboarding_checklist_aset_lalu_karyawan_dan_akun_dinonaktifkan(): void
    {
        app(ChartOfAccountsService::class)->install();
        app(AssetCategoryService::class)->installStandard();
        $akun = $this->buatPengguna(RoleName::Kasir);
        $budi = Employee::factory()->create(['name' => 'Budi', 'user_id' => $akun->id]);
        $laptop = app(FixedAssetService::class)->create([
            'name' => 'Laptop kasir', 'asset_category_id' => AssetCategory::where('code', 'KLP1')->value('id'), 'acquisition_date' => '2026-11-01',
            'acquisition_cost' => '6000000', 'residual_value' => '0', 'credit_account_id' => Account::where('code', '1-1200')->value('id'), 'employee_id' => $budi->id,
        ], $this->buatPengguna(RoleName::SuperAdmin));

        $this->actingAs($this->buatPengguna(RoleName::Admin))->post(route('offboardings.store', $budi), ['termination_type' => 'resign', 'notice_date' => '2026-11-12', 'last_working_date' => '2026-11-12'])->assertForbidden();

        $this->actingAs($this->hrd)->post(route('offboardings.store', $budi), [
            'termination_type' => 'resign', 'notice_date' => '2026-11-01', 'last_working_date' => '2026-11-12', 'reason' => 'Pindah kota', 'deactivate_user' => '1',
        ])->assertRedirect();
        $o = EmployeeOffboarding::sole();
        $this->assertSame(count(config('sdm.offboarding_checklist')) + 1, $o->items()->count());
        $this->assertSame($laptop->id, $o->items()->whereNotNull('fixed_asset_id')->value('fixed_asset_id'));
        $this->post(route('offboardings.store', $budi), ['termination_type' => 'resign', 'notice_date' => '2026-11-01', 'last_working_date' => '2026-11-12'])
            ->assertSessionHas('error', 'Offboarding Budi sudah berjalan.');

        $this->post(route('offboardings.complete', $o))->assertSessionHas('error');
        $this->assertTrue($budi->fresh()->is_active);

        $this->post(route('offboardings.items.store', $o), ['label' => 'Laporan stok terakhir'])->assertSessionHas('success');
        foreach ($o->items()->get() as $item) {
            $this->post(route('offboardings.items.toggle', [$o, $item]), ['done' => '1'])->assertRedirect();
        }
        $this->get(route('offboardings.show', $o))->assertOk()->assertSee('Semua sudah selesai.')->assertSee('Aset AST-0001 Laptop kasir');
        $this->post(route('offboardings.complete', $o))->assertSessionHas('success', 'Offboarding selesai. Karyawan dan akun loginnya sudah dinonaktifkan.');

        $budi->refresh();
        $this->assertFalse($budi->is_active);
        $this->assertSame(['2026-11-12', TerminationType::Resign, 'Pindah kota'], [$budi->end_date->toDateString(), $budi->termination_type, $budi->termination_reason]);
        $this->assertFalse($akun->fresh()->is_active);
        $this->assertNull(FixedAsset::withoutGlobalScopes()->find($laptop->id)->employee_id, 'Aset yang dikembalikan dilepas dari karyawan.');
        $this->get(route('offboardings.index'))->assertOk()->assertSee('Budi')->assertSee('Selesai');
    }

    public function test_hari_terakhir_di_depan_dinonaktifkan_oleh_proses_harian_dan_offboarding_bisa_dibatalkan(): void
    {
        $akun = $this->buatPengguna(RoleName::Kasir);
        $sari = Employee::factory()->create(['name' => 'Sari', 'user_id' => $akun->id]);
        $tono = Employee::factory()->create(['name' => 'Tono']);
        $this->actingAs($this->hrd);

        $this->post(route('offboardings.store', $sari), ['termination_type' => 'contract_end', 'notice_date' => '2026-11-12', 'last_working_date' => '2026-11-30'])->assertRedirect();
        $o = EmployeeOffboarding::sole();
        $o->items()->update(['is_done' => true]);
        $this->post(route('offboardings.complete', $o))->assertSessionHas('success', 'Offboarding selesai. Karyawan dinonaktifkan otomatis setelah hari terakhir kerjanya.');
        $this->assertTrue($sari->fresh()->is_active);
        $this->assertTrue($akun->fresh()->is_active);

        $this->travelTo(CarbonImmutable::parse('2026-11-30 01:00'));
        $this->artisan('sdm:proses-harian')->assertSuccessful();
        $this->assertFalse($sari->fresh()->is_active);
        $this->assertFalse($akun->fresh()->is_active);
        $this->assertNotNull($o->fresh()->deactivated_at);

        $this->post(route('offboardings.store', $tono), ['termination_type' => 'dismissal', 'notice_date' => '2026-11-30', 'last_working_date' => '2026-12-15'])->assertRedirect();
        $batal = EmployeeOffboarding::where('employee_id', $tono->id)->sole();
        $this->post(route('offboardings.cancel', $batal), ['alasan' => 'Batal PHK setelah mediasi'])->assertSessionHas('success');
        $this->assertSame(EmployeeOffboarding::CANCELLED, $batal->fresh()->status);
        $this->assertTrue($tono->fresh()->is_active);
        $this->get(route('employees.show', $tono))->assertOk()->assertSee('Offboarding');
    }
}
