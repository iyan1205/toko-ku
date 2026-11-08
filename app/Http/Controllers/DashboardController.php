<?php

namespace App\Http\Controllers;

use App\Support\Menu;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Sementara: sapaan dan pintasan ke halaman yang boleh dibuka pengguna.
     * KPI, grafik, dan transaksi terakhir dibuat di Fase 4.
     */
    public function __invoke(Request $request): View
    {
        return view('dashboard', [
            'menu' => Menu::links($request->user())->reject(fn (array $item) => $item['key'] === 'dashboard')->values(),
        ]);
    }
}
