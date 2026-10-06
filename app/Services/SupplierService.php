<?php

namespace App\Services;

use App\Exceptions\AturanBisnisException;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

class SupplierService
{
    public function __construct(private readonly KodeService $kode)
    {
    }

    /** @param array<string, mixed> $data */
    public function buat(array $data): Supplier
    {
        return DB::transaction(function () use ($data) {
            $data['code'] = $this->kode->berikutnya(Supplier::class, 'code', 'SUP-', 4);

            return Supplier::create($data);
        });
    }

    /** @param array<string, mixed> $data */
    public function perbarui(Supplier $supplier, array $data): Supplier
    {
        $supplier->update($data);

        return $supplier;
    }

    public function hapus(Supplier $supplier): void
    {
        $masihBerhutang = $supplier->purchases()->selesai()->where('balance', '>', 0)->exists();

        if ($masihBerhutang) {
            throw new AturanBisnisException('Supplier ini masih memiliki hutang yang belum lunas, sehingga tidak dapat dihapus.');
        }

        $supplier->delete();
    }
}
