<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Pengaturan → Akuntansi: nyala/mati jurnal otomatis. */
class PengaturanAkuntansiTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    public function test_pemilik_bisa_mematikan_jurnal_otomatis(): void
    {
        $this->siapkanPeran();
        $this->actingAs($this->buatPengguna(RoleName::Pemilik))->get(route('settings.accounting.edit'))
            ->assertOk()
            ->assertSee('Bagan akun belum dipasang');

        $this->put(route('settings.accounting.update'), ['auto_journal' => '0'])->assertSessionHasNoErrors();

        $this->assertSame('0', setting('accounting.auto_journal'));
    }

    public function test_akuntan_tidak_bisa_mengubah_pengaturan_jurnal_otomatis(): void
    {
        $this->siapkanPeran();

        $this->actingAs($this->buatPengguna(RoleName::Akuntan))
            ->put(route('settings.accounting.update'), ['auto_journal' => '0'])
            ->assertForbidden();

        $this->assertNull(setting('accounting.auto_journal'));
    }
}
