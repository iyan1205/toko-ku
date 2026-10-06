<?php

namespace App\Services;

use App\Exceptions\AturanBisnisException;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    public function __construct(private readonly KodeService $kode)
    {
    }

    /** @param array<string, mixed> $data */
    public function buat(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            $data['code'] = $this->kode->berikutnya(Customer::class, 'code', 'CUS-', 4);

            return Customer::create($data);
        });
    }

    /** @param array<string, mixed> $data */
    public function perbarui(Customer $pelanggan, array $data): Customer
    {
        $this->tolakBila($pelanggan->is_default, 'Pelanggan "Umum" adalah bawaan sistem dan tidak dapat diubah.');

        $pelanggan->update($data);

        return $pelanggan;
    }

    public function hapus(Customer $pelanggan): void
    {
        $this->tolakBila($pelanggan->is_default, 'Pelanggan "Umum" adalah bawaan sistem dan tidak dapat dihapus.');
        $this->tolakBila(
            (float) $pelanggan->receivable_balance > 0,
            'Pelanggan ini masih memiliki piutang yang belum lunas, sehingga tidak dapat dihapus.'
        );

        $pelanggan->delete();
    }

    private function tolakBila(bool $kondisi, string $pesan): void
    {
        if ($kondisi) {
            throw new AturanBisnisException($pesan);
        }
    }
}
