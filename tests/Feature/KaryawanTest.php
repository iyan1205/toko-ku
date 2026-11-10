<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Karyawan, departemen, dan jabatan (SDM), termasuk tautan opsional ke akun login. */
class KaryawanTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $hrd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->hrd = $this->buatPengguna(RoleName::Hrd);
    }

    /** @param array<string, mixed> $ubah */
    private function payload(array $ubah = []): array
    {
        return array_merge([
            'name' => 'Siti Aminah',
            'employment_type' => 'contract',
            'join_date' => '2026-01-05',
            'phone' => '0812 3456 7890',
            'is_active' => '1',
        ], $ubah);
    }

    public function test_menambah_karyawan_dengan_kode_otomatis_dan_akun_login(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);
        $dapur = Department::factory()->create(['name' => 'Dapur']);

        $this->actingAs($this->hrd)
            ->post(route('employees.store'), $this->payload(['user_id' => $kasir->id, 'department_id' => $dapur->id]))
            ->assertRedirect(route('employees.index'));

        $karyawan = Employee::sole();
        $this->assertSame('KRY-0001', $karyawan->code);
        $this->assertSame($kasir->id, $karyawan->user_id);
        $this->assertTrue($kasir->employee->is($karyawan));
        $this->get(route('employees.index'))->assertOk()->assertSee('Siti Aminah')->assertSee('Dapur')->assertSee('Kontrak');
    }

    public function test_satu_akun_login_hanya_untuk_satu_karyawan(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);
        Employee::factory()->create(['user_id' => $kasir->id]);

        $this->actingAs($this->hrd)
            ->post(route('employees.store'), $this->payload(['user_id' => $kasir->id]))
            ->assertSessionHasErrors(['user_id' => 'Akun login ini sudah tertaut ke karyawan lain.']);

        $this->assertSame(1, Employee::count());
    }

    public function test_menghapus_karyawan_melepas_akun_login_untuk_dipakai_lagi(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);
        $lama = Employee::factory()->create(['user_id' => $kasir->id]);

        $this->actingAs($this->hrd)->delete(route('employees.destroy', $lama))->assertRedirect(route('employees.index'));
        $this->post(route('employees.store'), $this->payload(['user_id' => $kasir->id]))->assertSessionHasNoErrors();

        $this->assertSoftDeleted($lama);
        $this->assertNull($lama->fresh()->user_id);
        $this->assertSame($kasir->id, Employee::sole()->user_id);
    }

    public function test_tanggal_berhenti_tidak_boleh_sebelum_mulai_kerja(): void
    {
        $this->actingAs($this->hrd)
            ->post(route('employees.store'), $this->payload(['end_date' => '2025-12-31']))
            ->assertSessionHasErrors('end_date');

        $this->assertSame(0, Employee::count());
    }

    public function test_daftar_karyawan_bisa_difilter_per_departemen_dan_status(): void
    {
        $dapur = Department::factory()->create(['name' => 'Dapur']);
        Employee::factory()->create(['name' => 'Budi Dapur', 'department_id' => $dapur->id]);
        Employee::factory()->create(['name' => 'Rina Kasir']);
        Employee::factory()->nonaktif()->create(['name' => 'Andi Keluar', 'department_id' => $dapur->id]);

        $this->actingAs($this->hrd)->get(route('employees.index', ['departemen' => $dapur->id, 'status' => 'aktif']))
            ->assertOk()
            ->assertSee('Budi Dapur')
            ->assertDontSee('Rina Kasir')
            ->assertDontSee('Andi Keluar');
    }

    public function test_departemen_dan_jabatan_bisa_ditambah(): void
    {
        $this->actingAs($this->hrd)->post(route('departments.store'), ['name' => 'Dapur', 'description' => 'Produksi makanan'])->assertSessionHasNoErrors();
        $this->post(route('positions.store'), ['name' => 'Juru Masak'])->assertSessionHasNoErrors();

        $this->get(route('departments.index'))->assertOk()->assertSee('Dapur')->assertSee('Jumlah karyawan')->assertSee(route('positions.index'));
        $this->get(route('positions.index'))->assertOk()->assertSee('Juru Masak');
    }

    public function test_departemen_yang_dipakai_karyawan_tidak_bisa_dihapus(): void
    {
        $dapur = Department::factory()->create(['name' => 'Dapur']);
        Employee::factory()->create(['department_id' => $dapur->id]);

        $this->actingAs($this->hrd)->delete(route('departments.destroy', $dapur))
            ->assertSessionHas('error', 'Departemen Dapur tidak dapat dihapus karena masih dipakai 1 karyawan.');

        $this->assertModelExists($dapur);
    }

    public function test_jabatan_yang_tidak_dipakai_bisa_dihapus(): void
    {
        $jabatan = Position::factory()->create();

        $this->actingAs($this->hrd)->delete(route('positions.destroy', $jabatan))->assertSessionHas('success');

        $this->assertModelMissing($jabatan);
    }

    public function test_admin_hanya_bisa_melihat_dan_kasir_tidak_bisa_membuka_karyawan(): void
    {
        $admin = $this->buatPengguna(RoleName::Admin);

        $this->actingAs($admin)->get(route('employees.index'))->assertOk();
        $this->post(route('employees.store'), $this->payload())->assertForbidden();
        $this->actingAs($this->buatPengguna(RoleName::Kasir))->get(route('employees.index'))->assertForbidden();

        $this->assertSame(0, Employee::count());
    }

    /** @return array<string, array{string}> */
    public static function daftarSdm(): array
    {
        return ['employees.export' => ['employees.export'], 'departments.export' => ['departments.export'], 'positions.export' => ['positions.export']];
    }

    #[DataProvider('daftarSdm')]
    public function test_daftar_sdm_bisa_diekspor_ke_excel_dan_pdf(string $route): void
    {
        $dapur = Department::factory()->create();
        $koki = Position::factory()->create();
        Employee::factory()->create(['department_id' => $dapur->id, 'position_id' => $koki->id]);
        $this->actingAs($this->hrd);

        $this->get(route($route, ['format' => 'xlsx']))->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->get(route($route, ['format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
