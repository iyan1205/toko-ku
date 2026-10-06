<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductService
{
    public function __construct(private readonly KodeService $kode)
    {
    }

    /**
     * @param array<string, mixed> $data  data tervalidasi tanpa foto (stok tidak pernah diterima dari formulir)
     */
    public function buat(array $data, ?UploadedFile $foto = null): Product
    {
        $path = null;

        try {
            return DB::transaction(function () use ($data, $foto, &$path) {
                $data['sku'] = $data['sku'] ?? $this->kode->berikutnya(Product::class, 'sku', 'PRD-', 5);

                if ($foto) {
                    $path = $foto->store('produk', 'public');
                    $data['photo'] = $path;
                }

                return Product::create($data);
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }
    }

    /** @param array<string, mixed> $data */
    public function perbarui(Product $produk, array $data, ?UploadedFile $foto = null, bool $hapusFoto = false): Product
    {
        $fotoLama = $produk->photo;
        $fotoBaru = null;

        try {
            DB::transaction(function () use ($produk, $data, $foto, $hapusFoto, &$fotoBaru) {
                $data['sku'] = $data['sku'] ?? $produk->sku;

                if ($foto) {
                    $fotoBaru = $foto->store('produk', 'public');
                    $data['photo'] = $fotoBaru;
                } elseif ($hapusFoto) {
                    $data['photo'] = null;
                }

                // Tahap 11: catat perubahan harga (purchase_price/selling_price) ke audit log di sini.
                $produk->update($data);
            });
        } catch (Throwable $e) {
            if ($fotoBaru) {
                Storage::disk('public')->delete($fotoBaru);
            }

            throw $e;
        }

        if ($fotoLama && $fotoLama !== $produk->photo) {
            Storage::disk('public')->delete($fotoLama);
        }

        return $produk;
    }

    /** Soft delete: riwayat penjualan/pembelian yang merujuk produk ini tetap utuh. Foto dipertahankan. */
    public function hapus(Product $produk): void
    {
        $produk->delete();
    }
}
