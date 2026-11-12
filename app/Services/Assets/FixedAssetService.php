<?php

namespace App\Services\Assets;

use App\Exceptions\AturanBisnisException;
use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\CashBankAccount;
use App\Models\FixedAsset;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\Accounting\AccountMappingService;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Accounting\JournalLines;
use App\Services\Accounting\JournalService;
use App\Services\KodeService;
use App\Support\Format;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pencatatan, pengubahan, penghapusan, dan pelepasan aset tetap.
 *
 *   perolehan : D Aset (harga perolehan) | K Akumulasi (akumulasi awal aset lama), K akun kredit (sisanya)
 *               akun kredit = rekening kas/bank, hutang, atau Ekuitas Saldo Awal untuk aset lama.
 *               Tanggal jurnal = tanggal perolehan, atau sehari sebelum mulai disusutkan bila ada akumulasi awal.
 *   pelepasan : D Akumulasi, D rekening (harga jual), D Rugi / K Laba | K Aset (harga perolehan)
 *
 * Nilai keuangan (harga, umur, akun) tidak diubah setelah dicatat; aset yang belum disusutkan bisa
 * dihapus (jurnal perolehannya dibalik) lalu dicatat ulang.
 */
class FixedAssetService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly AccountMappingService $mapping,
        private readonly DepreciationCalculator $calculator,
        private readonly KodeService $codes,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $user): FixedAsset
    {
        $category = AssetCategory::query()->active()->find((int) ($data['asset_category_id'] ?? 0));
        if ($category === null) {
            throw ValidationException::withMessages(['asset_category_id' => 'Pilih kategori aset yang aktif.']);
        }

        $cost = Uang::sen($data['acquisition_cost'] ?? 0);
        $residual = Uang::sen($data['residual_value'] ?? 0);
        $opening = Uang::sen($data['opening_accumulated'] ?? 0);
        $life = (int) ($data['useful_life_months'] ?? $category->useful_life_months);
        $acquired = CarbonImmutable::parse($data['acquisition_date']);
        $start = CarbonImmutable::parse($data['depreciation_start'] ?? $acquired)->startOfMonth();
        $credit = Account::query()->find((int) ($data['credit_account_id'] ?? 0));

        $errors = array_filter([
            'acquisition_cost' => $cost <= 0 ? 'Harga perolehan harus lebih dari nol.' : null,
            'residual_value' => $residual < 0 || $residual >= $cost ? 'Nilai sisa harus di bawah harga perolehan.' : null,
            'opening_accumulated' => $opening < 0 || $opening > $cost - $residual ? 'Akumulasi awal tidak boleh melebihi harga perolehan dikurangi nilai sisa.' : null,
            'useful_life_months' => $life < 1 || $life > 600 ? 'Umur ekonomis 1–600 bulan.' : null,
            'depreciation_start' => $start->lt($acquired->startOfMonth()) ? 'Mulai disusutkan tidak boleh sebelum bulan perolehan.' : null,
            'credit_account_id' => $credit === null || ! $credit->isPostable() || $credit->id === $category->asset_account_id ? 'Pilih akun sumber dana yang aktif.' : null,
        ]);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $journalDate = $opening > 0 ? $start->subDay() : $acquired;
        app(FiscalPeriodService::class)->ensureOpen($journalDate);

        return DB::transaction(function () use ($data, $category, $cost, $residual, $opening, $life, $acquired, $start, $credit, $journalDate, $user) {
            $asset = new FixedAsset([
                'name' => trim($data['name']),
                'asset_category_id' => $category->id,
                'acquisition_date' => $acquired->toDateString(),
                'acquisition_cost' => Uang::keDesimal($cost),
                'residual_value' => Uang::keDesimal($residual),
                'useful_life_months' => $life,
                'method' => $category->method,
                'declining_rate' => $category->declining_rate,
                'depreciation_start' => $start->toDateString(),
                'opening_accumulated' => Uang::keDesimal($opening),
                'credit_account_id' => $credit->id,
            ] + $this->descriptive($data));
            $asset->forceFill([
                'code' => $this->codes->berikutnya(FixedAsset::class, 'code', 'AST-', 4),
                'accumulated_depreciation' => Uang::keDesimal($opening),
                'status' => FixedAsset::ACTIVE,
            ]);
            if (! empty($data['branch_id'])) {
                $asset->forceFill(['branch_id' => (int) $data['branch_id']]);
            }
            $asset->save();

            $lines = (new JournalLines)
                ->debit($category->assetAccount, $cost, "Perolehan {$asset->name}")
                ->credit($category->accumulatedAccount, $opening, 'Akumulasi penyusutan sebelum dicatat')
                ->credit($credit, $cost - $opening, "Perolehan {$asset->name}");
            $this->journals->post($journalDate, "Perolehan aset {$asset->code} · {$asset->name}", $this->withCostCenter($lines->all(), $asset), $user, $asset, 'acquired',
                attributes: ['branch_id' => $asset->branch_id]);

            activity('akuntansi')->performedOn($asset)->causedBy($user)->event('created')
                ->withProperties(['harga' => $asset->acquisition_cost, 'kategori' => $category->name])
                ->log("Aset {$asset->code} {$asset->name} dicatat");

            return $asset;
        });
    }

    /** Hanya data keterangan yang bisa diubah (nama, lokasi, penanggung jawab, cost center, nomor seri). @param array<string, mixed> $data */
    public function update(FixedAsset $asset, array $data): FixedAsset
    {
        $asset->update(['name' => trim($data['name'])] + $this->descriptive($data));

        return $asset;
    }

    /** Hapus aset yang belum disusutkan dan belum dilepas: jurnal perolehannya dibalik. */
    public function delete(FixedAsset $asset, User $user, string $reason): void
    {
        DB::transaction(function () use ($asset, $user, $reason) {
            $locked = $this->lock($asset);
            if ($locked->isDisposed() || $locked->depreciations()->exists()) {
                throw new AturanBisnisException("Aset {$locked->code} sudah disusutkan atau dilepas, jadi tidak bisa dihapus. Lepaskan aset bila sudah tidak dipakai.");
            }

            if ($journal = $this->journals->find($locked, 'acquired')) {
                $this->journals->reverse($journal, "Hapus aset {$locked->code}: {$reason}", $user, $locked, 'deleted');
            }
            $locked->delete();

            activity('akuntansi')->performedOn($locked)->causedBy($user)->event('deleted')
                ->withProperties(['alasan' => $reason])->log("Aset {$locked->code} {$locked->name} dihapus");
        });
    }

    /**
     * Lepas aset: dijual (dengan harga dan rekening penerima) atau dihapus/rusak. Penyusutan harus
     * sudah dihitung sampai bulan sebelum tanggal pelepasan.
     *
     * @param  array<string, mixed>  $data  disposed_at, disposal_type (sold|scrapped), disposal_amount, disposal_cash_bank_account_id, disposal_note
     */
    public function dispose(FixedAsset $asset, array $data, User $user): FixedAsset
    {
        $date = CarbonImmutable::parse($data['disposed_at']);
        $sold = ($data['disposal_type'] ?? 'scrapped') === 'sold';
        $amount = $sold ? Uang::sen($data['disposal_amount'] ?? 0) : 0;
        app(FiscalPeriodService::class)->ensureOpen($date);

        return DB::transaction(function () use ($asset, $data, $date, $sold, $amount, $user) {
            $locked = $this->lock($asset);
            if ($locked->isDisposed()) {
                throw new AturanBisnisException("Aset {$locked->code} sudah dilepas.");
            }
            if ($date->lt(CarbonImmutable::parse($locked->acquisition_date))) {
                throw ValidationException::withMessages(['disposed_at' => 'Tanggal pelepasan tidak boleh sebelum tanggal perolehan.']);
            }

            $before = $date->startOfMonth()->subMonthNoOverflow()->format('Y-m');
            if ($this->calculator->schedule($locked, $before) !== []) {
                throw new AturanBisnisException('Hitung penyusutan sampai '.Format::bulan($before.'-01')." dulu (Aset Tetap → Penyusutan), lalu lepaskan {$locked->code}.");
            }
            if ($locked->depreciations()->where('period', '>=', $date->format('Y-m'))->exists()) {
                throw new AturanBisnisException("Aset {$locked->code} sudah disusutkan untuk bulan pelepasan atau sesudahnya. Batalkan penyusutan itu dulu.");
            }

            $rekening = null;
            if ($sold) {
                $rekening = CashBankAccount::query()->available()->active()->find((int) ($data['disposal_cash_bank_account_id'] ?? 0));
                if ($rekening === null || $amount <= 0) {
                    throw ValidationException::withMessages(['disposal_amount' => 'Isi harga jual dan rekening penerima.']);
                }
            }

            $category = $locked->category;
            $cost = Uang::sen($locked->acquisition_cost);
            $accumulated = Uang::sen($locked->accumulated_depreciation);
            $result = $amount - ($cost - $accumulated);
            $lines = (new JournalLines)
                ->debit($category->accumulatedAccount, $accumulated, 'Akumulasi penyusutan')
                ->debit($rekening?->account ?? $category->accumulatedAccount, $amount, 'Harga jual')
                ->debit($this->mapping->require('asset_disposal_loss'), max(0, -$result), 'Rugi pelepasan')
                ->credit($this->mapping->require('asset_disposal_gain'), max(0, $result), 'Laba pelepasan')
                ->credit($category->assetAccount, $cost, "Pelepasan {$locked->name}");

            $locked->forceFill([
                'status' => FixedAsset::DISPOSED,
                'disposed_at' => $date->toDateString(),
                'disposal_type' => $sold ? 'sold' : 'scrapped',
                'disposal_amount' => Uang::keDesimal($amount),
                'disposal_cash_bank_account_id' => $rekening?->id,
                'disposal_note' => $data['disposal_note'] ?? null,
            ])->save();

            $this->journals->post($date, ($sold ? 'Penjualan' : 'Penghapusan')." aset {$locked->code} · {$locked->name}", $this->withCostCenter($lines->all(), $locked), $user, $locked, 'disposed',
                attributes: ['branch_id' => $locked->branch_id]);

            activity('akuntansi')->performedOn($locked)->causedBy($user)->event('disposed')
                ->withProperties(['jenis' => $sold ? 'dijual' : 'dihapus', 'harga_jual' => Uang::keDesimal($amount), 'laba_rugi' => Uang::keDesimal($result)])
                ->log("Aset {$locked->code} {$locked->name} ".($sold ? 'dijual' : 'dihapus'));

            return $locked;
        });
    }

    /** Hitung ulang akumulasi (akumulasi awal + penyusutan yang tercatat). */
    public function refreshAccumulated(FixedAsset $asset): void
    {
        $sum = Uang::sen($asset->opening_accumulated) + $asset->depreciations()->get()->sum(fn ($d) => Uang::sen($d->amount));
        $asset->forceFill(['accumulated_depreciation' => Uang::keDesimal($sum)])->save();
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function descriptive(array $data): array
    {
        return [
            'location' => $data['location'] ?? null,
            'employee_id' => ($data['employee_id'] ?? null) ?: null,
            'holder_name' => $data['holder_name'] ?? null,
            'cost_center_id' => ($data['cost_center_id'] ?? null) ?: null,
            'serial_number' => $data['serial_number'] ?? null,
            'description' => $data['description'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function withCostCenter(array $lines, FixedAsset $asset): array
    {
        return array_map(fn (array $line) => $line + ['cost_center_id' => $asset->cost_center_id], $lines);
    }

    private function lock(FixedAsset $asset): FixedAsset
    {
        return FixedAsset::withoutGlobalScope(BranchScope::class)->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();
    }
}
