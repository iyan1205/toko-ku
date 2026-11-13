<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ProductBreakdownRequest;
use App\Models\Product;
use App\Models\ProductBreakdown;
use App\Models\Warehouse;
use App\Services\Inventory\BreakdownService;
use App\Support\Format;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Inventori → Pecah Bahan: definisi (1 pack karkas → potongan ayam) dan menjalankan pemecahan. */
class ProductBreakdownController extends Controller
{
    public function __construct(private readonly BreakdownService $service) {}

    public function index(): View
    {
        return view('inventory.breakdowns.index', [
            'breakdowns' => ProductBreakdown::query()->with(['source.unit', 'outputs.product.unit'])->orderByDesc('is_active')->latest('id')->get(),
            'gudang' => Warehouse::query()->active()->inCurrentBranch()->ordered()->get(['id', 'name', 'is_default']),
        ]);
    }

    public function create(): View
    {
        return view('inventory.breakdowns.form', $this->formData(new ProductBreakdown(['source_quantity' => 1, 'cost_method' => 'quantity', 'is_active' => true])));
    }

    public function store(ProductBreakdownRequest $request): RedirectResponse
    {
        $breakdown = $this->service->save(null, $request->validated());

        return redirect()->route('breakdowns.index')->with('success', "Pemecahan {$breakdown->source->name} tersimpan: {$breakdown->summary()}.");
    }

    public function edit(ProductBreakdown $breakdown): View
    {
        return view('inventory.breakdowns.form', $this->formData($breakdown->load('outputs')));
    }

    public function update(ProductBreakdownRequest $request, ProductBreakdown $breakdown): RedirectResponse
    {
        $this->service->save($breakdown, $request->validated());

        return redirect()->route('breakdowns.index')->with('success', "Pemecahan {$breakdown->source->name} diperbarui.");
    }

    public function destroy(ProductBreakdown $breakdown): RedirectResponse
    {
        $breakdown->delete();

        return redirect()->route('breakdowns.index')->with('success', 'Pemecahan dihapus. Stok yang sudah dipecah tidak berubah.');
    }

    /** Pecah N kemasan bahan di satu gudang. */
    public function run(Request $request, ProductBreakdown $breakdown): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'times' => ['required', 'integer', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:200'],
        ], [], ['times' => 'jumlah yang dipecah']);

        $mutasi = $this->service->run($breakdown, (int) $data['times'], (string) ($data['note'] ?? ''), $request->user(), Warehouse::query()->findOrFail($data['warehouse_id']));

        return redirect()->route('breakdowns.index')->with('success', 'Dipecah: '.Format::jumlah(abs((float) $mutasi->first()->quantity)).' '.$breakdown->source->name.' menjadi '
            .$mutasi->slice(1)->map(fn ($m) => Format::jumlah($m->quantity).' '.$m->product->name)->join(', ').'.');
    }

    /** @return array<string, mixed> */
    private function formData(ProductBreakdown $breakdown): array
    {
        return [
            'breakdown' => $breakdown,
            'produk' => Product::aktif()->stockable()->with('unit:id,name')->orderBy('name')->get(['id', 'name', 'sku', 'unit_id', 'type']),
        ];
    }
}
