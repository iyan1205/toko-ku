<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardService;
use App\Support\Menu;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dashboard untuk semua pengguna login. Isinya menyesuaikan hak akses (lihat DashboardService);
 * pengguna tanpa satu pun bagian dashboard melihat pintasan ke modul yang boleh dibuka.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): View
    {
        $data = $dashboard->for($request->user());

        return view('dashboard', $data + [
            'menu' => Menu::links($request->user())->reject(fn (array $item) => $item['key'] === 'dashboard')->values(),
        ]);
    }
}
