<?php

namespace Tests\Unit;

use App\Support\Format;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    public function test_rupiah_memakai_titik_sebagai_pemisah_ribuan(): void
    {
        $this->assertSame('Rp 10.000', Format::rupiah(10000));
        $this->assertSame('Rp 125.000', Format::rupiah(125000));
        $this->assertSame('Rp 1.250.000', Format::rupiah(1250000));
    }

    public function test_rupiah_menerima_string_desimal_dari_database(): void
    {
        $this->assertSame('Rp 125.000', Format::rupiah('125000.00'));
        $this->assertSame('Rp 1.250,50', Format::rupiah('1250.50'));
    }

    public function test_rupiah_menangani_nol_null_dan_negatif(): void
    {
        $this->assertSame('Rp 0', Format::rupiah(0));
        $this->assertSame('Rp 0', Format::rupiah(null));
        $this->assertSame('-Rp 5.000', Format::rupiah(-5000));
    }

    public function test_rupiah_tanpa_simbol(): void
    {
        $this->assertSame('28.000', Format::rupiah(28000, false));
    }

    public function test_tanggal_berformat_dd_mm_yyyy(): void
    {
        $this->assertSame('28-09-2026', Format::tanggal('2026-09-28'));
        $this->assertSame('28-09-2026 14:30', Format::tanggal('2026-09-28 14:30:00', true));
        $this->assertSame('-', Format::tanggal(null));
    }
}
