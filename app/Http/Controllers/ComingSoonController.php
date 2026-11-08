<?php

namespace App\Http\Controllers;

use App\Support\Menu;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Halaman "Segera Hadir" untuk menu berstatus coming_soon di config/menu.php.
 * Hanya bisa dibuka oleh pengguna yang juga melihat menu tersebut di sidebar.
 */
class ComingSoonController extends Controller
{
    public function __invoke(Request $request, string $key): View
    {
        $found = Menu::find($key);

        abort_if($found === null || $found['status'] !== Menu::COMING_SOON, 404);

        $user = $request->user();
        abort_unless(
            Menu::allows($found['parent']['permission'] ?? null, $user)
                && Menu::allows($found['item']['permission'] ?? null, $user),
            403,
        );

        return view('coming-soon', [
            'item' => $found['item'],
            'parent' => $found['parent'],
        ]);
    }
}
