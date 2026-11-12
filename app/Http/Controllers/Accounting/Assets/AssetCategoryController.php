<?php

namespace App\Http\Controllers\Accounting\Assets;

use App\Enums\DepreciationMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\AssetCategoryRequest;
use App\Models\Account;
use App\Models\AssetCategory;
use App\Services\Assets\AssetCategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Kategori aset tetap (izin accounting.assets.*). */
class AssetCategoryController extends Controller
{
    public function __construct(private readonly AssetCategoryService $categories) {}

    public function index(): View
    {
        return view('accounting.assets.categories.index', [
            'categories' => AssetCategory::query()->with(['assetAccount', 'accumulatedAccount', 'expenseAccount'])->withCount(['assets' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('code')->get(),
        ]);
    }

    public function install(): RedirectResponse
    {
        $created = $this->categories->installStandard();

        return redirect()->route('asset-categories.index')->with('success', $created > 0 ? "{$created} kategori standar ditambahkan." : 'Semua kategori standar sudah ada.');
    }

    public function create(): View
    {
        return view('accounting.assets.categories.form', $this->formData(new AssetCategory([
            'method' => DepreciationMethod::StraightLine, 'useful_life_months' => 48, 'is_active' => true,
            'accumulated_account_id' => Account::query()->where('code', '1-2900')->value('id'),
            'expense_account_id' => Account::query()->where('code', '6-1400')->value('id'),
        ])));
    }

    public function store(AssetCategoryRequest $request): RedirectResponse
    {
        $category = AssetCategory::create($request->validated());

        return redirect()->route('asset-categories.index')->with('success', "Kategori {$category->name} ditambahkan.");
    }

    public function edit(AssetCategory $assetCategory): View
    {
        return view('accounting.assets.categories.form', $this->formData($assetCategory));
    }

    public function update(AssetCategoryRequest $request, AssetCategory $assetCategory): RedirectResponse
    {
        $assetCategory->update($request->validated());

        return redirect()->route('asset-categories.index')->with('success', "Kategori {$assetCategory->name} diperbarui. Aset yang sudah dicatat tetap memakai aturan lamanya.");
    }

    public function destroy(AssetCategory $assetCategory): RedirectResponse
    {
        $this->categories->delete($assetCategory);

        return redirect()->route('asset-categories.index')->with('success', "Kategori {$assetCategory->name} dihapus.");
    }

    /** @return array<string, mixed> */
    private function formData(AssetCategory $category): array
    {
        $postable = Account::query()->postable()->ordered()->get(['id', 'code', 'name', 'type']);

        return [
            'category' => $category,
            'assetAccounts' => $postable->filter(fn ($a) => $a->type->value === 'asset')->mapWithKeys(fn ($a) => [$a->id => $a->code.' '.$a->name]),
            'expenseAccounts' => $postable->filter(fn ($a) => in_array($a->type->value, ['expense', 'cogs'], true))->mapWithKeys(fn ($a) => [$a->id => $a->code.' '.$a->name]),
        ];
    }
}
