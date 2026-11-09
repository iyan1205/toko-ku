<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\User;
use App\Services\Settings\TaxSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class PajakTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $pemilik;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->pemilik = $this->buatPengguna(RoleName::Pemilik);
    }

    private function payload(array $ubah = []): array
    {
        return array_merge(['enabled' => '1', 'name' => 'PPN', 'rate' => '11', 'inclusive' => '1'], $ubah);
    }

    public function test_bawaan_pajak_tidak_aktif(): void
    {
        $this->assertSame(
            ['enabled' => false, 'name' => 'PPN', 'rate' => 11.0, 'inclusive' => true],
            app(TaxSettingsService::class)->get(),
        );
    }

    public function test_menyimpan_pengaturan_pajak(): void
    {
        $this->actingAs($this->pemilik)->get(route('settings.taxes.edit'))->assertOk()->assertSee('Pajak penjualan');

        $this->actingAs($this->pemilik)
            ->put(route('settings.taxes.update'), $this->payload(['rate' => '11,5', 'inclusive' => '0']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.taxes.edit'));

        $this->assertSame(
            ['enabled' => true, 'name' => 'PPN', 'rate' => 11.5, 'inclusive' => false],
            app(TaxSettingsService::class)->get(),
        );
        $this->assertSame('11.5', setting('tax.rate'), 'Tarif disimpan dengan titik desimal.');
    }

    public function test_tarif_harus_antara_0_dan_100(): void
    {
        $this->actingAs($this->pemilik)
            ->put(route('settings.taxes.update'), $this->payload(['rate' => '120']))
            ->assertSessionHasErrors(['rate' => 'tarif pajak tidak boleh lebih dari 100.']);

        $this->actingAs($this->pemilik)
            ->put(route('settings.taxes.update'), $this->payload(['rate' => '-1']))
            ->assertSessionHasErrors('rate');
    }

    public function test_nama_pajak_wajib_diisi(): void
    {
        $this->actingAs($this->pemilik)
            ->put(route('settings.taxes.update'), $this->payload(['name' => '']))
            ->assertSessionHasErrors('name');
    }

    public function test_perubahan_tercatat_di_log_aktivitas(): void
    {
        $this->actingAs($this->pemilik)->put(route('settings.taxes.update'), $this->payload(['rate' => '12']));

        $log = Activity::query()->where('description', 'Pengaturan pajak diubah')->sole();
        $this->assertSame(['aktif' => 'tidak', 'tarif' => '11%'], $log->properties['old']);
        $this->assertSame(['aktif' => 'ya', 'tarif' => '12%'], $log->properties['attributes']);
    }

    public function test_admin_tanpa_izin_tidak_bisa_membuka_pengaturan_pajak(): void
    {
        $admin = $this->buatPengguna(RoleName::Admin);

        $this->actingAs($admin)->get(route('settings.taxes.edit'))->assertForbidden();
        $this->actingAs($admin)->put(route('settings.taxes.update'), $this->payload())->assertForbidden();

        $this->assertFalse(app(TaxSettingsService::class)->get()['enabled']);
    }
}
