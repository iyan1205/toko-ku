<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Exceptions\AturanBisnisException;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\User;
use App\Support\Format;
use App\Support\Uang;
use Illuminate\Support\Facades\DB;

/**
 * SATU-SATUNYA tempat yang mengubah paid_amount, balance, dan payment_status pembelian.
 * Dipakai saat pembayaran awal di formulir pembelian, dan nanti oleh modul Hutang (Tahap 7).
 */
class PaymentService
{
    public function __construct(private readonly KodeService $kode)
    {
    }

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
                    'Pembayaran melebihi sisa hutang. Sisa hutang ' . Format::rupiah(Uang::keDesimal($sisa)) . '.'
                );
            }

            $tanggal ??= now()->toDateString();

            $pembayaran = Payment::create([
                'number' => $this->kode->berikutnya(Payment::class, 'number', 'PAY-' . date('Ymd', strtotime($tanggal)) . '-', 4),
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

            return $pembayaran;
        });
    }
}
