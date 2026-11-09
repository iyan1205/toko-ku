<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePaymentMethodsRequest;
use App\Services\Settings\PaymentMethodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Metode pembayaran di kasir. Izin settings.payment-methods.* dipasang di routes/modules/settings.php. */
class PaymentMethodController extends Controller
{
    public function __construct(private readonly PaymentMethodService $methods) {}

    public function edit(): View
    {
        return view('settings.payment-methods', ['methods' => $this->methods->all()]);
    }

    public function update(UpdatePaymentMethodsRequest $request): RedirectResponse
    {
        $this->methods->update($request->validated('methods'), $request->user());

        return redirect()->route('settings.payment-methods.edit')
            ->with('success', 'Metode pembayaran tersimpan. Kasir memakai urutan dan nama ini mulai transaksi berikutnya.');
    }
}
