<?php

namespace App\Http\Controllers\Settings;

use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ReceiptPreviewRequest;
use App\Http\Requests\Settings\ReceiptSettingsRequest;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Settings\DocumentNumberService;
use App\Services\Settings\ReceiptSettingsService;
use App\Services\Settings\StoreProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Struk & Printer: lebar kertas, footer, cetak otomatis, logo, NPWP, beserta pratinjau
 * struk dengan data contoh. Izin settings.receipts.* dipasang di routes/modules/settings.php.
 */
class ReceiptSettingController extends Controller
{
    public function __construct(private readonly ReceiptSettingsService $receipts) {}

    public function edit(StoreProfileService $profile): View
    {
        return view('settings.receipts', [
            'receipt' => $this->receipts->get(),
            'profile' => $profile->ambil(),
            'widths' => StoreProfileService::LEBAR_DIDUKUNG,
        ]);
    }

    public function update(ReceiptSettingsRequest $request): RedirectResponse
    {
        $this->receipts->save($request->validated(), $request->user());

        return redirect()->route('settings.receipts.edit')->with('success', 'Pengaturan struk tersimpan.');
    }

    /**
     * Struk contoh di dalam iframe halaman pengaturan. Nilai form yang belum disimpan
     * dikirim lewat query string, jadi pratinjau langsung mengikuti isian.
     */
    public function preview(ReceiptPreviewRequest $request, DocumentNumberService $numbers): View
    {
        $settings = array_merge($this->receipts->get(), array_filter($request->validated(), fn ($value) => $value !== null));

        // Footer yang dikosongkan di form akan kembali ke bawaan saat disimpan.
        if ($request->exists('footer') && blank($request->validated('footer'))) {
            $settings['footer'] = StoreProfileService::FOOTER_BAWAAN;
        }

        return view('sales.receipt', [
            'penjualan' => $this->sampleSale($request->user(), $numbers->example(DocumentType::Sale)),
            'toko' => $this->receipts->storeHeader($settings),
            'lebarMm' => (int) $settings['paper_width'],
            'footer' => (string) $settings['footer'],
            'cetakOtomatis' => false,
            'pratinjau' => true,
        ]);
    }

    /** Penjualan contoh (tidak disimpan) untuk pratinjau. */
    private function sampleSale(User $cashier, string $number): Sale
    {
        $sale = new Sale;
        $sale->forceFill([
            'number' => $number,
            'sold_at' => now(),
            'subtotal' => '64000.00',
            'item_discount' => '2000.00',
            'discount' => '0.00',
            'grand_total' => '62000.00',
            'tendered_amount' => '100000.00',
            'change_amount' => '38000.00',
            'paid_amount' => '62000.00',
            'balance' => '0.00',
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Paid,
            'status' => PurchaseStatus::Completed,
        ]);

        $sale->setRelation('user', $cashier);
        $sale->setRelation('customer', new Customer(['name' => 'Umum']));
        $sale->setRelation('items', collect([
            new SaleItem(['product_name' => 'Kopi Susu Gula Aren', 'unit_name' => 'gelas', 'quantity' => '2.000', 'unit_price' => '18000.00', 'discount' => '0.00', 'line_total' => '36000.00']),
            new SaleItem(['product_name' => 'Roti Bakar Cokelat Keju', 'unit_name' => 'porsi', 'quantity' => '1.000', 'unit_price' => '20000.00', 'discount' => '2000.00', 'line_total' => '18000.00']),
            new SaleItem(['product_name' => 'Air Mineral 600 ml', 'unit_name' => 'botol', 'quantity' => '2.000', 'unit_price' => '4000.00', 'discount' => '0.00', 'line_total' => '8000.00']),
        ]));

        return $sale;
    }
}
