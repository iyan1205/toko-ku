<?php

namespace App\Services\Accounting;

use App\Exceptions\AturanBisnisException;
use App\Models\CostCenter;
use App\Models\RecurringJournalLine;

/** Cost center yang sudah dipakai jurnal atau templat jurnal berulang tidak dihapus, cukup dinonaktifkan. */
class CostCenterService
{
    /** @param array<string, mixed> $data */
    public function create(array $data): CostCenter
    {
        return CostCenter::create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(CostCenter $costCenter, array $data): CostCenter
    {
        $costCenter->update($data);

        return $costCenter;
    }

    public function delete(CostCenter $costCenter): void
    {
        $dipakai = $costCenter->lines()->exists()
            || RecurringJournalLine::query()->where('cost_center_id', $costCenter->id)->exists();

        if ($dipakai) {
            throw new AturanBisnisException("Cost center {$costCenter->label()} sudah dipakai di jurnal, jadi tidak bisa dihapus. Nonaktifkan saja.");
        }

        $costCenter->delete();
    }
}
