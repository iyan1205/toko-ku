<?php

namespace App\Services;

use App\Exceptions\ImportGagalException;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Impor produk dari baris-baris spreadsheet. Semua-atau-tidak-sama-sekali:
 * bila ada satu baris salah, tidak ada data yang tersimpan.
 *
 * - SKU sudah ada  -> produk diperbarui; SKU kosong/baru -> produk dibuat.
 * - Kategori dan satuan harus sudah ada (tidak dibuat otomatis agar salah ketik tidak mengotori master).
 * - Kolom stok diabaikan: stok hanya berubah lewat stock_movements (Tahap 3).
 */
class ProductImportService
{
    public const MAKS_BARIS = 2000;

    private const KOLOM_WAJIB = ['nama_produk', 'kategori', 'satuan', 'harga_beli', 'harga_jual'];

    private const KOLOM_DIKENAL = [
        'sku', 'barcode', 'nama_produk', 'kategori', 'satuan', 'harga_beli', 'harga_jual',
        'stok_minimum', 'diskon', 'status', 'deskripsi',
    ];

    public function __construct(private readonly KodeService $kode)
    {
    }

    /**
     * @param  Collection<int, mixed>  $rows  baris dengan kunci judul kolom (mis. "Nama Produk" atau nama_produk)
     * @return array{dibuat:int, diperbarui:int}
     */
    public function impor(Collection $rows): array
    {
        $baris = $rows->map(fn ($r) => $this->normalisasi($this->keArray($r)));

        $terisi = $baris->filter(fn (array $r) => $this->adaIsi($r));

        if ($terisi->isEmpty()) {
            throw new ImportGagalException(['Berkas tidak berisi data produk.']);
        }

        if ($terisi->count() > self::MAKS_BARIS) {
            throw new ImportGagalException(['Maksimal ' . self::MAKS_BARIS . ' baris per impor.']);
        }

        $hilang = array_diff(self::KOLOM_WAJIB, array_keys($baris->first()));
        if ($hilang) {
            throw new ImportGagalException(['Kolom wajib tidak ditemukan pada baris judul: ' . implode(', ', $hilang) . '.']);
        }

        $kategori = Category::pluck('id', 'name')->mapWithKeys(fn ($id, $nama) => [mb_strtolower($nama) => $id]);
        $satuan = Unit::pluck('id', 'name')->mapWithKeys(fn ($id, $nama) => [mb_strtolower($nama) => $id]);
        $produkSku = Product::withTrashed()->get()->keyBy(fn (Product $p) => mb_strtolower($p->sku));
        $pemilikBarcode = Product::withTrashed()->whereNotNull('barcode')->pluck('id', 'barcode');

        $errors = [];
        $siap = [];
        $skuDiFile = [];
        $barcodeDiFile = [];

        foreach ($baris as $i => $r) {
            if (! $this->adaIsi($r)) {
                continue;
            }

            $nomor = $i + 2; // baris 1 adalah judul kolom
            $r += array_fill_keys(self::KOLOM_DIKENAL, null); // kolom opsional yang tidak ada di berkas dianggap kosong
            $salah = $this->validasiBaris($r);

            $sku = $r['sku'] ? strtoupper($r['sku']) : null;
            $barcode = $r['barcode'] ?: null;
            $ada = $sku ? $produkSku->get(mb_strtolower($sku)) : null;

            if ($ada?->trashed()) {
                $salah[] = "SKU \"{$sku}\" dipakai produk yang sudah dihapus.";
            }

            if ($sku) {
                if (isset($skuDiFile[$sku])) {
                    $salah[] = "SKU \"{$sku}\" muncul ganda (juga di baris {$skuDiFile[$sku]}).";
                }
                $skuDiFile[$sku] = $nomor;
            }

            if ($barcode) {
                $pemilik = $pemilikBarcode->get($barcode);
                if ($pemilik !== null && $pemilik !== $ada?->id) {
                    $salah[] = "Barcode \"{$barcode}\" sudah dipakai produk lain.";
                }
                if (isset($barcodeDiFile[$barcode])) {
                    $salah[] = "Barcode \"{$barcode}\" muncul ganda (juga di baris {$barcodeDiFile[$barcode]}).";
                }
                $barcodeDiFile[$barcode] = $nomor;
            }

            $idKategori = $kategori->get(mb_strtolower((string) $r['kategori']));
            if ($r['kategori'] && ! $idKategori) {
                $salah[] = "Kategori \"{$r['kategori']}\" tidak ditemukan.";
            }

            $idSatuan = $satuan->get(mb_strtolower((string) $r['satuan']));
            if ($r['satuan'] && ! $idSatuan) {
                $salah[] = "Satuan \"{$r['satuan']}\" tidak ditemukan.";
            }

            if ($salah) {
                foreach ($salah as $pesan) {
                    $errors[] = "Baris {$nomor}: {$pesan}";
                }

                continue;
            }

            $siap[] = [
                'ada' => $ada,
                'data' => [
                    'barcode' => $barcode,
                    'name' => $r['nama_produk'],
                    'category_id' => $idKategori,
                    'unit_id' => $idSatuan,
                    'purchase_price' => (float) $r['harga_beli'],
                    'selling_price' => (float) $r['harga_jual'],
                    'min_stock' => (float) ($r['stok_minimum'] ?? 0),
                    'discount_percent' => (float) ($r['diskon'] ?? 0),
                    'is_active' => strtolower((string) ($r['status'] ?? 'aktif')) !== 'nonaktif',
                    'description' => $r['deskripsi'] ?: null,
                ],
                'sku' => $sku,
            ];
        }

        if ($errors) {
            throw new ImportGagalException($errors);
        }

        return DB::transaction(function () use ($siap) {
            $dibuat = 0;
            $diperbarui = 0;

            foreach ($siap as $item) {
                if ($item['ada']) {
                    // Tahap 11: catat perubahan harga ke audit log.
                    $item['ada']->update($item['data']);
                    $diperbarui++;
                } else {
                    Product::create($item['data'] + [
                        'sku' => $item['sku'] ?? $this->kode->berikutnya(Product::class, 'sku', 'PRD-', 5),
                    ]);
                    $dibuat++;
                }
            }

            return ['dibuat' => $dibuat, 'diperbarui' => $diperbarui];
        });
    }

    /** @return array<int, string> */
    private function validasiBaris(array $r): array
    {
        $v = Validator::make($r, [
            'sku' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._\-\/]+$/'],
            'barcode' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'nama_produk' => ['required', 'string', 'max:150'],
            'kategori' => ['required', 'string'],
            'satuan' => ['required', 'string'],
            'harga_beli' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'harga_jual' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'stok_minimum' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'diskon' => ['nullable', 'numeric', 'between:0,100'],
            'status' => ['nullable', 'in:aktif,nonaktif,Aktif,Nonaktif,AKTIF,NONAKTIF'],
            'deskripsi' => ['nullable', 'string', 'max:2000'],
        ], [
            'regex' => ':attribute berisi karakter yang tidak diizinkan.',
            'numeric' => ':attribute harus berupa angka biasa tanpa "Rp" atau pemisah ribuan.',
            'in' => ':attribute harus "Aktif" atau "Nonaktif".',
        ], [
            'sku' => 'SKU',
            'barcode' => 'Barcode',
            'nama_produk' => 'Nama Produk',
            'kategori' => 'Kategori',
            'satuan' => 'Satuan',
            'harga_beli' => 'Harga Beli',
            'harga_jual' => 'Harga Jual',
            'stok_minimum' => 'Stok Minimum',
            'diskon' => 'Diskon',
            'status' => 'Status',
            'deskripsi' => 'Deskripsi',
        ]);

        return $v->errors()->all();
    }
    
    /** Laravel Excel mengirim tiap baris sebagai Collection; (array) akan merusaknya, jadi pakai toArray(). */
    private function keArray(mixed $baris): array
    {
        return $baris instanceof Arrayable ? $baris->toArray() : (array) $baris;
    }
    /** Kunci judul kolom diseragamkan ("Nama Produk" -> nama_produk); nilai dirapikan. */
    private function normalisasi(array $row): array
    {
        $hasil = [];

        foreach ($row as $kunci => $nilai) {
            $hasil[Str::slug((string) $kunci, '_')] = $this->teks($nilai);
        }

        return $hasil;
    }

    private function teks(mixed $nilai): mixed
    {
        if ($nilai === null) {
            return null;
        }

        // Excel menyimpan barcode/SKU angka sebagai float (8.99E+12); kembalikan ke teks utuh.
        if (is_float($nilai) && floor($nilai) === $nilai) {
            return number_format($nilai, 0, '', '');
        }

        if (is_string($nilai)) {
            $nilai = trim($nilai);

            return $nilai === '' ? null : $nilai;
        }

        return is_scalar($nilai) ? (string) $nilai : null;
    }

    private function adaIsi(array $row): bool
    {
        return collect($row)->contains(fn ($v) => $v !== null && $v !== '');
    }
}
