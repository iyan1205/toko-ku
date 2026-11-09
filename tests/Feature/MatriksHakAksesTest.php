<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class MatriksHakAksesTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $super;

    private Role $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->super = $this->buatPengguna(RoleName::SuperAdmin);
        $this->kasir = Role::findByName(RoleName::Kasir->value);
    }

    public function test_hak_akses_peran_diganti_sesuai_centang_dan_tercatat(): void
    {
        $this->actingAs($this->super)
            ->put(route('permissions.update', $this->kasir), ['permissions' => ['pos.transactions.create', 'inventory.products.view', 'pos.customers.view']])
            ->assertRedirect(route('permissions.index', ['peran' => $this->kasir->id]))
            ->assertSessionHas('success');

        $this->assertEqualsCanonicalizing(
            ['pos.transactions.create', 'inventory.products.view', 'pos.customers.view'],
            $this->kasir->fresh()->permissions->pluck('name')->all(),
        );

        $log = Activity::query()->where('event', 'permissions_updated')->sole();
        $this->assertSame(['pos.customers.view'], $log->properties['ditambah']);
        $this->assertContains('pos.transactions.print', $log->properties['dicabut']);
    }

    public function test_tanpa_centang_sama_sekali_semua_hak_akses_dicabut(): void
    {
        $this->actingAs($this->super)->put(route('permissions.update', $this->kasir), []);

        $this->assertSame(0, $this->kasir->fresh()->permissions()->count());
    }

    public function test_izin_di_luar_katalog_ditolak(): void
    {
        $this->actingAs($this->super)
            ->put(route('permissions.update', $this->kasir), ['permissions' => ['izin.palsu.view']])
            ->assertSessionHasErrors('permissions.0');
    }

    public function test_hak_akses_super_admin_tidak_bisa_diubah(): void
    {
        $superAdmin = Role::findByName(RoleName::SuperAdmin->value);

        $this->actingAs($this->buatPengguna(RoleName::Pemilik))
            ->put(route('permissions.update', $superAdmin), ['permissions' => []])
            ->assertForbidden();

        $this->actingAs($this->super)
            ->put(route('permissions.update', $superAdmin), ['permissions' => []])
            ->assertSessionHas('error');
        $this->assertGreaterThan(0, $superAdmin->fresh()->permissions()->count());
    }

    public function test_selain_super_admin_tidak_bisa_memberi_izin_yang_tidak_dimilikinya(): void
    {
        $penyelia = Role::create(['name' => 'Penyelia', 'guard_name' => 'web'])
            ->givePermissionTo(['access.permissions.view', 'access.permissions.update', 'inventory.products.view', 'pos.transactions.create']);
        $aktor = User::factory()->create()->assignRole($penyelia);
        $sebelum = $this->kasir->permissions->pluck('name')->all();

        $this->actingAs($aktor)
            ->put(route('permissions.update', $this->kasir), ['permissions' => [...$sebelum, 'access.users.delete']])
            ->assertSessionHas('error');
        $this->assertFalse($this->kasir->fresh()->hasPermissionTo('access.users.delete'));

        $this->actingAs($aktor)
            ->put(route('permissions.update', $this->kasir), ['permissions' => ['pos.transactions.create']])
            ->assertSessionHas('success');
        $this->assertSame(['pos.transactions.create'], $this->kasir->fresh()->permissions->pluck('name')->all());
    }

    public function test_halaman_matriks_menampilkan_centang_peran_yang_dipilih(): void
    {
        $response = $this->actingAs($this->super)
            ->get(route('permissions.index', ['peran' => $this->kasir->id]))
            ->assertOk()
            ->assertSee('Transaksi penjualan')
            ->assertSee('Segera Hadir');

        $this->assertMatchesRegularExpression('#value="pos\.transactions\.create"[^>]*checked#', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('#value="pos\.transactions\.void"[^>]*checked#', $response->getContent());
    }

    public function test_kasir_tidak_bisa_membuka_matriks_hak_akses(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Kasir))->get(route('permissions.index'))->assertForbidden();
    }
}
