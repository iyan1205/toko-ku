<?php

namespace Tests\Feature;

use App\Enums\EmploymentType;
use App\Enums\RoleName;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeMovement;
use App\Models\Position;
use App\Models\User;
use App\Services\Sdm\EmployeeContractService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** HR inti: data karyawan lengkap, foto, atasan langsung, riwayat kontrak, dan mutasi/promosi. */
class DataKaryawanLengkapTest extends TestCase
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

    public function test_data_pribadi_pajak_keluarga_pendidikan_dan_foto_tersimpan(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);
        $this->actingAs($this->hrd)->post(route('employees.store'), [
            'name' => 'Siti Aminah', 'gender' => 'female', 'employment_type' => 'contract', 'is_active' => '1', 'user_id' => $kasir->id,
            'identity_number' => '3201010101900001', 'birth_place' => 'Bandung', 'birth_date' => '1990-01-01', 'religion' => 'Islam',
            'marital_status' => 'married', 'tax_id' => '12.345.678.9-012.345', 'ptkp_status' => 'K1',
            'bpjs_health_number' => '0001234567890', 'bank_name' => 'BCA', 'bank_account_number' => '1234567890', 'bank_account_name' => 'Siti Aminah',
            'emergency_contact_name' => 'Budi', 'emergency_contact_relation' => 'Suami', 'emergency_contact_phone' => '0812000111',
            'photo' => UploadedFile::fake()->image('siti.jpg', 300, 300),
            'family' => [
                ['name' => 'Budi', 'relation' => 'spouse', 'is_dependent' => '0'],
                ['name' => 'Ani', 'relation' => 'child', 'birth_date' => '2018-05-01', 'is_dependent' => '1'],
                ['name' => '', 'relation' => 'child'],
            ],
            'educations' => [['level' => 's1', 'institution' => 'Universitas Padjadjaran', 'major' => 'Akuntansi', 'start_year' => '2008', 'end_year' => '2012']],
            'experiences' => [['company' => 'Toko Lama', 'position' => 'Kasir', 'start_date' => '2012-06-01', 'end_date' => '2015-12-31']],
        ])->assertSessionHasNoErrors();

        $siti = Employee::sole();
        $this->assertSame('123456789012345', $siti->tax_id, 'Tanda baca NPWP dibuang.');
        $this->assertSame('K/1', $siti->ptkp_status->label());
        $this->assertSame(2, $siti->familyMembers()->count(), 'Baris tanpa nama diabaikan.');
        $this->assertTrue($siti->familyMembers()->where('name', 'Ani')->value('is_dependent'));
        $this->assertSame('Universitas Padjadjaran', $siti->educations()->value('institution'));
        Storage::disk('local')->assertExists($siti->photo_path);

        $this->get(route('employees.show', $siti))->assertOk()
            ->assertSee('Kontrak (PKWT)')->assertSee('123456789012345')->assertSee('Universitas Padjadjaran')->assertSee('Toko Lama');
        $this->get(route('employees.photo', $siti))->assertOk();

        // Karyawan itu sendiri boleh melihat fotonya; karyawan lain tidak.
        $this->actingAs($kasir)->get(route('employees.photo', $siti))->assertOk();
        $this->actingAs($this->buatPengguna(RoleName::Kasir))->get(route('employees.photo', $siti))->assertForbidden();

        // Ubah: ganti foto lama dan kosongkan keluarga.
        $lama = $siti->photo_path;
        $this->actingAs($this->hrd)->put(route('employees.update', $siti), ['name' => 'Siti Aminah', 'employment_type' => 'contract', 'is_active' => '1', 'user_id' => $kasir->id, 'remove_photo' => '1'])
            ->assertRedirect(route('employees.show', $siti));
        $this->assertNull($siti->fresh()->photo_path);
        Storage::disk('local')->assertMissing($lama);
        $this->assertSame(0, $siti->familyMembers()->count());
    }

    public function test_atasan_tidak_boleh_melingkar_dan_npwp_harus_lengkap(): void
    {
        $manajer = Employee::factory()->create(['name' => 'Manajer']);
        $staf = Employee::factory()->create(['name' => 'Staf', 'supervisor_id' => $manajer->id]);

        $this->actingAs($this->hrd)->put(route('employees.update', $manajer), ['name' => 'Manajer', 'employment_type' => 'permanent', 'supervisor_id' => $staf->id])
            ->assertSessionHasErrors(['supervisor_id' => 'Atasan tidak boleh karyawan itu sendiri atau bawahannya.']);
        $this->put(route('employees.update', $manajer), ['name' => 'Manajer', 'employment_type' => 'permanent', 'supervisor_id' => $manajer->id])
            ->assertSessionHasErrors('supervisor_id');
        $this->post(route('employees.store'), ['name' => 'Baru', 'employment_type' => 'permanent', 'tax_id' => '1234'])
            ->assertSessionHasErrors(['tax_id' => 'NPWP terdiri dari 15 atau 16 angka.']);

        $this->get(route('employees.show', $manajer))->assertOk()->assertSee('Bawahan (1)')->assertSee('Staf');
    }

    public function test_riwayat_kontrak_mengubah_status_kerja_dan_terdeteksi_saat_hampir_habis(): void
    {
        $budi = Employee::factory()->create(['name' => 'Budi', 'employment_type' => EmploymentType::Daily, 'join_date' => null]);
        $this->actingAs($this->hrd);

        $this->post(route('employees.contracts.store', $budi), ['employment_type' => 'contract', 'start_date' => '2026-01-01'])
            ->assertSessionHasErrors(['end_date' => 'Kontrak PKWT wajib punya tanggal berakhir.']);
        $this->post(route('employees.contracts.store', $budi), ['employment_type' => 'contract', 'start_date' => '2026-01-01', 'end_date' => '2026-11-30', 'number' => 'PKWT/001'])
            ->assertSessionHas('success');

        $budi->refresh();
        $this->assertSame(EmploymentType::Contract, $budi->employment_type);
        $this->assertSame('2026-01-01', $budi->join_date->toDateString(), 'Tanggal mulai kerja diisi dari kontrak pertama.');
        $this->assertSame([$budi->id], app(EmployeeContractService::class)->expiring(30)->get()->pluck('employee_id')->all(), 'Berakhir 18 hari lagi.');
        $this->assertSame([], app(EmployeeContractService::class)->expiring(10)->get()->all());

        $this->post(route('employees.contracts.store', $budi), ['employment_type' => 'permanent', 'start_date' => '2025-12-01'])->assertSessionHas('error');
        $this->post(route('employees.contracts.store', $budi), ['employment_type' => 'permanent', 'start_date' => '2026-12-01'])->assertSessionHas('success');
        $this->assertSame(EmploymentType::Permanent, $budi->fresh()->employment_type);
        $this->assertSame([], app(EmployeeContractService::class)->expiring(30)->get()->all(), 'Sudah diperpanjang menjadi PKWTT.');

        // Hapus kontrak terbaru: status kembali ke kontrak sebelumnya; kontrak lama tidak bisa dihapus.
        [$terbaru, $lama] = $budi->contracts()->get()->all();
        $this->delete(route('employees.contracts.destroy', [$budi, $lama]))->assertSessionHas('error', 'Hanya kontrak terbaru yang bisa dihapus.');
        $this->delete(route('employees.contracts.destroy', [$budi, $terbaru]))->assertSessionHas('success');
        $this->assertSame(EmploymentType::Contract, $budi->fresh()->employment_type);
        $this->get(route('employees.show', ['employee' => $budi, 'tab' => 'kontrak']))->assertOk()->assertSee('PKWT/001')->assertSee('18 hari lagi');
    }

    public function test_mutasi_langsung_diterapkan_dan_promosi_bertanggal_depan_menunggu(): void
    {
        $dapur = Department::factory()->create(['name' => 'Dapur']);
        $kasir = Position::factory()->create(['name' => 'Kasir']);
        $kepala = Position::factory()->create(['name' => 'Kepala Toko']);
        $rina = Employee::factory()->create(['name' => 'Rina', 'position_id' => $kasir->id, 'grade' => 'II']);
        $this->actingAs($this->hrd);

        $this->post(route('employees.movements.store', $rina), ['type' => 'transfer', 'effective_date' => '2026-11-12'])
            ->assertSessionHas('error', 'Isi minimal satu perubahan: cabang, departemen, jabatan, atasan, atau grade.');
        $this->post(route('employees.movements.store', $rina), ['type' => 'transfer', 'effective_date' => '2026-11-10', 'to_department_id' => $dapur->id, 'decree_number' => 'SK-01'])
            ->assertSessionHas('success', 'Mutasi dicatat dan sudah diterapkan.');
        $this->assertSame($dapur->id, $rina->fresh()->department_id);

        $this->post(route('employees.movements.store', $rina), ['type' => 'promotion', 'effective_date' => '2026-12-01', 'to_position_id' => $kepala->id, 'to_grade' => 'III'])
            ->assertSessionHas('success');
        $promosi = EmployeeMovement::whereNull('applied_at')->sole();
        $this->assertSame([$kasir->id, 'II'], [$rina->fresh()->position_id, $rina->fresh()->grade]);
        $this->assertSame(['Jabatan' => ['Kasir', 'Kepala Toko'], 'Grade' => ['II', 'III']], $promosi->changes());
        $this->post(route('employees.movements.store', $rina), ['type' => 'transfer', 'effective_date' => '2026-11-20', 'to_grade' => 'IV'])->assertSessionHas('error');

        $this->travelTo(CarbonImmutable::parse('2026-12-01 01:00'));
        $this->artisan('sdm:proses-harian')->assertSuccessful();
        $this->assertSame([$kepala->id, 'III'], [$rina->fresh()->position_id, $rina->fresh()->grade]);
        $this->assertNotNull($promosi->fresh()->applied_at);
        $this->delete(route('employees.movements.destroy', [$rina, $promosi]))->assertSessionHas('error');
        $this->get(route('employees.show', ['employee' => $rina, 'tab' => 'mutasi']))->assertOk()->assertSee('Promosi')->assertSee('Kepala Toko')->assertSee('SK-01');
    }
}
