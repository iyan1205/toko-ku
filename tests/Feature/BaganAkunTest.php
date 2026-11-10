<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Bagan akun: COA standar, aturan akun sistem/induk/terpakai, saldo, hak akses, ekspor. */
class BaganAkunTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $akuntan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->akuntan = $this->buatPengguna(RoleName::Akuntan);
    }

    private function pasang(): void
    {
        app(ChartOfAccountsService::class)->install();
    }

    private function akun(string $kode): Account
    {
        return Account::where('code', $kode)->sole();
    }

    public function test_pasang_coa_standar_dari_halaman(): void
    {
        $this->actingAs($this->akuntan)->get(route('accounts.index'))->assertOk()->assertSee('Pasang COA standar');

        $this->post(route('accounts.install'))->assertRedirect(route('accounts.index'));

        $this->assertSame(43, Account::count());
        $this->assertSame(
            collect(config('accounting.accounts'))->values()->sort()->values()->all(),
            Account::where('is_system', true)->orderBy('code')->pluck('code')->all(),
        );
        $this->assertSame($this->akun('1-1000')->id, $this->akun('1-1100')->parent_id);
    }

    public function test_pasang_ulang_tidak_menimpa_perubahan_pemilik(): void
    {
        $this->pasang();
        $this->akun('1-1100')->update(['name' => 'Kas Laci']);

        $ditambah = app(ChartOfAccountsService::class)->install();

        $this->assertSame(0, $ditambah);
        $this->assertSame('Kas Laci', $this->akun('1-1100')->name);
        $this->assertSame(43, Account::count());
    }

    public function test_akun_baru_harus_satu_kelompok_dengan_induknya(): void
    {
        $this->pasang();

        $this->actingAs($this->akuntan)->post(route('accounts.store'), [
            'code' => '6-1800', 'name' => 'Beban Kemasan', 'type' => 'expense', 'parent_id' => $this->akun('1-1000')->id, 'is_active' => '1',
        ])->assertSessionHasErrors(['parent_id' => 'Akun induk 1-1000 Aset Lancar berada di kelompok Aset, sedangkan akun ini Beban.']);

        $this->post(route('accounts.store'), [
            'code' => '6-1800', 'name' => 'Beban Kemasan', 'type' => 'expense', 'parent_id' => $this->akun('6-0000')->id, 'is_active' => '1',
        ])->assertRedirect(route('accounts.index'));
        $this->assertSame('debit', $this->akun('6-1800')->normal_balance->value);
    }

    public function test_akun_sistem_tidak_bisa_dinonaktifkan_atau_dihapus(): void
    {
        $this->pasang();
        $kas = $this->akun('1-1100');

        $this->actingAs($this->akuntan)->put(route('accounts.update', $kas), ['code' => '1-1100', 'name' => 'Kas', 'type' => 'asset', 'is_active' => '0'])
            ->assertSessionHasErrors(['is_active' => 'Akun sistem tidak bisa dinonaktifkan karena dipakai jurnal otomatis.']);
        $this->delete(route('accounts.destroy', $kas))
            ->assertSessionHas('error', 'Akun 1-1100 Kas dipakai jurnal otomatis dan tidak bisa dihapus.');

        $this->assertTrue($kas->fresh()->is_active);
        $this->assertNotSoftDeleted($kas);
    }

    public function test_akun_yang_sudah_dipakai_jurnal_tidak_bisa_dihapus_dan_saldonya_tampil(): void
    {
        $this->pasang();
        $listrik = $this->akun('6-1200');
        app(JournalService::class)->post(now(), 'Bayar listrik', [
            ['account_id' => $listrik->id, 'debit' => '350000'],
            ['account_id' => $this->akun('1-1100')->id, 'credit' => '350000'],
        ], $this->akuntan);

        $this->actingAs($this->akuntan)->delete(route('accounts.destroy', $listrik))
            ->assertSessionHas('error', 'Akun 6-1200 Beban Listrik, Air & Internet sudah dipakai di jurnal, jadi tidak bisa dihapus. Nonaktifkan saja.');

        $this->assertNotSoftDeleted($listrik);
        $this->get(route('accounts.index', ['cari' => '6-1200']))->assertOk()->assertSee('Rp 350.000');
    }

    public function test_akun_biasa_yang_belum_dipakai_bisa_dihapus(): void
    {
        $this->pasang();
        $akun = $this->akun('6-1700');

        $this->actingAs($this->akuntan)->delete(route('accounts.destroy', $akun))->assertSessionHas('success');

        $this->assertSoftDeleted($akun);
    }

    public function test_kasir_dan_admin_tidak_bisa_membuka_bagan_akun(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Kasir))->get(route('accounts.index'))->assertForbidden();
        $this->actingAs($this->buatPengguna(RoleName::Admin))->post(route('accounts.install'))->assertForbidden();

        $this->assertSame(0, Account::count());
    }

    /** @return array<string, array{string}> */
    public static function daftarAkuntansi(): array
    {
        return ['accounts.export' => ['accounts.export'], 'journals.export' => ['journals.export']];
    }

    #[DataProvider('daftarAkuntansi')]
    public function test_daftar_akuntansi_bisa_diekspor_ke_excel_dan_pdf(string $route): void
    {
        $this->pasang();
        app(JournalService::class)->post(now(), 'Setoran modal', [
            ['account_id' => $this->akun('1-1100')->id, 'debit' => '1000000'],
            ['account_id' => $this->akun('3-1000')->id, 'credit' => '1000000'],
        ], $this->akuntan);
        $this->actingAs($this->akuntan);

        $this->get(route($route, ['format' => 'xlsx']))->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->get(route($route, ['format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
