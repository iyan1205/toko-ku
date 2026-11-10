<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Events\CustomerPaymentRecorded;
use App\Events\SupplierPaymentRecorded;
use App\Exceptions\AturanBisnisException;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\Settings\DocumentNumberService;
use App\Support\Format;
use App\Support\Uang;
use Illuminate\Support\Facades\DB;

/**
 * SATU-SATUNYA tempat yang mencatat uang masuk/keluar (tabel payments) dan mengubah paid_amount,
 * balance, dan payment_status karena pembayaran. Dipakai pembelian, Hutang Supplier, kasir,
 * Piutang Pelanggan, dan pengembalian uang retur penjualan.
 */
class PaymentService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /** @param int $sen jumlah dalam sen (Rupiah x100) */
    public function bayarPembelian(
        Purchase $pembelian,
        int $sen,
        PaymentMethod $metode,
        ?string $catatan,
        User $user,
        ?string $tanggal = null,
    ): Payment {
        if ($sen <= 0) {
            throw new AturanBisnisException('Jumlah pembayaran harus lebih dari nol.');
        }

        if ($metode === PaymentMethod::Receivable) {
            throw new AturanBisnisException('Metode Piutang tidak berlaku untuk pembayaran ke supplier.');
        }

        return DB::transaction(function () use ($pembelian, $sen, $metode, $catatan, $user, $tanggal) {
            // Kunci pembelian: dua pembayaran bersamaan tidak boleh sama-sama lolos dari batas sisa hutang.
            $terkunci = Purchase::whereKey($pembelian->getKey())->lockForUpdate()->firstOrFail();

            if ($terkunci->status !== PurchaseStatus::Completed) {
                throw new AturanBisnisException('Pembelian yang sudah dibatalkan tidak dapat dibayar.');
            }

            $sisa = Uang::sen($terkunci->balance);

            if ($sen > $sisa) {
                throw new AturanBisnisException(
                    'Pembayaran melebihi sisa hutang. Sisa hutang '.Format::rupiah(Uang::keDesimal($sisa)).'.'
                );
            }

            $tanggal ??= now()->toDateString();

            $pembayaran = Payment::create([
                'number' => $this->numbers->next(DocumentType::SupplierPayment, $tanggal),
                'payable_type' => $terkunci->getMorphClass(),
                'payable_id' => $terkunci->getKey(),
                'amount' => Uang::keDesimal($sen),
                'method' => $metode,
                'paid_at' => $tanggal,
                'note' => $catatan,
                'user_id' => $user->getKey(),
            ]);

            $dibayar = Uang::sen($terkunci->paid_amount) + $sen;
            $sisaBaru = $sisa - $sen;

            $terkunci->forceFill([
                'paid_amount' => Uang::keDesimal($dibayar),
                'balance' => Uang::keDesimal($sisaBaru),
                'payment_status' => PaymentStatus::dari($dibayar, $sisaBaru),
            ])->save();

            // Sinkronkan instance milik pemanggil agar tidak menampilkan angka basi.
            $pembelian->setRawAttributes($terkunci->getAttributes(), true);

            SupplierPaymentRecorded::dispatch($pembayaran, $terkunci);

            return $pembayaran;
        });
    }

    /**
     * Uang masuk dari pelanggan untuk sebuah penjualan: pembayaran saat transaksi (satu baris per
     * metode), bayar di muka (DP), dan pelunasan piutang. Untuk penjualan piutang, saldo piutang
     * pelanggan ikut berkurang. $session = sesi kasir tempat uang diterima (untuk rekap setoran).
     *
     * @param  int  $sen  jumlah yang DITERIMA untuk penjualan ini (bukan uang yang diserahkan; kembalian tidak dihitung)
     */
    public function terimaPenjualan(
        Sale $penjualan,
        int $sen,
        PaymentMethod $metode,
        ?string $catatan,
        User $user,
        ?string $tanggal = null,
        ?CashSession $session = null,
    ): Payment {
        if ($sen <= 0) {
            throw new AturanBisnisException('Jumlah pembayaran harus lebih dari nol.');
        }

        if ($metode === PaymentMethod::Receivable || $metode === PaymentMethod::Mixed) {
            throw new AturanBisnisException('Piutang bukan metode pembayaran. Pilih metode yang dipakai pelanggan membayar.');
        }

        return DB::transaction(function () use ($penjualan, $sen, $metode, $catatan, $user, $tanggal, $session) {
            $terkunci = Sale::whereKey($penjualan->getKey())->lockForUpdate()->firstOrFail();

            if ($terkunci->status !== PurchaseStatus::Completed) {
                throw new AturanBisnisException('Penjualan yang sudah dibatalkan tidak dapat dibayar.');
            }

            $sisa = Uang::sen($terkunci->balance);

            if ($sen > $sisa) {
                throw new AturanBisnisException(
                    'Pembayaran melebihi sisa piutang. Sisa piutang '.Format::rupiah(Uang::keDesimal($sisa)).'.'
                );
            }

            $tanggal ??= now()->toDateString();

            $pembayaran = Payment::create([
                'number' => $this->numbers->next(DocumentType::CustomerPayment, $tanggal),
                'payable_type' => $terkunci->getMorphClass(),
                'payable_id' => $terkunci->getKey(),
                'amount' => Uang::keDesimal($sen),
                'method' => $metode,
                'paid_at' => $tanggal,
                'note' => $catatan,
                'user_id' => $user->getKey(),
                'cash_session_id' => $session?->getKey(),
            ]);

            $dibayar = Uang::sen($terkunci->paid_amount) + $sen;
            $sisaBaru = $sisa - $sen;

            $terkunci->forceFill([
                'paid_amount' => Uang::keDesimal($dibayar),
                'balance' => Uang::keDesimal($sisaBaru),
                'payment_status' => PaymentStatus::dari($dibayar, $sisaBaru),
            ])->save();

            if ($terkunci->payment_method === PaymentMethod::Receivable) {
                $pelanggan = Customer::whereKey($terkunci->customer_id)->lockForUpdate()->firstOrFail();
                $pelanggan->forceFill(['receivable_balance' => Uang::keDesimal(Uang::sen($pelanggan->receivable_balance) - $sen)])->save();
            }

            $penjualan->setRawAttributes($terkunci->getAttributes(), true);

            CustomerPaymentRecorded::dispatch($pembayaran, $terkunci);

            return $pembayaran;
        });
    }

    /**
     * Uang yang dikembalikan ke pelanggan karena retur penjualan disetujui (uang keluar).
     * Tidak mengubah paid_amount penjualan: nilai retur dicatat di sales.returned_amount.
     *
     * @param  int  $sen  jumlah yang dikembalikan
     */
    public function refundSaleReturn(
        SaleReturn $retur,
        int $sen,
        PaymentMethod $metode,
        User $user,
        ?CashSession $session = null,
    ): Payment {
        if ($sen <= 0) {
            throw new AturanBisnisException('Jumlah pengembalian uang harus lebih dari nol.');
        }

        if ($metode === PaymentMethod::Receivable || $metode === PaymentMethod::Mixed) {
            throw new AturanBisnisException('Pilih metode pengembalian uang: tunai, transfer, atau metode lain.');
        }

        $tanggal = now()->toDateString();

        return Payment::create([
            'number' => $this->numbers->next(DocumentType::CustomerRefund, $tanggal),
            'payable_type' => $retur->getMorphClass(),
            'payable_id' => $retur->getKey(),
            'amount' => Uang::keDesimal($sen),
            'method' => $metode,
            'paid_at' => $tanggal,
            'note' => "Pengembalian uang retur {$retur->number}",
            'user_id' => $user->getKey(),
            'cash_session_id' => $session?->getKey(),
        ]);
    }
}
