<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\StockOpnameStatus;
use App\Exceptions\AturanBisnisException;
use App\Exports\Inventory\StockOpnamesExport;
use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockOpnameRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockOpname;
use App\Models\Warehouse;
use App\Rules\OpenPeriod;
use App\Services\StockOpnameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stok opname per gudang: hitung (draft), lalu selesaikan untuk membukukan selisih.
 * Izin: view (daftar/detail), create (hitung & ubah draft), approve (selesaikan), export.
 */
class StockOpnameController extends Controller
{
    public const BATAS_BARIS = 500;

    public function __construct(private readonly StockOpnameService $service) {}

    public function index(Request $request): View
    {
        return view('inventory.opnames.index', [
            'opname' => StockOpnamesExport::fromRequest($request, $this->filter($request))->paginate(15),
            'gudang' => Warehouse::query()->inCurrentBranch()->ordered()->get(['id', 'name']),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return StockOpnamesExport::fromRequest($request, $this->filter($request))->download($format);
    }

    public function create(Request $request): View
    {
        $data = $request->validate([
            'gudang' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
        ]);
        $gudang = isset($data['gudang']) ? Warehouse::findOrFail($data['gudang']) : Warehouse::forCurrentBranch();

        return view('inventory.opnames.form', $this->formData($request, new StockOpname(['opname_date' => now()]), $gudang));
    }

    public function store(StockOpnameRequest $request): RedirectResponse
    {
        $final = $request->input('aksi') === 'final';
        $opname = $this->service->create($request->validated(), $request->user(), $final);

        return redirect()->route('stock-opnames.show', $opname)->with('success', $this->pesan($opname->fresh(), $final));
    }

    public function show(StockOpname $stockOpname): View
    {
        $stockOpname->load(['warehouse', 'user', 'finalizedBy', 'items' => fn ($q) => $q->with('product.unit')->orderBy('id')]);

        return view('inventory.opnames.show', [
            'opname' => $stockOpname,
            // Untuk draft: stok gudang saat ini, agar produk yang stoknya bergeser sejak dihitung terlihat.
            'stokKini' => $stockOpname->isDraft()
                ? $this->service->warehouseQuantities($stockOpname->warehouse, $stockOpname->items->pluck('product_id'))
                : [],
        ]);
    }

    public function edit(Request $request, StockOpname $stockOpname): View|RedirectResponse
    {
        if (! $stockOpname->isDraft()) {
            return redirect()->route('stock-opnames.show', $stockOpname)->with('error', 'Stok opname yang sudah selesai tidak bisa diubah.');
        }

        return view('inventory.opnames.form', $this->formData($request, $stockOpname->load('items'), $stockOpname->warehouse));
    }

    public function update(StockOpnameRequest $request, StockOpname $stockOpname): RedirectResponse
    {
        $final = $request->input('aksi') === 'final';
        $this->service->updateDraft($stockOpname, $request->validated(), $request->user(), $final);

        return redirect()->route('stock-opnames.show', $stockOpname)->with('success', $this->pesan($stockOpname->fresh(), $final));
    }

    public function finalize(Request $request, StockOpname $stockOpname): RedirectResponse
    {
        [$opname] = $this->service->finalizeOrSubmit($stockOpname, $request->user());

        return redirect()->route('stock-opnames.show', $stockOpname)->with('success', $this->pesan($opname, true));
    }

    /** Mulai opname kosong untuk dihitung dengan scan barcode (HP), opsional membekukan stok gudang. */
    public function start(Request $request, StockOpnameService $service): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'opname_date' => ['required', 'date', 'before_or_equal:today', new OpenPeriod],
            'note' => ['nullable', 'string', 'max:255'],
            'freeze_stock' => ['boolean'],
        ], [], ['warehouse_id' => 'gudang', 'opname_date' => 'tanggal opname']);

        $opname = $service->start(Warehouse::query()->findOrFail($data['warehouse_id']), $data['opname_date'], $data['note'] ?? null, $request->boolean('freeze_stock'), $request->user());

        return redirect()->route('stock-opnames.count', $opname)->with('success', "Opname {$opname->number} dimulai".($opname->freeze_stock ? '; stok gudang dibekukan sampai selesai.' : '.'));
    }

    /** Halaman hitung untuk HP: scan barcode (kamera atau scanner) lalu jumlah fisik bertambah. */
    public function count(StockOpname $stockOpname): View|RedirectResponse
    {
        if (! $stockOpname->isDraft()) {
            return redirect()->route('stock-opnames.show', $stockOpname)->with('error', "Stok opname {$stockOpname->number} sudah {$stockOpname->status->label()}.");
        }

        return view('inventory.opnames.count', [
            'opname' => $stockOpname->load(['warehouse', 'items' => fn ($q) => $q->with('product:id,name,sku,barcode,unit_id', 'product.unit:id,name')->latest('updated_at')]),
        ]);
    }

    /** Satu scan: kode (barcode/SKU/barcode satuan) atau product_id, jumlah, mode add|set. */
    public function scan(Request $request, StockOpname $stockOpname): JsonResponse
    {
        $data = $request->validate([
            'kode' => ['nullable', 'string', 'max:60', 'required_without:product_id'],
            'product_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'mode' => ['nullable', 'in:add,set'],
        ]);

        $kode = trim((string) ($data['kode'] ?? ''));
        $satuan = $kode !== '' ? ProductUnit::query()->where('barcode', $kode)->first() : null;
        $produk = ! empty($data['product_id'])
            ? Product::aktif()->find($data['product_id'])
            : ($satuan?->product ?? Product::aktif()->where(fn ($q) => $q->where('barcode', $kode)->orWhere('sku', $kode))->first());

        if ($produk === null) {
            return response()->json(['message' => "Kode {$kode} tidak ditemukan."], 404);
        }

        // Scan barcode DUS dihitung sebagai isi dus dalam satuan dasar.
        $jumlah = (float) ($data['quantity'] ?? 1) * ($satuan ? (float) $satuan->factor : 1);
        $item = $this->service->scan($stockOpname, $produk, $jumlah, $data['mode'] ?? 'add', $request->user());

        return response()->json([
            'product_id' => $produk->id, 'name' => $produk->name, 'sku' => $produk->sku, 'unit' => $produk->unit?->name,
            'physical' => (float) $item->physical_stock, 'system' => (float) $item->system_stock, 'difference' => (float) $item->difference,
            'added' => $jumlah,
        ]);
    }

    private function pesan(StockOpname $opname, bool $final): string
    {
        return match (true) {
            $opname->status === StockOpnameStatus::Submitted => "Stok opname {$opname->number} diajukan untuk persetujuan. Selisih dibukukan setelah disetujui.",
            $opname->status === StockOpnameStatus::Final => "Stok opname {$opname->number} selesai. Selisih sudah dibukukan ke stok {$opname->warehouse->name}.",
            default => "Draft stok opname {$opname->number} tersimpan. Stok belum berubah sampai opname diselesaikan.",
        };
    }

    public function destroy(StockOpname $stockOpname): RedirectResponse
    {
        if (! $stockOpname->isDraft()) {
            throw new AturanBisnisException('Stok opname yang sudah selesai tidak bisa dihapus.');
        }

        $this->service->deleteDraft($stockOpname);

        return redirect()->route('stock-opnames.index')->with('success', "Draft stok opname {$stockOpname->number} dihapus.");
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'gudang' => ['nullable', 'integer', 'exists:warehouses,id'],
            'status' => ['nullable', Rule::in(array_map(fn ($s) => $s->value, StockOpnameStatus::cases()))],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);
    }

    /**
     * Produk yang ditampilkan di formulir hitung. Untuk draft, semua produk yang sudah dihitung
     * selalu ikut (walau tidak cocok filter), karena menyimpan draft mengganti seluruh hitungannya.
     *
     * @return array<string, mixed>
     */
    private function formData(Request $request, StockOpname $opname, Warehouse $gudang): array
    {
        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'kategori' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        $query = Product::aktif()->stockable()->filter($filter)->orderBy('name');
        $draftIds = $opname->exists ? $opname->items->pluck('product_id') : collect();

        $ids = (clone $query)->limit(self::BATAS_BARIS)->pluck('id')->merge($draftIds)->unique();
        $produk = Product::withTrashed()->with(['unit:id,name', 'category:id,name'])->whereIn('id', $ids)->orderBy('name')->get();

        return [
            'opname' => $opname,
            'gudang' => $gudang,
            'pilihanGudang' => Warehouse::query()->active()->inCurrentBranch()->ordered()->get(['id', 'name']),
            'produk' => $produk,
            'stokGudang' => $this->service->warehouseQuantities($gudang, $produk->modelKeys()),
            'hitungan' => $opname->exists ? $opname->items->keyBy('product_id') : collect(),
            // reorder(): COUNT tanpa ORDER BY agar aman di MySQL dengan ONLY_FULL_GROUP_BY.
            'terpotong' => (clone $query)->reorder()->count() > self::BATAS_BARIS,
            'kategori' => Category::orderBy('name')->get(['id', 'name']),
            'batas' => self::BATAS_BARIS,
        ];
    }
}
