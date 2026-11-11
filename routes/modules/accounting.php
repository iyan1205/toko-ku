<?php

/*
| Akuntansi: bagan akun (COA), saldo awal, jurnal umum & jurnal berulang, buku besar, neraca saldo,
| cost center, dan kunci periode. Kas & bank, laporan keuangan, dan tutup buku menyusul (docs/ROADMAP.md).
| Di-include dari routes/web.php di dalam grup middleware auth. Setiap route dijaga izin
| "{modul}.{fitur}.{aksi}" (config/permission-matrix.php). Route statis ditulis di atas
| route ber-parameter.
*/

use App\Http\Controllers\Accounting\AccountController;
use App\Http\Controllers\Accounting\BankReconciliationController;
use App\Http\Controllers\Accounting\CashBankAccountController;
use App\Http\Controllers\Accounting\CashTransactionController;
use App\Http\Controllers\Accounting\CostCenterController;
use App\Http\Controllers\Accounting\FiscalPeriodController;
use App\Http\Controllers\Accounting\GiroController;
use App\Http\Controllers\Accounting\JournalController;
use App\Http\Controllers\Accounting\LedgerController;
use App\Http\Controllers\Accounting\OpeningBalanceController;
use App\Http\Controllers\Accounting\RecurringJournalController;
use App\Http\Controllers\Accounting\TrialBalanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('akuntansi')->group(function () {
    // ---- Bagan akun ----
    Route::get('akun/ekspor', [AccountController::class, 'export'])->middleware('permission:accounting.coa.export')->name('accounts.export');
    Route::post('akun/pasang-standar', [AccountController::class, 'installStandard'])->middleware('permission:accounting.coa.create')->name('accounts.install');
    Route::resource('akun', AccountController::class)
        ->parameters(['akun' => 'account'])
        ->names('accounts')
        ->except('show')
        ->middlewareFor('index', 'permission:accounting.coa.view')
        ->middlewareFor(['create', 'store'], 'permission:accounting.coa.create')
        ->middlewareFor(['edit', 'update'], 'permission:accounting.coa.update')
        ->middlewareFor('destroy', 'permission:accounting.coa.delete');

    // ---- Jurnal umum: draf bisa diubah/diajukan/dibatalkan; jurnal posted dikoreksi lewat jurnal pembalik. ----
    Route::get('jurnal/ekspor', [JournalController::class, 'export'])->middleware('permission:accounting.journals.export')->name('journals.export');
    Route::middleware('permission:accounting.journals.create')->group(function () {
        Route::get('jurnal/buat', [JournalController::class, 'create'])->name('journals.create');
        Route::post('jurnal', [JournalController::class, 'store'])->name('journals.store');
        Route::prefix('jurnal/{journal}')->whereNumber('journal')->group(function () {
            Route::get('ubah', [JournalController::class, 'edit'])->name('journals.edit');
            Route::put('/', [JournalController::class, 'update'])->name('journals.update');
            Route::post('posting', [JournalController::class, 'post'])->name('journals.post');
            Route::post('batal', [JournalController::class, 'cancel'])->name('journals.cancel');
        });
    });
    Route::middleware('permission:accounting.journals.view')->group(function () {
        Route::get('jurnal', [JournalController::class, 'index'])->name('journals.index');
        Route::get('jurnal/{journal}', [JournalController::class, 'show'])->whereNumber('journal')->name('journals.show');
    });
    Route::post('jurnal/{journal}/balik', [JournalController::class, 'reverse'])
        ->whereNumber('journal')->middleware('permission:accounting.journals.void')->name('journals.reverse');

    // ---- Saldo awal: draf (create), posting (approve), batalkan yang sudah diposting (void). ----
    Route::get('saldo-awal', [OpeningBalanceController::class, 'index'])->middleware('permission:accounting.opening-balances.view')->name('opening-balances.index');
    Route::middleware('permission:accounting.opening-balances.create')->group(function () {
        Route::get('saldo-awal/buat', [OpeningBalanceController::class, 'create'])->name('opening-balances.create');
        Route::post('saldo-awal', [OpeningBalanceController::class, 'store'])->name('opening-balances.store');
        Route::prefix('saldo-awal/{openingBalance}')->whereNumber('openingBalance')->group(function () {
            Route::get('ubah', [OpeningBalanceController::class, 'edit'])->name('opening-balances.edit');
            Route::put('/', [OpeningBalanceController::class, 'update'])->name('opening-balances.update');
            Route::delete('/', [OpeningBalanceController::class, 'destroy'])->name('opening-balances.destroy');
        });
    });
    Route::prefix('saldo-awal/{openingBalance}')->whereNumber('openingBalance')->name('opening-balances.')->group(function () {
        Route::get('/', [OpeningBalanceController::class, 'show'])->middleware('permission:accounting.opening-balances.view')->name('show');
        Route::post('posting', [OpeningBalanceController::class, 'post'])->middleware('permission:accounting.opening-balances.approve')->name('post');
        Route::post('batal', [OpeningBalanceController::class, 'cancel'])->middleware('permission:accounting.opening-balances.void')->name('cancel');
    });

    // ---- Jurnal berulang (templat; jurnalnya dibuat scheduler akuntansi:jurnal-terjadwal). ----
    Route::post('jurnal-berulang/{recurringJournal}/jalankan', [RecurringJournalController::class, 'run'])
        ->whereNumber('recurringJournal')->middleware('permission:accounting.recurring.update')->name('recurring-journals.run');
    Route::resource('jurnal-berulang', RecurringJournalController::class)
        ->parameters(['jurnal-berulang' => 'recurringJournal'])
        ->names('recurring-journals')
        ->middlewareFor(['index', 'show'], 'permission:accounting.recurring.view')
        ->middlewareFor(['create', 'store'], 'permission:accounting.recurring.create')
        ->middlewareFor(['edit', 'update'], 'permission:accounting.recurring.update')
        ->middlewareFor('destroy', 'permission:accounting.recurring.delete');

    // ---- Buku besar dan neraca saldo (hanya jurnal posted). ----
    Route::get('buku-besar', [LedgerController::class, 'index'])->middleware('permission:accounting.ledger.view')->name('ledger.index');
    Route::get('buku-besar/ekspor', [LedgerController::class, 'export'])->middleware('permission:accounting.ledger.export')->name('ledger.export');
    Route::get('neraca-saldo', [TrialBalanceController::class, 'index'])->middleware('permission:accounting.trial-balance.view')->name('trial-balance.index');
    Route::get('neraca-saldo/ekspor', [TrialBalanceController::class, 'export'])->middleware('permission:accounting.trial-balance.export')->name('trial-balance.export');

    // ---- Cost center / departemen (dimensi laporan). ----
    Route::get('cost-center/ekspor', [CostCenterController::class, 'export'])->middleware('permission:accounting.cost-centers.export')->name('cost-centers.export');
    Route::resource('cost-center', CostCenterController::class)
        ->parameters(['cost-center' => 'costCenter'])
        ->names('cost-centers')
        ->except('show')
        ->middlewareFor('index', 'permission:accounting.cost-centers.view')
        ->middlewareFor(['create', 'store'], 'permission:accounting.cost-centers.create')
        ->middlewareFor(['edit', 'update'], 'permission:accounting.cost-centers.update')
        ->middlewareFor('destroy', 'permission:accounting.cost-centers.delete');

    // ---- Kas & bank: rekening, transaksi kas, rekonsiliasi bank, giro (Fase 10). ----
    Route::prefix('kas-bank')->group(function () {
        Route::get('rekening', [CashBankAccountController::class, 'index'])->middleware('permission:accounting.cash-bank.view')->name('cash-bank-accounts.index');
        Route::middleware('permission:accounting.cash-bank.update')->name('cash-bank-accounts.')->group(function () {
            Route::get('rekening/buat', [CashBankAccountController::class, 'create'])->name('create');
            Route::post('rekening', [CashBankAccountController::class, 'store'])->name('store');
            Route::get('rekening/{cashBankAccount}/ubah', [CashBankAccountController::class, 'edit'])->whereNumber('cashBankAccount')->name('edit');
            Route::put('rekening/{cashBankAccount}', [CashBankAccountController::class, 'update'])->whereNumber('cashBankAccount')->name('update');
        });

        Route::get('transaksi', [CashTransactionController::class, 'index'])->middleware('permission:accounting.cash-bank.view')->name('cash-transactions.index');
        Route::get('transaksi/ekspor', [CashTransactionController::class, 'export'])->middleware('permission:accounting.cash-bank.export')->name('cash-transactions.export');
        Route::middleware('permission:accounting.cash-bank.create')->name('cash-transactions.')->group(function () {
            Route::get('transaksi/buat/{jenis}', [CashTransactionController::class, 'create'])->whereIn('jenis', ['masuk', 'keluar', 'transfer'])->name('create');
            Route::post('transaksi/{jenis}', [CashTransactionController::class, 'store'])->whereIn('jenis', ['masuk', 'keluar', 'transfer'])->name('store');
            Route::get('transaksi/{cashTransaction}/ubah', [CashTransactionController::class, 'edit'])->whereNumber('cashTransaction')->name('edit');
            Route::put('transaksi/{cashTransaction}', [CashTransactionController::class, 'update'])->whereNumber('cashTransaction')->name('update');
            Route::post('transaksi/{cashTransaction}/posting', [CashTransactionController::class, 'post'])->whereNumber('cashTransaction')->name('post');
        });
        Route::get('transaksi/{cashTransaction}', [CashTransactionController::class, 'show'])->whereNumber('cashTransaction')->middleware('permission:accounting.cash-bank.view')->name('cash-transactions.show');
        Route::post('transaksi/{cashTransaction}/batal', [CashTransactionController::class, 'cancel'])->whereNumber('cashTransaction')
            ->middleware('permission:accounting.cash-bank.create|accounting.cash-bank.void')->name('cash-transactions.cancel');

        Route::get('rekonsiliasi', [BankReconciliationController::class, 'index'])->middleware('permission:accounting.reconciliation.view')->name('bank-reconciliations.index');
        Route::get('rekonsiliasi/templat', [BankReconciliationController::class, 'template'])->middleware('permission:accounting.reconciliation.view')->name('bank-reconciliations.template');
        Route::post('rekonsiliasi', [BankReconciliationController::class, 'store'])->middleware('permission:accounting.reconciliation.create')->name('bank-reconciliations.store');
        Route::prefix('rekonsiliasi/{bankStatement}')->whereNumber('bankStatement')->name('bank-reconciliations.')->group(function () {
            Route::get('/', [BankReconciliationController::class, 'show'])->middleware('permission:accounting.reconciliation.view')->name('show');
            Route::get('laporan', [BankReconciliationController::class, 'report'])->middleware('permission:accounting.reconciliation.view')->name('report');
            Route::middleware('permission:accounting.reconciliation.create')->group(function () {
                Route::post('cocokkan-otomatis', [BankReconciliationController::class, 'autoMatch'])->name('auto-match');
                Route::post('mutasi/{line}/cocokkan', [BankReconciliationController::class, 'match'])->whereNumber('line')->name('match');
                Route::delete('mutasi/{line}/cocokkan', [BankReconciliationController::class, 'unmatch'])->whereNumber('line')->name('unmatch');
                Route::post('mutasi/{line}/catat', [BankReconciliationController::class, 'record'])->whereNumber('line')->name('record');
                Route::delete('/', [BankReconciliationController::class, 'destroy'])->name('destroy');
            });
            Route::post('kunci', [BankReconciliationController::class, 'finalize'])->middleware('permission:accounting.reconciliation.approve')->name('finalize');
        });

        // Giro: izin mengikuti sisi pelunasannya (piutang/hutang), diperiksa di controller.
        Route::get('giro', [GiroController::class, 'index'])->middleware('permission:pos.receivables.view|purchasing.payables.view')->name('giros.index');
        Route::post('giro/{settlement}/cair', [GiroController::class, 'clear'])->whereNumber('settlement')->middleware('permission:pos.receivables.create|purchasing.payables.create')->name('giros.clear');
        Route::post('giro/{settlement}/tolak', [GiroController::class, 'bounce'])->whereNumber('settlement')->middleware('permission:pos.receivables.void|purchasing.payables.void')->name('giros.bounce');
    });

    // ---- Kunci periode: tutup / buka kembali per bulan. ----
    Route::get('kunci-periode', [FiscalPeriodController::class, 'index'])->middleware('permission:accounting.periods.view')->name('fiscal-periods.index');
    Route::prefix('kunci-periode/{tahun}/{bulan}')->whereNumber(['tahun', 'bulan'])->middleware('permission:accounting.periods.update')->group(function () {
        Route::post('tutup', [FiscalPeriodController::class, 'close'])->name('fiscal-periods.close');
        Route::post('buka', [FiscalPeriodController::class, 'reopen'])->name('fiscal-periods.reopen');
    });
});
