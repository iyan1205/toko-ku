<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductService
{
    public function __construct(private readonly KodeService $kode) {}

    /**
     * @param  array<string, mixed>  $data  data tervalidasi tanpa foto (stok tidak pernah diterima dari formulir)
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

                [$data, $units] = $this->pisahkan($data);
                $produk = Product::create($data);
                $this->syncUnits($produk, $units);
                $this->syncVariants($produk);

                return $produk;
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

                [$data, $units] = $this->pisahkan($data);
                $produk->update($data);
                if ($units !== null) {
                    $this->syncUnits($produk, $units);
                }
                $this->syncVariants($produk);
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

    /**
     * Satuan lain dari data form, dan atribut varian kosong = bukan induk varian.
     *
     * @return array{0: array<string, mixed>, 1: ?list<array{unit_id: int, factor: string, barcode?: ?string, selling_price?: ?string}>}
     */
    private function pisahkan(array $data): array
    {
        $units = array_key_exists('units', $data) ? (array) $data['units'] : null;
        unset($data['units']);
        if (array_key_exists('variant_options', $data)) {
            $data['variant_options'] = $data['variant_options'] ?: null;
        }

        return [$data, $units];
    }

    /** Ganti daftar satuan lain produk (DUS, PAK, ...) dengan isian form. */
    public function syncUnits(Product $produk, array $units): void
    {
        $simpan = collect($units)->keyBy(fn ($u) => (int) $u['unit_id']);
        $produk->units()->whereNotIn('unit_id', $simpan->keys())->delete();
        foreach ($simpan as $unitId => $u) {
            $produk->units()->updateOrCreate(['unit_id' => $unitId], [
                'factor' => $u['factor'],
                'barcode' => filled($u['barcode'] ?? null) ? $u['barcode'] : null,
                'selling_price' => filled($u['selling_price'] ?? null) ? $u['selling_price'] : null,
            ]);
        }
    }

    /**
     * Varian dari atribut induk (variant_options): satu produk per kombinasi nilai, dibuat bila belum ada
     * (SKU induk + nilai, harga & aturan dari induk). Kombinasi yang dihapus dinonaktifkan, tidak dihapus,
     * supaya riwayat stok dan penjualannya tetap utuh.
     */
    public function syncVariants(Product $induk): void
    {
        if (! $induk->isVariantParent()) {
            return;
        }

        $kombinasi = [[]];
        foreach ($induk->variant_options as $opsi) {
            $baru = [];
            foreach ($kombinasi as $k) {
                foreach ($opsi['values'] as $nilai) {
                    $baru[] = $k + [$opsi['name'] => $nilai];
                }
            }
            $kombinasi = $baru;
        }

        $ada = $induk->variants()->withTrashed()->get();
        $kunci = fn (array $nilai) => mb_strtolower(json_encode(array_values($nilai)));
        $dipakai = [];

        foreach ($kombinasi as $nilai) {
            $varian = $ada->first(fn (Product $v) => $kunci($v->variant_values ?? []) === $kunci($nilai));
            $nama = mb_substr($induk->name.' '.implode(' / ', $nilai), 0, 150);
            if ($varian === null) {
                $sku = $this->skuVarian($induk->sku, $nilai);
                $varian = Product::create([
                    'sku' => $sku, 'name' => $nama, 'parent_id' => $induk->id, 'variant_values' => $nilai,
                    'category_id' => $induk->category_id, 'unit_id' => $induk->unit_id,
                    'purchase_price' => $induk->purchase_price, 'selling_price' => $induk->selling_price,
                    'min_stock' => $induk->min_stock, 'discount_percent' => $induk->discount_percent,
                    'track_batches' => $induk->track_batches, 'track_serials' => $induk->track_serials,
                    'is_active' => $induk->is_active, 'description' => $induk->description,
                ]);
                $varian->forceFill(['type' => $induk->type ?? 'jual'])->save();
            } else {
                if ($varian->trashed()) {
                    $varian->restore();
                }
                // Status aktif varian yang sudah ada diatur sendiri di halaman varian itu.
                $varian->update(['name' => $nama, 'variant_values' => $nilai]);
            }
            $dipakai[] = $varian->id;
        }

        $induk->variants()->whereNotIn('id', $dipakai)->update(['is_active' => false]);
    }

    private function skuVarian(string $skuInduk, array $nilai): string
    {
        $akhiran = strtoupper(trim(preg_replace('/[^A-Za-z0-9-]+/', '', implode('-', array_map(fn ($v) => mb_substr($v, 0, 6), $nilai))), '-') ?: 'V');
        $dasar = mb_substr($skuInduk.'-'.$akhiran, 0, 46);
        $sku = $dasar;
        for ($i = 2; Product::withTrashed()->where('sku', $sku)->exists(); $i++) {
            $sku = $dasar.'-'.$i;
        }

        return $sku;
    }

    /** Soft delete: riwayat penjualan/pembelian yang merujuk produk ini tetap utuh. Foto dipertahankan. */
    public function hapus(Product $produk): void
    {
        $produk->delete();
    }
}
