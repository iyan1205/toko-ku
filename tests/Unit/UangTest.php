<?php

namespace Tests\Unit;

use App\Support\Uang;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class UangTest extends TestCase
{
    public function test_parse_ke_sen_dari_berbagai_tipe(): void
    {
        $this->assertSame(1250050, Uang::sen('12500.50'));
        $this->assertSame(800000, Uang::sen(8000));
        $this->assertSame(800050, Uang::sen(8000.5));
        $this->assertSame(0, Uang::sen(null));
        $this->assertSame(9550000, Uang::sen('95500.00'));
        $this->assertSame(50, Uang::sen('0.5'));
        $this->assertSame(-525, Uang::sen('-5.25'));
        $this->assertSame(1050, Uang::sen('10.500'), 'Nol di luar skala boleh dibuang.');
    }

    public function test_angka_melebihi_skala_atau_bukan_angka_ditolak_bukan_dibulatkan_diam_diam(): void
    {
        foreach (['10.555', 'Rp 8.000', 'abc', '1,5'] as $buruk) {
            try {
                Uang::sen($buruk);
                $this->fail("Seharusnya menolak: {$buruk}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_milli_dan_format_balik(): void
    {
        $this->assertSame(2500, Uang::milli('2.5'));
        $this->assertSame(100000, Uang::milli('100.000'));
        $this->assertSame('12500.50', Uang::keDesimal(1250050));
        $this->assertSame('0.05', Uang::keDesimal(5));
        $this->assertSame('0.00', Uang::keDesimal(0));
        $this->assertSame('-5.25', Uang::keDesimal(-525));
        $this->assertSame('2.500', Uang::milliKeDesimal(2500));
        $this->assertSame('0.007', Uang::milliKeDesimal(7));
    }

    public function test_putaran_balik_tanpa_kehilangan_digit(): void
    {
        foreach (['0.01', '1.00', '12500.50', '99999999.99'] as $s) {
            $this->assertSame($s, Uang::keDesimal(Uang::sen($s)));
        }
    }

    public function test_kali_membulatkan_setengah_ke_atas(): void
    {
        $this->assertSame(8000000, Uang::kali(10000, 800000));   // 10 x 8000
        $this->assertSame(2500000, Uang::kali(2500, 1000000));   // 2,5 x 10000
        $this->assertSame(3330, Uang::kali(333, 10000));         // 0,333 x 100 = 33,30
        $this->assertSame(1, Uang::kali(500, 1), '0,005 naik menjadi 0,01');
        $this->assertSame(0, Uang::kali(400, 1), '0,004 turun menjadi 0,00');
    }

    public function test_batas_atas_validasi_tidak_overflow(): void
    {
        // 99.999,999 x Rp 99.999.999,99 adalah masukan terbesar yang diizinkan formulir.
        $this->assertIsInt(Uang::kali(99999999, 9999999999));
    }

    public function test_perkalian_yang_meluap_ditolak_bukan_diam_diam_menjadi_float(): void
    {
        // Rp 9.999.999.999 per unit (batas harga produk) x 99.999,999 unit melewati batas 64-bit.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('terlalu besar');

        Uang::kali(99999999, 999999999900);
    }

    public function test_harga_per_unit_dibulatkan_setengah_ke_atas(): void
    {
        $this->assertSame(290000, Uang::perUnit(1450000, 5000)); // 14.500 / 5 = 2.900
        $this->assertSame(3333, Uang::perUnit(10000, 3000));     // 100 / 3 = 33,33
        $this->assertSame(6667, Uang::perUnit(20000, 3000));     // 200 / 3 = 66,67
        $this->assertSame(0, Uang::perUnit(0, 1000));
    }

    public function test_nilai_tidak_valid_untuk_hitungan_ditolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Uang::perUnit(100, 0);
    }

    public function test_kurangi_persen_membulatkan_setengah_ke_atas(): void
    {
        $this->assertSame(1000, Uang::kurangiPersen(1000, 0));
        $this->assertSame(500, Uang::kurangiPersen(999, '50')); // 9,99 x 0,5 = 4,995 -> 5,00
        $this->assertSame(1, Uang::kurangiPersen(1, '49.99'));  // 0,01 x 0,5001 -> tetap 1 sen
        $this->assertSame(0, Uang::kurangiPersen(10000, '100'));
        $this->assertSame(845000, Uang::kurangiPersen(1000000, '15.5')); // Rp10.000 - 15,5% = Rp8.450
    }

    public function test_kurangi_persen_menolak_di_luar_0_sampai_100(): void
    {
        foreach (['101', '-5'] as $buruk) {
            try {
                Uang::kurangiPersen(100, $buruk);
                $this->fail("Seharusnya menolak persen: {$buruk}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_kali_negatif_ditolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Uang::kali(-1, 5);
    }
}
