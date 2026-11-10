<?php

namespace App\Listeners;

use App\Events\CustomerPaymentRecorded;
use App\Events\GoodsReceived;
use App\Events\PurchaseReturned;
use App\Events\PurchaseVoided;
use App\Events\SaleCompleted;
use App\Events\SaleReturned;
use App\Events\SaleVoided;
use App\Events\SupplierPaymentRecorded;
use App\Services\Accounting\AutoJournalService;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Jurnal otomatis untuk modul Akuntansi. Didaftarkan otomatis oleh event discovery Laravel
 * (method berawalan "handle"). Semua event-nya dikirim setelah transaksi asal tersimpan.
 *
 * Kegagalan membuat jurnal (misal akun sistem dinonaktifkan) TIDAK membatalkan atau mengganggu
 * transaksi asal: kasir tetap bisa berjualan. Kegagalan dicatat di log aplikasi dan
 * Log Aktivitas (kelompok "akuntansi") agar bisa dibuat manual.
 */
class CreateAutoJournal
{
    public function __construct(private readonly AutoJournalService $auto) {}

    public function handleSaleCompleted(SaleCompleted $event): void
    {
        $this->run("penjualan {$event->sale->number}", fn () => $this->auto->forSale($event->sale));
    }

    public function handleSaleVoided(SaleVoided $event): void
    {
        $this->run("pembatalan penjualan {$event->sale->number}", fn () => $this->auto->forSaleVoid($event->sale));
    }

    public function handleSaleReturned(SaleReturned $event): void
    {
        $this->run("retur penjualan {$event->saleReturn->number}", fn () => $this->auto->forSaleReturn($event->saleReturn));
    }

    public function handleGoodsReceived(GoodsReceived $event): void
    {
        $this->run("pembelian {$event->purchase->number}", fn () => $this->auto->forPurchase($event->purchase, $event->receipt));
    }

    public function handlePurchaseVoided(PurchaseVoided $event): void
    {
        $this->run("pembatalan pembelian {$event->purchase->number}", fn () => $this->auto->forPurchaseVoid($event->purchase));
    }

    public function handlePurchaseReturned(PurchaseReturned $event): void
    {
        $this->run("retur pembelian {$event->purchaseReturn->number}", fn () => $this->auto->forPurchaseReturn($event->purchaseReturn));
    }

    public function handleCustomerPaymentRecorded(CustomerPaymentRecorded $event): void
    {
        // Pembayaran saat transaksi sudah masuk jurnal penjualannya.
        if (! $event->atCheckout) {
            $this->run("pelunasan piutang {$event->payment->number}", fn () => $this->auto->forCustomerPayment($event->payment, $event->sale));
        }
    }

    public function handleSupplierPaymentRecorded(SupplierPaymentRecorded $event): void
    {
        // Pembayaran saat pembelian langsung sudah masuk jurnal pembeliannya.
        if (! $event->atCheckout) {
            $this->run("pembayaran hutang {$event->payment->number}", fn () => $this->auto->forSupplierPayment($event->payment, $event->purchase));
        }
    }

    private function run(string $document, Closure $callback): void
    {
        if (! $this->auto->enabled()) {
            return;
        }

        try {
            DB::transaction($callback);
        } catch (Throwable $e) {
            Log::warning("Jurnal otomatis gagal untuk {$document}", ['error' => $e->getMessage()]);

            activity('akuntansi')->event('auto_journal_failed')
                ->withProperties(['dokumen' => $document, 'pesan' => $e->getMessage()])
                ->log("Jurnal otomatis gagal dibuat untuk {$document}");
        }
    }
}
