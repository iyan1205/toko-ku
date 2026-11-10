<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Pengaturan → Kasir: apakah kasir wajib membuka sesi sebelum menjual. */
class PengaturanKasirTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    public function test_pemilik_bisa_mewajibkan_sesi_kasir(): void
    {
        $this->siapkanPeran();
        $pemilik = $this->buatPengguna(RoleName::Pemilik);
        $this->actingAs($pemilik)->get(route('settings.pos.edit'))->assertOk();

        $this->put(route('settings.pos.update'), ['require_session' => '1'])->assertSessionHasNoErrors();

        $this->assertSame('1', setting('pos.require_session'));
    }

    public function test_admin_tidak_bisa_mengubah_pengaturan_kasir(): void
    {
        $this->siapkanPeran();
        $admin = $this->buatPengguna(RoleName::Admin);

        $this->actingAs($admin)->put(route('settings.pos.update'), ['require_session' => '1'])->assertForbidden();

        $this->assertNull(setting('pos.require_session'));
    }
}
