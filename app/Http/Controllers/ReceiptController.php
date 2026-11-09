<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\StoreProfileService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Struk untuk printer thermal. Halaman berdiri sendiri (tanpa sidebar) dengan CSS
 *
 * @page berukuran roll, jadi saat dicetak dari browser hasilnya pas selebar kertas.
 *
 * Pencetakan dilakukan di komputer kasir: browser → dialog cetak → printer thermal
 * yang terpasang di komputer itu. Server tidak perlu terhubung ke printer.
 *
 * Identitas toko dan lebar kertas dibaca dari Profil Toko (menu Pengaturan),
 * bukan dari .env, supaya bisa diubah pemilik tanpa menyentuh server.
 */
class ReceiptController extends Controller
{
    public function __construct(private readonly StoreProfileService $profil) {}

    public function show(Sale $sale): View
    {
        // Izin yang sama dengan melihat detail penjualan: kasir hanya struk miliknya sendiri.
        Gate::authorize('view', $sale);
        Gate::authorize('pos.transactions.print');

        $profil = $this->profil->ambil();

        return view('sales.receipt', [
            'penjualan' => $sale->load(['customer', 'user', 'items']),
            'toko' => $profil,
            'lebarMm' => $profil['lebar_struk_mm'],
            'footer' => $profil['footer_struk'],
            'cetakOtomatis' => (bool) config('pos.struk.cetak_otomatis', false),
        ]);
    }
}
