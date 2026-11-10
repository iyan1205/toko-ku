<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\RoleName;
use App\Exceptions\AturanBisnisException;
use App\Exports\Accounting\AccountsExport;
use App\Models\Account;
use App\Models\Journal;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** HasDocumentStatus pada jurnal manual: draf, posting, pembatalan, nomor, dan kunci isi dokumen posted. */
class StatusDokumenTest extends TestCase
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

    /** @return list<array<string, mixed>> */
    private function baris(int $nilai = 250000): array
    {
        return [
            ['account_id' => Account::where('code', '1-1100')->value('id'), 'debit' => $nilai],
            ['account_id' => Account::where('code', '3-1000')->value('id'), 'credit' => $nilai],
        ];
    }

    private function saldoKas(): float
    {
        $kas = Account::query()->where('code', '1-1100')
            ->withSum(['lines as debit_total' => fn ($q) => $q->whereHas('journal', fn ($j) => $j->where('status', 'posted'))], 'debit')
            ->withSum(['lines as credit_total' => fn ($q) => $q->whereHas('journal', fn ($j) => $j->where('status', 'posted'))], 'credit')
            ->sole();

        return AccountsExport::balance($kas);
    }

    public function test_draf_bernomor_tidak_masuk_saldo_sampai_diposting(): void
    {
        $this->actingAs($this->akuntan)->post(route('journals.store'), [
            'aksi' => 'draft',
            'journal_date' => now()->toDateString(),
            'description' => 'Setoran modal',
            'lines' => $this->baris(),
        ])->assertSessionHas('success');

        $draf = Journal::sole();
        $this->assertSame(DocumentStatus::Draft, $draf->documentStatus());
        $this->assertSame('JU-'.now()->format('Ymd').'-0001', $draf->number);
        $this->assertSame(0.0, $this->saldoKas());

        $this->get(route('journals.edit', $draf))->assertOk()->assertSee('Ubah draf');
        $this->put(route('journals.update', $draf), [
            'aksi' => 'draft', 'journal_date' => now()->toDateString(), 'description' => 'Setoran modal (revisi)', 'lines' => $this->baris(300000),
        ])->assertSessionHas('success');
        $this->assertSame('300000.00', $draf->fresh()->total);

        $this->post(route('journals.post', $draf))->assertSessionHas('success', "Jurnal {$draf->number} diposting.");
        $draf->refresh();
        $this->assertSame(DocumentStatus::Posted, $draf->documentStatus());
        $this->assertSame($this->akuntan->id, $draf->posted_by);
        $this->assertSame(300000.0, $this->saldoKas());

        $this->get(route('journals.edit', $draf))->assertRedirect(route('journals.show', $draf));
    }

    public function test_dokumen_posted_terkunci_dan_hanya_draf_yang_bisa_dihapus(): void
    {
        $posted = app(JournalService::class)->post(now(), 'Modal', $this->baris(), $this->akuntan);

        try {
            $posted->update(['description' => 'Diubah diam-diam']);
            $this->fail('Jurnal posted seharusnya tidak bisa diubah.');
        } catch (AturanBisnisException $e) {
            $this->assertStringContainsString('sudah Diposting dan tidak bisa diubah', $e->getMessage());
        }

        $this->expectException(AturanBisnisException::class);
        $this->expectExceptionMessage('Hanya draf yang bisa dihapus.');
        $posted->delete();
    }

    public function test_perpindahan_status_yang_tidak_sah_ditolak(): void
    {
        $draf = app(JournalService::class)->saveDraft(now(), 'Draf', $this->baris(), $this->akuntan);
        $batal = app(JournalService::class)->cancelDraft($draf, $this->akuntan, 'Salah akun');
        $this->assertSame(DocumentStatus::Cancelled, $batal->documentStatus());
        $this->assertSame('Salah akun', $batal->cancel_reason);

        $this->expectException(AturanBisnisException::class);
        $this->expectExceptionMessage('berstatus Dibatalkan dan tidak bisa menjadi Diposting');
        $batal->moveToStatus(DocumentStatus::Posted, $this->akuntan);
    }

    public function test_jurnal_yang_belum_diposting_tidak_bisa_dibalik(): void
    {
        $draf = app(JournalService::class)->saveDraft(now(), 'Draf', $this->baris(), $this->akuntan);

        $this->actingAs($this->akuntan)->post(route('journals.reverse', $draf), ['alasan' => 'Coba balik'])
            ->assertSessionHas('error', "Jurnal {$draf->number} belum diposting. Draf yang tidak jadi dipakai cukup dibatalkan.");
        $this->assertSame(1, Journal::count());
    }
}
