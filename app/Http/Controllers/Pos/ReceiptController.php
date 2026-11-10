<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;

use App\Models\Sale;
use App\Services\Settings\ReceiptSettingsService;
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
 * Identitas toko (Pengaturan → Profil Toko), lebar kertas, footer, logo, NPWP, dan cetak
 * otomatis (Pengaturan → Struk & Printer) dibaca dari tabel settings, bukan dari .env,
 * supaya bisa diubah pemilik tanpa menyentuh server.
 */
class ReceiptController extends Controller
{
    public function __construct(private readonly ReceiptSettingsService $struk) {}

    public function show(Sale $sale): View
    {
        // Izin yang sama dengan melihat detail penjualan: kasir hanya struk miliknya sendiri.
        Gate::authorize('view', $sale);
        Gate::authorize('pos.transactions.print');

        $pengaturan = $this->struk->get();

        return view('sales.receipt', [
            'penjualan' => $sale->load(['customer', 'user', 'items', 'payments']),
            'toko' => $this->struk->storeHeader(),
            'lebarMm' => $pengaturan['paper_width'],
            'footer' => $pengaturan['footer'],
            'cetakOtomatis' => $pengaturan['auto_print'],
            'pratinjau' => false,
        ]);
    }
}
