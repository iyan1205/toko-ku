<?php

namespace App\Http\Controllers;

use App\Support\Menu;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Placeholder Tahap 1: sapaan dan pintasan modul sesuai hak akses.
     * Ringkasan penjualan/stok/keuntungan ditambahkan pada Tahap 9.
     */
    public function __invoke(Request $request): View
    {
        return view('dashboard', [
            'menu' => Menu::untuk($request->user())->reject(fn ($m) => $m['route'] === 'dashboard'),
        ]);
    }
}
