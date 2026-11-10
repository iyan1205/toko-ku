<?php

namespace App\Services\Pos;

use App\Enums\CashSessionStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseStatus;
use App\Exceptions\AturanBisnisException;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Settings\DocumentNumberService;
use App\Support\CurrentBranch;
use App\Support\Format;
use App\Support\Uang;
use Illuminate\Support\Facades\DB;

/**
 * Sesi kasir: buka dengan modal awal di satu gudang penjualan, tutup dengan menghitung uang fisik.
 *
 *   - Satu kasir hanya boleh punya satu sesi terbuka.
 *   - Penjualan, pelunasan piutang, dan pengembalian uang retur yang dicatat kasir selama sesinya
 *     terbuka ditautkan ke sesi itu (sales.cash_session_id, payments.cash_session_id).
 *   - Uang tunai seharusnya = modal awal + tunai masuk - tunai keluar. Selisih = uang fisik - seharusnya.
 *   - Wajib atau tidaknya sesi sebelum menjual diatur di Pengaturan → Kasir (setting pos.require_session).
 */
class CashSessionService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly CurrentBranch $currentBranch,
    ) {}

    public function isRequired(): bool
    {
        return setting('pos.require_session', '0') === '1';
    }

    /** Sesi yang sedang terbuka milik pengguna ini, atau null. */
    public function current(User $user): ?CashSession
    {
        return CashSession::query()->open()->where('user_id', $user->getKey())->with('warehouse')->latest('id')->first();
    }

    /**
     * Gudang penjualan bila tanpa sesi: gudang bawaan cabang yang dipilih di topbar
     * (gudang aktif cabang itu, yang bertanda bawaan dulu), atau Gudang Utama.
     */
    public function branchWarehouse(): Warehouse
    {
        $branch = $this->currentBranch->get();

        return ($branch ? Warehouse::query()->active()->where('branch_id', $branch->getKey())
            ->orderByDesc('is_default')->orderBy('name')->first() : null)
            ?? Warehouse::default();
    }

    /**
     * Konteks menjual untuk kasir ini: sesi terbuka (bila ada) dan gudang penjualannya.
     *
     * @return array{session: ?CashSession, warehouse: Warehouse}
     */
    public function saleContext(User $user): array
    {
        $session = $this->current($user);

        return [
            'session' => $session,
            'warehouse' => $session?->warehouse ?? $this->branchWarehouse(),
        ];
    }

    /** @param int $openingCash modal awal dalam sen */
    public function open(User $user, Warehouse $warehouse, int $openingCash, ?string $note = null): CashSession
    {
        if ($openingCash < 0) {
            throw new AturanBisnisException('Modal awal tidak boleh minus.');
        }

        return DB::transaction(function () use ($user, $warehouse, $openingCash, $note) {
            // Kunci baris pengguna: dua tab yang membuka sesi bersamaan tidak boleh sama-sama lolos.
            User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $berjalan = CashSession::query()->open()->where('user_id', $user->getKey())->first();
            if ($berjalan) {
                throw new AturanBisnisException("Anda masih punya sesi kasir terbuka ({$berjalan->number}). Tutup sesi itu dulu.");
            }

            $gudang = Warehouse::query()->active()->whereKey($warehouse->getKey())->first();
            if (! $gudang) {
                throw new AturanBisnisException('Gudang penjualan tidak aktif. Pilih gudang lain.');
            }

            $sekarang = now();
            $session = new CashSession([
                'number' => $this->numbers->next(DocumentType::CashSession, $sekarang),
                'user_id' => $user->getKey(),
                'warehouse_id' => $gudang->getKey(),
                'branch_id' => $gudang->branch_id ?? $this->currentBranch->get()?->getKey(),
                'opened_at' => $sekarang,
                'opening_cash' => Uang::keDesimal($openingCash),
                'opening_note' => filled($note) ? trim($note) : null,
            ]);
            $session->forceFill(['status' => CashSessionStatus::Open])->save();

            activity('penjualan')->performedOn($session)->causedBy($user)->event('created')
                ->withProperties(['gudang' => $gudang->name, 'modal_awal' => $session->opening_cash])
                ->log("Sesi kasir {$session->number} dibuka");

            return $session->load('warehouse');
        });
    }

    /**
     * Rekap sesi: per metode (masuk, keluar, bersih), tunai seharusnya di laci, dan jumlah transaksi.
     * Untuk sesi yang sudah ditutup, dipakai rekap yang disimpan saat penutupan.
     *
     * @return array{
     *   methods: list<array{method: string, label: string, in: string, out: string, net: string}>,
     *   cash_in: string, cash_out: string, expected_cash: string,
     *   sales_count: int, sales_total: string, voided_count: int, returns_count: int
     * }
     */
    public function summary(CashSession $session): array
    {
        if (! $session->isOpen() && is_array($session->summary)) {
            return $session->summary;
        }

        $refund = (new SaleReturn)->getMorphClass();
        $baris = Payment::query()->where('cash_session_id', $session->getKey())->whereNull('voided_at')
            ->selectRaw('method, CASE WHEN payable_type = ? THEN 1 ELSE 0 END AS keluar, SUM(amount) AS jumlah', [$refund])
            ->groupBy('method', 'keluar')
            ->get();

        $metode = [];
        foreach ($baris as $b) {
            $kode = $b->method instanceof PaymentMethod ? $b->method->value : (string) $b->method;
            $metode[$kode] ??= ['in' => 0, 'out' => 0];
            $metode[$kode][(int) $b->keluar === 1 ? 'out' : 'in'] += Uang::sen($b->jumlah);
        }

        // Tunai selalu tampil, walau belum ada transaksi tunai: itulah yang dihitung di laci.
        $metode = [PaymentMethod::Cash->value => $metode[PaymentMethod::Cash->value] ?? ['in' => 0, 'out' => 0]] + $metode;

        $penjualan = Sale::query()->where('cash_session_id', $session->getKey());
        $tunai = $metode[PaymentMethod::Cash->value];

        return [
            'methods' => collect($metode)->map(fn (array $m, string $kode) => [
                'method' => $kode,
                'label' => PaymentMethod::tryFrom($kode)?->label() ?? $kode,
                'in' => Uang::keDesimal($m['in']),
                'out' => Uang::keDesimal($m['out']),
                'net' => Uang::keDesimal($m['in'] - $m['out']),
            ])->values()->all(),
            'cash_in' => Uang::keDesimal($tunai['in']),
            'cash_out' => Uang::keDesimal($tunai['out']),
            'expected_cash' => Uang::keDesimal(Uang::sen($session->opening_cash) + $tunai['in'] - $tunai['out']),
            'sales_count' => (clone $penjualan)->where('status', PurchaseStatus::Completed->value)->count(),
            'sales_total' => Uang::keDesimal(Uang::sen((clone $penjualan)->where('status', PurchaseStatus::Completed->value)->sum('grand_total'))),
            'voided_count' => (clone $penjualan)->where('status', PurchaseStatus::Voided->value)->count(),
            'returns_count' => Payment::query()->where('cash_session_id', $session->getKey())->where('payable_type', $refund)->count(),
        ];
    }

    /** @param int $closingCash uang fisik yang dihitung, dalam sen */
    public function close(CashSession $session, int $closingCash, ?string $note, User $by): CashSession
    {
        if ($closingCash < 0) {
            throw new AturanBisnisException('Uang fisik tidak boleh minus.');
        }

        return DB::transaction(function () use ($session, $closingCash, $note, $by) {
            // Penjualan yang sedang disimpan mengunci sesi yang sama, jadi rekap tidak terlewat satu transaksi.
            $terkunci = CashSession::whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if (! $terkunci->isOpen()) {
                throw new AturanBisnisException("Sesi {$terkunci->number} sudah ditutup.");
            }

            $rekap = $this->summary($terkunci);
            $seharusnya = Uang::sen($rekap['expected_cash']);

            $terkunci->forceFill([
                'status' => CashSessionStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $by->getKey(),
                'expected_cash' => Uang::keDesimal($seharusnya),
                'closing_cash' => Uang::keDesimal($closingCash),
                'cash_difference' => Uang::keDesimal($closingCash - $seharusnya),
                'summary' => $rekap,
                'closing_note' => filled($note) ? trim($note) : null,
            ])->save();

            activity('penjualan')->performedOn($terkunci)->causedBy($by)->event('closed')
                ->withProperties([
                    'tunai_seharusnya' => $terkunci->expected_cash,
                    'uang_fisik' => $terkunci->closing_cash,
                    'selisih' => $terkunci->cash_difference,
                ])
                ->log("Sesi kasir {$terkunci->number} ditutup, selisih ".Format::rupiah($terkunci->cash_difference));

            $session->setRawAttributes($terkunci->getAttributes(), true);

            return $terkunci;
        });
    }

    /** Verifikasi setoran oleh atasan (izin pos.sessions.approve). */
    public function verify(CashSession $session, ?string $note, User $by): CashSession
    {
        return DB::transaction(function () use ($session, $note, $by) {
            $terkunci = CashSession::whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($terkunci->isOpen()) {
                throw new AturanBisnisException("Sesi {$terkunci->number} masih terbuka. Tutup dulu sebelum diverifikasi.");
            }

            if ($terkunci->verified_at !== null) {
                throw new AturanBisnisException("Sesi {$terkunci->number} sudah diverifikasi.");
            }

            $terkunci->forceFill([
                'verified_at' => now(),
                'verified_by' => $by->getKey(),
                'verification_note' => filled($note) ? trim($note) : null,
            ])->save();

            activity('penjualan')->performedOn($terkunci)->causedBy($by)->event('verified')
                ->log("Setoran sesi kasir {$terkunci->number} diverifikasi");

            return $terkunci;
        });
    }

    /**
     * Sesi terbuka milik pengguna yang dikunci sampai transaksi database selesai, atau null.
     * Dipakai saat menyimpan penjualan/pembayaran, supaya sesi tidak ditutup di tengah jalan.
     */
    public function lockCurrent(User $user): ?CashSession
    {
        return CashSession::query()->open()->where('user_id', $user->getKey())->lockForUpdate()->latest('id')->first();
    }
}
