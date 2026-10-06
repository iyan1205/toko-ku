<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Dilempar service ketika sebuah aturan bisnis dilanggar
 * (contoh: "Stok tidak mencukupi."). Pesannya aman ditampilkan ke pengguna.
 * Di-render sebagai flash error oleh handler di bootstrap/app.php.
 */
class AturanBisnisException extends RuntimeException
{
}
