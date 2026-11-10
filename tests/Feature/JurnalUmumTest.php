<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Journal;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Jurnal manual: debit = kredit, aturan baris & akun, jurnal pembalik, dan hak akses. */
class JurnalUmumTest extends TestCase
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

    private function akun(string $kode): int
    {
        return Account::where('code', $kode)->value('id');
    }

    /** Setoran modal: Kas (D) / Modal Pemilik (K). */
    private function payload(string $debit = '1000000', string $kredit = '1000000', array $ubah = []): array
    {
        return array_merge([
            'journal_date' => now()->format('Y-m-d'),
            'description' => 'Setoran modal awal',
            'lines' => [
                ['account_id' => $this->akun('1-1100'), 'debit' => $debit, 'credit' => ''],
                ['account_id' => $this->akun('3-1000'), 'debit' => '', 'credit' => $kredit],
            ],
        ], $ubah);
    }

    public function test_jurnal_manual_seimbang_tersimpan(): void
    {
        $response = $this->actingAs($this->akuntan)->post(route('journals.store'), $this->payload());

        $journal = Journal::sole();
        $response->assertRedirect(route('journals.show', $journal));
        $this->assertSame('JU-'.now()->format('Ymd').'-0001', $journal->number);
        $this->assertSame('1000000.00', $journal->total);
        $this->assertNull($journal->source_type);
        $this->assertSame([['1000000.00', '0.00'], ['0.00', '1000000.00']], $journal->lines->map(fn ($l) => [$l->debit, $l->credit])->all());
        $this->get(route('journals.show', $journal))->assertOk()->assertSee('Setoran modal awal')->assertSee('Modal Pemilik');
    }

    public function test_jurnal_tidak_seimbang_ditolak_dengan_selisihnya(): void
    {
        $this->actingAs($this->akuntan)
            ->post(route('journals.store'), $this->payload('1000000', '900000'))
            ->assertSessionHasErrors(['lines' => 'Jurnal tidak seimbang: debit Rp 1.000.000, kredit Rp 900.000 (selisih Rp 100.000).']);

        $this->assertSame(0, Journal::count());
    }

    public function test_satu_baris_tidak_boleh_berisi_debit_dan_kredit_sekaligus(): void
    {
        $payload = $this->payload();
        $payload['lines'][0]['credit'] = '5000';

        $this->actingAs($this->akuntan)->post(route('journals.store'), $payload)
            ->assertSessionHasErrors(['lines.0.debit' => 'Isi debit atau kredit saja, tidak boleh keduanya dalam satu baris.']);

        $this->assertSame(0, Journal::count());
    }

    public function test_akun_induk_dan_akun_nonaktif_tidak_bisa_dipakai(): void
    {
        Account::where('code', '6-1900')->update(['is_active' => false]);
        $payload = $this->payload();
        $payload['lines'][0]['account_id'] = $this->akun('1-1000');
        $payload['lines'][1]['account_id'] = $this->akun('6-1900');

        $this->actingAs($this->akuntan)->post(route('journals.store'), $payload)->assertSessionHasErrors([
            'lines.0.account_id' => 'Akun 1-1000 Aset Lancar adalah akun induk dan tidak bisa dipakai di jurnal.',
            'lines.1.account_id' => 'Akun 6-1900 Beban Lain-lain tidak aktif.',
        ]);
    }

    public function test_jurnal_dengan_satu_baris_bernilai_ditolak(): void
    {
        $this->expectException(ValidationException::class);

        try {
            app(JournalService::class)->post(now(), 'Satu sisi', [
                ['account_id' => $this->akun('1-1100'), 'debit' => '1000'],
                ['account_id' => $this->akun('3-1000'), 'debit' => '0', 'credit' => '0'],
            ], $this->akuntan);
        } catch (ValidationException $e) {
            $this->assertSame(['lines' => ['Jurnal minimal berisi dua baris bernilai.']], $e->errors());

            throw $e;
        }
    }

    public function test_jurnal_manual_bisa_dibalik_satu_kali(): void
    {
        $this->actingAs($this->akuntan)->post(route('journals.store'), $this->payload());
        $journal = Journal::sole();

        $this->post(route('journals.reverse', $journal), ['alasan' => 'Salah akun'])->assertSessionHasNoErrors();
        $this->post(route('journals.reverse', $journal), ['alasan' => 'Salah akun lagi'])->assertSessionHas('error');

        $pembalik = Journal::where('reversal_of_id', $journal->id)->sole();
        $this->assertSame([['0.00', '1000000.00'], ['1000000.00', '0.00']], $pembalik->lines->map(fn ($l) => [$l->debit, $l->credit])->all());
        $this->assertSame(2, Journal::count());
    }

    public function test_tanggal_jurnal_tidak_boleh_di_masa_depan(): void
    {
        $this->actingAs($this->akuntan)
            ->post(route('journals.store'), $this->payload(ubah: ['journal_date' => now()->addDay()->format('Y-m-d')]))
            ->assertSessionHasErrors(['journal_date' => 'Tanggal jurnal tidak boleh lebih dari hari ini.']);
    }

    public function test_daftar_jurnal_bisa_difilter_per_akun(): void
    {
        $this->actingAs($this->akuntan)->post(route('journals.store'), $this->payload());
        $this->post(route('journals.store'), [
            'journal_date' => now()->format('Y-m-d'), 'description' => 'Bayar listrik bulan ini',
            'lines' => [
                ['account_id' => $this->akun('6-1200'), 'debit' => '350000'],
                ['account_id' => $this->akun('1-1100'), 'credit' => '350000'],
            ],
        ]);

        $this->get(route('journals.index', ['akun' => $this->akun('6-1200')]))
            ->assertOk()
            ->assertSee('Bayar listrik bulan ini')
            ->assertDontSee('Setoran modal awal');
    }

    public function test_kasir_dan_admin_tidak_bisa_membuka_jurnal(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Kasir))->get(route('journals.index'))->assertForbidden();
        $this->actingAs($this->buatPengguna(RoleName::Admin))->post(route('journals.store'), $this->payload())->assertForbidden();

        $this->assertSame(0, Journal::count());
    }
}
