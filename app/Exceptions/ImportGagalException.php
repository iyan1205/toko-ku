<?php

namespace App\Exceptions;

/** Impor dibatalkan seluruhnya karena ada baris yang tidak valid. */
class ImportGagalException extends AturanBisnisException
{
    /** @param array<int, string> $daftar */
    public function __construct(private readonly array $daftar)
    {
        parent::__construct(
            'Impor dibatalkan: ditemukan ' . count($daftar) . ' kesalahan. Tidak ada data yang disimpan.'
        );
    }

    /** @return array<int, string> */
    public function daftar(): array
    {
        return $this->daftar;
    }
}
