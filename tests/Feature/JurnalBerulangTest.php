<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Journal;
use App\Models\RecurringJournal;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Accounting\RecurringJournalService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Jurnal berulang (templat bulanan via scheduler) dan jurnal yang dibalik otomatis. */
class JurnalBerulangTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $akuntan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->akuntan = $this->buatPengguna(RoleName::Akuntan);
        app(ChartOfAccountsService::class)->install();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00'));
    }

    private function akun(string $kode): int
    {
        return Account::where('code', $kode)->value('id');
    }

    /** Sewa 3.000.000: Beban Sewa (D) / Bank (K). @return array<string, mixed> */
    private function payload(array $ubah = []): array
    {
        return array_merge([
            'name' => 'Sewa ruko',
            'description' => 'Beban sewa ruko',
            'interval_months' => 1,
            'day_of_month' => 31,
            'start_on' => '2026-08-01',
            'end_on' => '',
            'auto_post' => '1',
            'is_active' => '1',
            'lines' => [
                ['account_id' => $this->akun('6-1100'), 'debit' => '3000000', 'credit' => ''],
                ['account_id' => $this->akun('1-1200'), 'debit' => '', 'credit' => '3000000'],
            ],
        ], $ubah);
    }

    private function templat(array $ubah = []): RecurringJournal
    {
        $this->actingAs($this->akuntan)->post(route('recurring-journals.store'), $this->payload($ubah))->assertSessionHasNoErrors();

        return RecurringJournal::latest('id')->firstOrFail();
    }

    public function test_jadwal_tanggal_terakhir_mengikuti_panjang_bulan(): void
    {
        $templat = $this->templat(['start_on' => '2027-01-15']);
        $service = app(RecurringJournalService::class);

        $this->assertSame('2027-01-31', $templat->next_run_on->toDateString());
        $this->assertSame('2027-02-28', $service->nextAfter($templat, CarbonImmutable::parse('2027-01-31'))->toDateString());
        $this->assertSame('2027-03-31', $service->nextAfter($templat, CarbonImmutable::parse('2027-02-28'))->toDateString());

        $templat->forceFill(['day_of_month' => 10, 'interval_months' => 3, 'end_on' => '2027-06-30'])->save();
        $this->assertSame('2027-04-10', $service->nextAfter($templat, CarbonImmutable::parse('2027-01-10'))->toDateString());
        $this->assertNull($service->nextAfter($templat, CarbonImmutable::parse('2027-04-10')), 'Jadwal setelah tanggal selesai tidak dibuat.');
    }

    public function test_scheduler_membuat_jadwal_yang_terlewat_sekali_per_periode(): void
    {
        $templat = $this->templat();

        Artisan::call('akuntansi:jurnal-terjadwal');
        Artisan::call('akuntansi:jurnal-terjadwal');

        // 31 Agu, 30 Sep, 31 Okt sudah lewat; 30 Nov belum.
        $jurnal = Journal::where('recurring_journal_id', $templat->id)->orderBy('journal_date')->get();
        $this->assertSame(['2026-08', '2026-09', '2026-10'], $jurnal->pluck('recurring_period')->all());
        $this->assertSame(['2026-08-31', '2026-09-30', '2026-10-31'], $jurnal->map(fn ($j) => $j->journal_date->toDateString())->all());
        $this->assertTrue($jurnal->every(fn ($j) => $j->isPosted() && $j->user_id === $this->akuntan->id && $j->total == 3000000));
        $this->assertSame('Beban sewa ruko (Oktober 2026)', $jurnal->last()->description);
        $this->assertSame('2026-11-30', $templat->fresh()->next_run_on->toDateString());

        $this->actingAs($this->akuntan)->get(route('journals.show', $jurnal->first()))->assertOk()->assertSee('Sewa ruko')->assertSee('Jurnal berulang');
    }

    public function test_templat_draf_membuat_draf_yang_belum_masuk_saldo(): void
    {
        $templat = $this->templat(['auto_post' => '0', 'start_on' => '2026-11-01', 'day_of_month' => 5]);

        $this->actingAs($this->akuntan)->post(route('recurring-journals.run', $templat))->assertSessionHas('success');

        $jurnal = Journal::where('recurring_journal_id', $templat->id)->sole();
        $this->assertSame(DocumentStatus::Draft, $jurnal->documentStatus());
        $this->assertSame('2026-11-05', $jurnal->journal_date->toDateString());
    }

    public function test_periode_terkunci_dicatat_sebagai_kegagalan_tanpa_menghentikan_templat_lain(): void
    {
        app(FiscalPeriodService::class)->close(2026, 10, $this->buatPengguna(RoleName::Pemilik));
        $gagal = $this->templat(['start_on' => '2026-10-01', 'day_of_month' => 15]);
        $lancar = $this->templat(['name' => 'Internet', 'start_on' => '2026-11-01', 'day_of_month' => 1]);

        Artisan::call('akuntansi:jurnal-terjadwal');

        $this->assertStringContainsString('2026-10: Periode Oktober 2026 sudah ditutup', $gagal->fresh()->last_error);
        $this->assertSame('2026-10-15', $gagal->fresh()->next_run_on->toDateString(), 'Jadwal tidak dilompati.');
        $this->assertSame(0, Journal::where('recurring_journal_id', $gagal->id)->count());
        $this->assertSame(1, Journal::where('recurring_journal_id', $lancar->id)->count());

        $this->actingAs($this->akuntan)->get(route('recurring-journals.index'))->assertOk()->assertSee('Gagal');
    }

    public function test_jurnal_dibalik_otomatis_pada_tanggal_yang_dijadwalkan(): void
    {
        $this->actingAs($this->akuntan)->post(route('journals.store'), [
            'aksi' => 'post',
            'journal_date' => '2026-11-10',
            'auto_reverse_on' => '2026-12-01',
            'description' => 'Akrual listrik November',
            'lines' => [
                ['account_id' => $this->akun('6-1200'), 'debit' => '450000', 'credit' => ''],
                ['account_id' => $this->akun('2-1900'), 'debit' => '', 'credit' => '450000'],
            ],
        ])->assertSessionHasNoErrors();
        $akrual = Journal::sole();

        Artisan::call('akuntansi:jurnal-terjadwal');
        $this->assertNull($akrual->reversal()->first(), 'Belum waktunya dibalik.');

        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:20'));
        Artisan::call('akuntansi:jurnal-terjadwal');
        Artisan::call('akuntansi:jurnal-terjadwal');

        $pembalik = $akrual->reversal()->sole();
        $this->assertSame('2026-12-01', $pembalik->journal_date->toDateString());
        $this->assertSame(1, Journal::whereNotNull('reversal_of_id')->count());
        $this->assertSame(['450000.00', '450000.00'], [(string) $pembalik->lines->firstWhere('account_id', $this->akun('2-1900'))->debit, (string) $pembalik->total]);
    }

    public function test_tanggal_balik_otomatis_harus_setelah_tanggal_jurnal(): void
    {
        $this->actingAs($this->akuntan)->post(route('journals.store'), [
            'journal_date' => '2026-11-10', 'auto_reverse_on' => '2026-11-10', 'description' => 'Uji',
            'lines' => [
                ['account_id' => $this->akun('6-1200'), 'debit' => '1000', 'credit' => ''],
                ['account_id' => $this->akun('2-1900'), 'debit' => '', 'credit' => '1000'],
            ],
        ])->assertSessionHasErrors('auto_reverse_on');
    }
}
