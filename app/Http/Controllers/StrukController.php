<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\StoreProfileService;
use App\Support\StrukTeks;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Struk sebagai teks murni untuk printer thermal yang terhubung ke HP/tablet.
 *
 * Kenapa perlu endpoint terpisah: browser TIDAK BISA mengirim data ke printer
 * thermal Bluetooth (printer struk memakai Bluetooth Classic/SPP, sedangkan
 * browser hanya bisa Bluetooth Low Energy). Jalur yang bekerja adalah aplikasi
 * jembatan di HP — RawBT paling umum di Indonesia — yang menerima teks dan
 * meneruskannya ke printer.
 *
 * Halaman struk (ReceiptController) mengambil teks dari sini lalu mengirimnya
 * lewat Android Intent. Dipisah agar format struk hanya ada di satu tempat.
 *
 * Pencetakan lewat dialog browser biasa TIDAK terpengaruh: itu tetap memakai
 * halaman HTML dari ReceiptController.
 */
class StrukController extends Controller
{
    public function __construct(private readonly StoreProfileService $profil) {}

    public function teks(Sale $sale): JsonResponse
    {
        // Izin sama dengan melihat struk: kasir hanya struk miliknya sendiri.
        Gate::authorize('view', $sale);
        Gate::authorize('pos.transactions.print');

        $toko = $this->profil->ambil();

        $sale->load(['customer', 'user', 'items']);

        return response()->json([
            'nomor' => $sale->number,
            'lebar_mm' => $toko['lebar_struk_mm'],
            'lebar_karakter' => StrukTeks::lebarKertas($toko['lebar_struk_mm']),
            'teks' => (new StrukTeks($sale, $toko))->susun(),
        ]);
    }
}
