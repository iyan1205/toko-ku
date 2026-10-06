<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Menu sidebar dibaca dari config/menu.php. Item hanya tampil bila
 * (1) route-nya sudah ada dan (2) pengguna punya salah satu izin yang disyaratkan.
 * Tahap berikutnya cukup mendaftarkan route dan menu otomatis muncul.
 */
final class Menu
{
    /** @return Collection<int, array<string, mixed>> */
    public static function untuk(?User $user): Collection
    {
        return collect(config('menu', []))
            ->filter(function (array $item) use ($user) {
                if (! Route::has($item['route'])) {
                    return false;
                }

                $izin = $item['permission'] ?? null;

                return $izin === null || ($user !== null && $user->canAny((array) $izin));
            })
            ->values();
    }
}
