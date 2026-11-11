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
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Settings\DocumentNumberService;
use App\Support\Format;
use App\Support\Uang;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        bool $atCheckout = false,
        ?Settlement $settlement = null,
    ): Payment {
        if ($sen <= 0) {
            throw new AturanBisnisException('Jumlah pembayaran harus lebih dari nol.');
        }

        if ($metode === PaymentMethod::Receivable) {
            throw new AturanBisnisException('Metode Piutang tidak berlaku untuk pembayaran ke supplier.');
        }

        app(FiscalPeriodService::class)->ensureOpen($tanggal ?? now());

        return DB::transaction(function () use ($pembelian, $sen, $metode, $catatan, $user, $tanggal, $atCheckout, $settlement) {
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
                'settlement_id' => $settlement?->getKey(),
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

            SupplierPaymentRecorded::dispatch($pembayaran, $terkunci, $atCheckout);

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
        bool $atCheckout = false,
        ?Settlement $settlement = null,
    ): Payment {
        if ($sen <= 0) {
            throw new AturanBisnisException('Jumlah pembayaran harus lebih dari nol.');
        }

        if ($metode === PaymentMethod::Receivable || $metode === PaymentMethod::Mixed) {
            throw new AturanBisnisException('Piutang bukan metode pembayaran. Pilih metode yang dipakai pelanggan membayar.');
        }

        app(FiscalPeriodService::class)->ensureOpen($tanggal ?? now());

        return DB::transaction(function () use ($penjualan, $sen, $metode, $catatan, $user, $tanggal, $session, $atCheckout, $settlement) {
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
                'settlement_id' => $settlement?->getKey(),
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

            CustomerPaymentRecorded::dispatch($pembayaran, $terkunci, $atCheckout);

            return $pembayaran;
        });
    }

    /**
     * Pembayaran faktur manual (faktur penjualan atau tagihan pembelian), selalu lewat Pelunasan:
     * jurnalnya dibuat untuk pelunasan itu, jadi tidak ada event per pembayaran.
     *
     * @param  int  $sen  jumlah untuk faktur ini
     */
    public function bayarFaktur(Invoice $faktur, int $sen, PaymentMethod $metode, ?string $catatan, User $user, string $tanggal, ?Settlement $settlement = null): Payment
    {
        if ($sen <= 0) {
            throw new AturanBisnisException('Jumlah pembayaran harus lebih dari nol.');
        }

        app(FiscalPeriodService::class)->ensureOpen($tanggal);

        return DB::transaction(function () use ($faktur, $sen, $metode, $catatan, $user, $tanggal, $settlement) {
            $terkunci = Invoice::withoutGlobalScopes()->whereKey($faktur->getKey())->lockForUpdate()->firstOrFail();

            if (! $terkunci->isPosted()) {
                throw new AturanBisnisException("{$terkunci->documentLabel()} berstatus {$terkunci->documentStatus()->label()} dan tidak bisa dibayar.");
            }

            $sisa = Uang::sen($terkunci->balance);
            if ($sen > $sisa) {
                throw new AturanBisnisException("Pembayaran melebihi sisa {$terkunci->number}. Sisa ".Format::rupiah(Uang::keDesimal($sisa)).'.');
            }

            $pembayaran = Payment::create([
                'number' => $this->numbers->next($terkunci->isSales() ? DocumentType::CustomerPayment : DocumentType::SupplierPayment, $tanggal),
                'payable_type' => $terkunci->getMorphClass(),
                'payable_id' => $terkunci->getKey(),
                'amount' => Uang::keDesimal($sen),
                'method' => $metode,
                'paid_at' => $tanggal,
                'note' => $catatan,
                'user_id' => $user->getKey(),
                'settlement_id' => $settlement?->getKey(),
            ]);

            $dibayar = Uang::sen($terkunci->paid_amount) + $sen;
            $sisaBaru = $sisa - $sen;
            $terkunci->forceFill([
                'paid_amount' => Uang::keDesimal($dibayar),
                'balance' => Uang::keDesimal($sisaBaru),
                'payment_status' => PaymentStatus::dari($dibayar + Uang::sen($terkunci->credited_amount), $sisaBaru),
            ])->save();

            if ($terkunci->isSales()) {
                $this->ubahPiutangPelanggan($terkunci->customer_id, -$sen);
            }

            $faktur->setRawAttributes($terkunci->getAttributes(), true);

            return $pembayaran;
        });
    }

    /**
     * Batalkan satu pembayaran (pelunasan dibatalkan atau giro ditolak): sisa dokumennya kembali,
     * begitu juga saldo piutang pelanggan. Baris payments tidak dihapus, hanya ditandai batal.
     */
    public function batalkanPembayaran(Payment $pembayaran, User $user, string $alasan): void
    {
        DB::transaction(function () use ($pembayaran, $user, $alasan) {
            $terkunci = Payment::withoutGlobalScopes()->whereKey($pembayaran->getKey())->lockForUpdate()->firstOrFail();
            if ($terkunci->dibatalkan()) {
                return;
            }

            $class = Relation::getMorphedModel($terkunci->payable_type) ?? $terkunci->payable_type;
            $dokumen = $class::withoutGlobalScopes()->whereKey($terkunci->payable_id)->lockForUpdate()->firstOrFail();
            $sen = Uang::sen($terkunci->amount);

            $dibayar = max(0, Uang::sen($dokumen->paid_amount) - $sen);
            $sisaBaru = Uang::sen($dokumen->balance) + $sen;
            $dokumen->forceFill([
                'paid_amount' => Uang::keDesimal($dibayar),
                'balance' => Uang::keDesimal($sisaBaru),
                'payment_status' => PaymentStatus::dari($dibayar + Uang::sen($dokumen->credited_amount ?? 0), $sisaBaru),
            ])->save();

            $piutang = ($dokumen instanceof Sale && $dokumen->payment_method === PaymentMethod::Receivable)
                || ($dokumen instanceof Invoice && $dokumen->isSales());
            if ($piutang) {
                $this->ubahPiutangPelanggan($dokumen->customer_id, $sen);
            }

            $terkunci->forceFill(['voided_at' => now(), 'voided_by' => $user->getKey(), 'void_reason' => mb_substr($alasan, 0, 255)])->save();
        });
    }

    /** Tambah (sen > 0) atau kurangi saldo piutang pelanggan. */
    public function ubahPiutangPelanggan(?int $customerId, int $sen): void
    {
        if ($customerId === null || $sen === 0) {
            return;
        }

        $pelanggan = Customer::withTrashed()->whereKey($customerId)->lockForUpdate()->firstOrFail();
        $pelanggan->forceFill(['receivable_balance' => Uang::keDesimal(Uang::sen($pelanggan->receivable_balance) + $sen)])->save();
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
        app(FiscalPeriodService::class)->ensureOpen($tanggal);

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
