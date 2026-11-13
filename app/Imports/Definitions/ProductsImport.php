<?php

namespace App\Imports\Definitions;

use App\Imports\ImportDefinition;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Support\Facades\Validator;

/**
 * Impor produk (kerangka impor: templat → pratinjau → proses). SKU yang sudah ada → diperbarui;
 * SKU kosong → produk baru (SKU otomatis). Kategori & satuan harus sudah ada. Stok tidak diimpor
 * (stok hanya berubah lewat kartu stok). Opsional: satu satuan besar (misal DUS isi 12) dengan
 * harga & barcode sendiri, serta pelacakan batch/seri.
 */
class ProductsImport extends ImportDefinition
{
    /** @var array<string, Product> */
    private array $bySku = [];

    /** @var array<string, int> */
    private array $categories = [];

    /** @var array<string, int> */
    private array $units = [];

    /** @var array<string, int> barcode => product_id (produk & satuan lain) */
    private array $barcodes = [];

    /** @var array<string, int> */
    private array $seen = [];

    public function __construct(private readonly ProductService $products) {}

    public function key(): string
    {
        return 'products';
    }

    public function title(): string
    {
        return 'Produk';
    }

    public function indexRoute(): string
    {
        return 'products.index';
    }

    public function matchingRule(): string
    {
        return 'Baris dengan SKU yang sudah ada memperbarui produk itu. SKU kosong = produk baru dengan SKU otomatis. Stok tidak diimpor: isi lewat pembelian atau penyesuaian stok.';
    }

    public function columns(): array
    {
        return [
            'sku' => ['label' => 'SKU', 'hint' => 'Kosongkan untuk produk baru.', 'example' => 'PRD-00001', 'text' => true],
            'barcode' => ['label' => 'Barcode', 'example' => '8991234567890', 'text' => true],
            'nama_produk' => ['label' => 'Nama Produk', 'required' => true, 'example' => 'Mie Instan Goreng'],
            'kategori' => ['label' => 'Kategori', 'required' => true, 'hint' => 'Nama kategori yang sudah ada.', 'example' => 'Makanan'],
            'satuan' => ['label' => 'Satuan', 'required' => true, 'hint' => 'Satuan dasar yang sudah ada.', 'example' => 'PCS'],
            'harga_beli' => ['label' => 'Harga Beli', 'required' => true, 'example' => '2500'],
            'harga_jual' => ['label' => 'Harga Jual', 'required' => true, 'example' => '3500'],
            'stok_minimum' => ['label' => 'Stok Minimum', 'example' => '24'],
            'diskon' => ['label' => 'Diskon', 'hint' => 'Persen, 0–100.', 'example' => '0'],
            'status' => ['label' => 'Status', 'hint' => 'aktif atau nonaktif.', 'example' => 'aktif'],
            'dijual' => ['label' => 'Dijual', 'hint' => 'ya/tidak: tampil di kasir. Tidak = bahan baku.', 'example' => 'ya'],
            'dibeli' => ['label' => 'Dibeli', 'hint' => 'ya/tidak: bisa dibeli dari supplier. Tidak = olahan sendiri.', 'example' => 'ya'],
            'deskripsi' => ['label' => 'Deskripsi', 'example' => ''],
            'lacak_batch' => ['label' => 'Lacak Batch', 'hint' => 'ya/tidak: batch & kedaluwarsa.', 'example' => 'tidak'],
            'lacak_seri' => ['label' => 'Lacak Seri', 'hint' => 'ya/tidak: nomor seri per unit.', 'example' => 'tidak'],
            'satuan_besar' => ['label' => 'Satuan Besar', 'hint' => 'Opsional, misal DUS.', 'example' => 'DUS'],
            'isi_satuan_besar' => ['label' => 'Isi Satuan Besar', 'hint' => 'Jumlah satuan dasar per satuan besar.', 'example' => '40'],
            'harga_satuan_besar' => ['label' => 'Harga Satuan Besar', 'hint' => 'Kosong = harga jual × isi.', 'example' => '125000'],
            'barcode_satuan_besar' => ['label' => 'Barcode Satuan Besar', 'example' => '18991234567897', 'text' => true],
        ];
    }

    public function maxRows(): int
    {
        return 5000;
    }

    public function prepare(): void
    {
        $this->bySku = Product::withTrashed()->get()->keyBy(fn (Product $p) => mb_strtolower($p->sku))->all();
        $this->categories = Category::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id])->all();
        $this->units = Unit::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id])->all();
        $this->barcodes = Product::withTrashed()->whereNotNull('barcode')->pluck('id', 'barcode')->all()
            + ProductUnit::query()->whereNotNull('barcode')->pluck('product_id', 'barcode')->all();
        $this->seen = [];
    }

    public function validate(array $row, int $line): array
    {
        $sku = ($v = $this->text($row['sku'] ?? null)) ? mb_strtoupper($v) : null;
        $existing = $sku ? ($this->bySku[mb_strtolower($sku)] ?? null) : null;
        $ya = fn ($v) => in_array(mb_strtolower((string) $this->text($v)), ['ya', 'y', '1', 'yes', 'true'], true);
        $data = [
            'sku' => $sku,
            'barcode' => $this->text($row['barcode'] ?? null),
            'name' => $this->text($row['nama_produk'] ?? null),
            'category_id' => $this->categories[mb_strtolower((string) $this->text($row['kategori'] ?? null))] ?? null,
            'unit_id' => $this->units[mb_strtolower((string) $this->text($row['satuan'] ?? null))] ?? null,
            'purchase_price' => $this->text($row['harga_beli'] ?? null),
            'selling_price' => $this->text($row['harga_jual'] ?? null),
            'min_stock' => $this->text($row['stok_minimum'] ?? null) ?? '0',
            'discount_percent' => $this->text($row['diskon'] ?? null) ?? '0',
            'is_active' => mb_strtolower((string) ($this->text($row['status'] ?? null) ?? 'aktif')) !== 'nonaktif',
            'description' => $this->text($row['deskripsi'] ?? null),
            'type' => ($this->text($row['dijual'] ?? null) === null || $ya($row['dijual'])) ? 'jual' : 'bahan_baku',
            'is_purchasable' => $this->text($row['dibeli'] ?? null) === null || $ya($row['dibeli']),
            'track_batches' => $ya($row['lacak_batch'] ?? null),
            'track_serials' => $ya($row['lacak_seri'] ?? null),
        ];
        $besar = $this->text($row['satuan_besar'] ?? null);
        $unit = $besar ? [
            'unit_id' => $this->units[mb_strtolower($besar)] ?? null,
            'factor' => $this->text($row['isi_satuan_besar'] ?? null),
            'selling_price' => $this->text($row['harga_satuan_besar'] ?? null),
            'barcode' => $this->text($row['barcode_satuan_besar'] ?? null),
        ] : null;

        $errors = Validator::make($data + ['unit' => $unit], [
            'name' => ['required', 'string', 'max:150'],
            'purchase_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'min_stock' => ['numeric', 'min:0'],
            'discount_percent' => ['numeric', 'between:0,100'],
            'barcode' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._\\-]+$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit.factor' => ['nullable', 'required_with:unit', 'numeric', 'gt:0'],
            'unit.selling_price' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            'name' => 'Nama Produk', 'purchase_price' => 'Harga Beli', 'selling_price' => 'Harga Jual', 'min_stock' => 'Stok Minimum',
            'discount_percent' => 'Diskon', 'barcode' => 'Barcode', 'unit.factor' => 'Isi Satuan Besar', 'unit.selling_price' => 'Harga Satuan Besar',
        ])->errors()->all();

        if ($data['category_id'] === null) {
            $errors[] = 'Kategori "'.($row['kategori'] ?? '').'" tidak ditemukan.';
        }
        if ($data['unit_id'] === null) {
            $errors[] = 'Satuan "'.($row['satuan'] ?? '').'" tidak ditemukan.';
        }
        if ($unit !== null && $unit['unit_id'] === null) {
            $errors[] = "Satuan besar \"{$besar}\" tidak ditemukan.";
        }
        if ($sku !== null && $existing?->trashed()) {
            $errors[] = "SKU {$sku} dipakai produk yang sudah dihapus.";
        }
        foreach (array_filter([$data['barcode'], $unit['barcode'] ?? null]) as $kode) {
            $pemilik = $this->barcodes[$kode] ?? null;
            if ($pemilik !== null && $pemilik !== $existing?->id) {
                $errors[] = "Barcode {$kode} sudah dipakai produk lain.";
            }
            if (isset($this->seen['b:'.$kode])) {
                $errors[] = "Barcode {$kode} muncul ganda (juga di baris {$this->seen['b:'.$kode]}).";
            }
            $this->seen['b:'.$kode] ??= $line;
        }
        if ($sku !== null) {
            if (isset($this->seen['s:'.$sku])) {
                $errors[] = "SKU {$sku} muncul ganda (juga di baris {$this->seen['s:'.$sku]}).";
            }
            $this->seen['s:'.$sku] ??= $line;
        }

        return [['existing' => $existing, 'unit' => $unit] + $data, $errors];
    }

    public function persist(array $data, User $user): string
    {
        $existing = $data['existing'];
        $fields = array_diff_key($data, array_flip(['existing', 'unit']));
        if ($fields['sku'] === null) {
            unset($fields['sku']);
        }

        if ($existing !== null) {
            $product = $this->products->perbarui($existing, $fields);
        } else {
            $product = $this->products->buat($fields);
        }

        if ($data['unit'] !== null) {
            $lain = $product->units()->where('unit_id', '!=', $data['unit']['unit_id'])->get()
                ->map(fn ($u) => ['unit_id' => $u->unit_id, 'factor' => $u->factor, 'barcode' => $u->barcode, 'selling_price' => $u->selling_price])->all();
            $this->products->syncUnits($product, [...$lain, $data['unit']]);
        }

        return $existing !== null ? 'updated' : 'created';
    }
}
