<?php

namespace Tests\Unit;

use App\Support\Format;
use PHPUnit\Framework\TestCase;

class FormatJumlahTest extends TestCase
{
    public function test_nol_di_belakang_koma_dibuang(): void
    {
        $this->assertSame('10', Format::jumlah('10.000'));
        $this->assertSame('100', Format::jumlah(100));
        $this->assertSame('1.000', Format::jumlah(1000));
        $this->assertSame('1.250', Format::jumlah('1250.000'));
    }

    public function test_desimal_dipertahankan_dengan_koma(): void
    {
        $this->assertSame('2,5', Format::jumlah('2.500'));
        $this->assertSame('0,125', Format::jumlah(0.125));
    }

    public function test_nol_dan_null(): void
    {
        $this->assertSame('0', Format::jumlah(0));
        $this->assertSame('0', Format::jumlah(null));
    }
}
