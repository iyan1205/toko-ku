<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Impor bagan akun dari Excel/CSV lewat kerangka impor (pratinjau → proses). */
class ImporBaganAkunTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $akuntan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->akuntan = $this->buatPengguna(RoleName::Akuntan);
        app(ChartOfAccountsService::class)->install();
    }

    private function unggah(string $csv): ImportBatch
    {
        $this->actingAs($this->akuntan)->post(route('imports.accounts.preview'), ['file' => UploadedFile::fake()->createWithContent('akun.csv', $csv)])
            ->assertSessionHasNoErrors()->assertRedirect();

        return ImportBatch::latest('id')->firstOrFail();
    }

    public function test_akun_induk_di_baris_sebelumnya_dipakai_akun_di_bawahnya(): void
    {
        $batch = $this->unggah("Kode,Nama,Kelompok,Kode Induk,Akun Induk,Saldo Normal,Kas Bank,Aktif,Deskripsi\n"
            ."6-2000,Beban Operasional Lain,Beban,6-0000,Ya,,,,\n"
            ."6-2100,Beban Kebersihan,beban,6-2000,Tidak,,,,Jasa sampah\n"
            ."1-1210,Bank BCA,Aset,1-1000,,,Ya,,\n"
            ."1-1100,Kas Laci,Aset,1-1000,,,Ya,,Laci kasir depan\n");

        $this->assertSame([4, 0], [$batch->valid_rows, $batch->invalid_rows]);
        $this->assertNull(Account::where('code', '6-2100')->first(), 'Pratinjau belum menyimpan.');

        $this->post(route('imports.accounts.commit', $batch))->assertRedirect();

        $kebersihan = Account::where('code', '6-2100')->sole();
        $this->assertSame(Account::where('code', '6-2000')->value('id'), $kebersihan->parent_id);
        $this->assertSame(AccountType::Expense, $kebersihan->type);
        $this->assertSame(NormalBalance::Debit, $kebersihan->normal_balance);
        $this->assertTrue(Account::where('code', '6-2000')->sole()->is_header);
        $this->assertTrue(Account::where('code', '1-1210')->sole()->is_cash_bank);
        $this->assertSame(['Kas Laci', 'Laci kasir depan'], [Account::where('code', '1-1100')->value('name'), Account::where('code', '1-1100')->value('description')]);
        $this->assertSame([3, 1], [$batch->fresh()->created_count, $batch->fresh()->updated_count]);
    }

    public function test_baris_yang_melanggar_aturan_bagan_akun_dilewati(): void
    {
        $batch = $this->unggah("Kode,Nama,Kelompok,Kode Induk,Akun Induk,Kas Bank,Aktif\n"
            ."1-1100,Kas,Beban,,,,\n"                     // akun sistem: kelompok tidak boleh diganti
            ."4-1000,Penjualan,Pendapatan,,,,Tidak\n"      // akun sistem: tidak boleh nonaktif
            ."6-3000,Beban X,Beban,1-1000,,,\n"           // induk beda kelompok
            ."6-3100,Beban Y,Beban,6-9999,,,\n"           // induk tidak ada
            ."2-5000,Hutang X,Liabilitas,2-1000,,Ya,\n"   // kas/bank hanya aset
            ."7-0000,Lain,Entah,,,,\n"                    // kelompok tidak dikenal
            ."1-1200,Bank,Aset,1-1000,,Tidak,\n");        // dipetakan sebagai kas/bank

        $this->assertSame([0, 7], [$batch->valid_rows, $batch->invalid_rows]);
        $errors = collect($batch->rows)->keyBy('line')->map(fn ($r) => implode(' ', $r['errors']));
        $this->assertStringContainsString('kelompoknya tidak bisa diganti', $errors[2]);
        $this->assertStringContainsString('tidak bisa dinonaktifkan', $errors[3]);
        $this->assertStringContainsString('berkelompok Aset, sedangkan akun ini Beban', $errors[4]);
        $this->assertStringContainsString('Kode induk 6-9999 tidak ditemukan', $errors[5]);
        $this->assertStringContainsString('Kas Bank hanya untuk akun Aset', $errors[6]);
        $this->assertStringContainsString('Kelompok tidak dikenal', $errors[7]);
        $this->assertStringContainsString('Kas Bank harus Ya', $errors[8]);
    }

    public function test_halaman_bagan_akun_berupa_pohon_dengan_tombol_impor(): void
    {
        $this->actingAs($this->akuntan)->get(route('accounts.index'))->assertOk()
            ->assertSee(route('imports.accounts.create'))
            ->assertSee('Ciutkan semua')
            ->assertSeeInOrder(['Aset', 'Aset Lancar', 'Kas', 'Bank'])
            ->assertSee('Kas/bank');

        $this->get(route('accounts.index', ['cari' => 'Diskon']))->assertOk()
            ->assertSee('Diskon Penjualan')->assertSee('Pendapatan')->assertDontSee('Persediaan Barang Dagang');

        $this->get(route('imports.accounts.template'))->assertOk();
    }

    public function test_akun_standar_baru_ditawarkan_untuk_coa_lama(): void
    {
        Account::where('code', '4-1200')->forceDelete();

        $this->actingAs($this->akuntan)->get(route('accounts.index'))->assertOk()
            ->assertSee('Ada 1 akun standar baru')->assertSee('4-1200 Diskon Penjualan');

        $this->post(route('accounts.install'))->assertRedirect();
        $this->assertTrue(Account::where('code', '4-1200')->sole()->is_system, 'Akun baru langsung dipakai jurnal otomatis.');
    }
}
