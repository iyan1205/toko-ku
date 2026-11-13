<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Barcode Code 128 (set B: ASCII 32–126) sebagai SVG, tanpa paket tambahan. Untuk label produk:
 * hampir semua scanner kasir membaca Code 128.
 */
final class Code128
{
    /** Lebar batang/spasi tiap simbol 0–106 (106 = stop). */
    public const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;

    private const STOP = 106;

    /**
     * Nilai simbol (start, data, checksum, stop).
     *
     * @return list<int>
     */
    public static function values(string $text): array
    {
        if ($text === '' || preg_match('/[^\x20-\x7E]/', $text)) {
            throw new InvalidArgumentException('Code 128 hanya untuk huruf, angka, dan tanda baca ASCII.');
        }

        $values = [self::START_B];
        $sum = self::START_B;
        foreach (str_split($text) as $i => $char) {
            $value = ord($char) - 32;
            $values[] = $value;
            $sum += $value * ($i + 1);
        }
        $values[] = $sum % 103;
        $values[] = self::STOP;

        return $values;
    }

    /** Lebar modul berurutan (batang, spasi, batang, ...), termasuk zona diam 10 modul di kiri dan kanan. */
    public static function modules(string $text): string
    {
        return implode('', array_map(fn (int $v) => self::PATTERNS[$v], self::values($text)));
    }

    /** SVG barcode; tinggi dalam satuan modul (skala mengikuti lebar elemen yang memuatnya). */
    public static function svg(string $text, int $height = 40): string
    {
        $x = 10; // zona diam
        $rects = '';
        foreach (str_split(self::modules($text)) as $i => $width) {
            if ($i % 2 === 0) {
                $rects .= '<rect x="'.$x.'" y="0" width="'.$width.'" height="'.$height.'"/>';
            }
            $x += (int) $width;
        }
        $total = $x + 10;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$total.' '.$height.'" preserveAspectRatio="none" role="img" aria-label="'.e($text).'"><g fill="#000">'.$rects.'</g></svg>';
    }
}
