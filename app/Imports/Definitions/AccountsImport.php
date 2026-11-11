<?php

namespace App\Imports\Definitions;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Imports\ImportDefinition;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounting\AccountMappingService;
use App\Services\Accounting\AccountService;
use Illuminate\Support\Facades\Validator;

/**
 * Impor bagan akun. Kode yang sudah ada → diperbarui; kode baru → akun baru. Akun induk ditulis
 * lewat kolom Kode Induk: boleh akun yang sudah ada atau akun induk di baris sebelumnya pada berkas.
 * Aturan sama dengan formulir (AccountService): akun sistem tidak bisa diganti kelompoknya,
 * dijadikan akun induk, atau dinonaktifkan.
 */
class AccountsImport extends ImportDefinition
{
    private const TYPES = [
        'aset' => AccountType::Asset, 'asset' => AccountType::Asset, 'harta' => AccountType::Asset,
        'liabilitas' => AccountType::Liability, 'kewajiban' => AccountType::Liability, 'hutang' => AccountType::Liability, 'liability' => AccountType::Liability,
        'ekuitas' => AccountType::Equity, 'modal' => AccountType::Equity, 'equity' => AccountType::Equity,
        'pendapatan' => AccountType::Revenue, 'revenue' => AccountType::Revenue,
        'hpp' => AccountType::Cogs, 'harga pokok penjualan' => AccountType::Cogs, 'cogs' => AccountType::Cogs,
        'beban' => AccountType::Expense, 'biaya' => AccountType::Expense, 'expense' => AccountType::Expense,
    ];

    /** @var array<string, Account> */
    private array $byCode = [];

    /** @var array<string, array{type: AccountType, header: bool, line: int}> kode di berkas yang valid */
    private array $seen = [];

    /** @var list<int> */
    private array $cashBankIds = [];

    public function __construct(
        private readonly AccountService $accounts,
        private readonly AccountMappingService $mapping,
    ) {}

    public function key(): string
    {
        return 'accounts';
    }

    public function title(): string
    {
        return 'Bagan Akun';
    }

    public function indexRoute(): string
    {
        return 'accounts.index';
    }

    public function matchingRule(): string
    {
        return 'Baris dengan Kode yang sudah ada memperbarui akun itu; kode baru menjadi akun baru. Tulis akun induk sebelum akun di bawahnya.';
    }

    public function columns(): array
    {
        return [
            'kode' => ['label' => 'Kode', 'required' => true, 'hint' => 'Huruf, angka, titik, dan tanda -. Maksimal 20 karakter.', 'example' => '6-1800', 'text' => true],
            'nama' => ['label' => 'Nama', 'required' => true, 'hint' => 'Maksimal 100 karakter.', 'example' => 'Beban Kebersihan'],
            'kelompok' => ['label' => 'Kelompok', 'required' => true, 'hint' => 'Aset, Liabilitas, Ekuitas, Pendapatan, HPP, atau Beban.', 'example' => 'Beban'],
            'kode_induk' => ['label' => 'Kode Induk', 'hint' => 'Kode akun induk (kelompok). Kosongkan untuk akun teratas.', 'example' => '6-0000', 'text' => true],
            'akun_induk' => ['label' => 'Akun Induk', 'hint' => 'Ya bila akun ini hanya mengelompokkan akun lain. Bawaan: Tidak.', 'example' => 'Tidak'],
            'saldo_normal' => ['label' => 'Saldo Normal', 'hint' => 'Debit atau Kredit. Kosongkan agar mengikuti kelompoknya.', 'example' => ''],
            'kas_bank' => ['label' => 'Kas Bank', 'hint' => 'Ya untuk akun kas atau rekening bank (kelompok Aset). Bawaan: Tidak.', 'example' => 'Tidak'],
            'aktif' => ['label' => 'Aktif', 'hint' => 'Ya atau Tidak. Bawaan: Ya.', 'example' => 'Ya'],
            'deskripsi' => ['label' => 'Deskripsi', 'hint' => 'Maksimal 255 karakter.', 'example' => 'Jasa kebersihan dan sampah'],
        ];
    }

    public function maxRows(): int
    {
        return 1000;
    }

    public function prepare(): void
    {
        $this->byCode = Account::withTrashed()->get()->keyBy(fn (Account $a) => mb_strtolower($a->code))->all();
        $this->seen = [];
        $this->cashBankIds = $this->mapping->cashBankAccountIds();
    }

    public function validate(array $row, int $line): array
    {
        $code = $this->text($row['kode'] ?? null);
        $key = mb_strtolower((string) $code);
        $existing = $code !== null ? ($this->byCode[$key] ?? null) : null;
        $errors = [];

        $type = $this->type($row['kelompok'] ?? null);
        $header = $this->yesNo($row['akun_induk'] ?? null, $existing?->is_header ?? false, 'Akun Induk', $errors);
        $cashBank = $this->yesNo($row['kas_bank'] ?? null, $existing?->is_cash_bank ?? false, 'Kas Bank', $errors);
        $active = $this->yesNo($row['aktif'] ?? null, $existing?->is_active ?? true, 'Aktif', $errors);
        $normal = $this->normalBalance($row['saldo_normal'] ?? null, $errors);

        $data = [
            'code' => $code,
            'name' => $this->text($row['nama'] ?? null),
            'type' => $type?->value,
            'parent_code' => $this->text($row['kode_induk'] ?? null),
            'is_header' => $header,
            'is_cash_bank' => $cashBank,
            'is_active' => $active,
            'normal_balance' => $normal?->value ?? ($existing && $existing->type === $type ? $existing->normal_balance->value : null),
            'description' => $this->text($row['deskripsi'] ?? null),
        ];

        $errors = [...Validator::make($data, [
            'code' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-]+$/'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ], ['code.regex' => 'Kode hanya boleh berisi huruf, angka, titik, dan tanda -.'], [
            'code' => 'Kode', 'name' => 'Nama', 'description' => 'Deskripsi',
        ])->errors()->all(), ...$errors];

        if ($type === null) {
            $errors[] = 'Kelompok tidak dikenal. Pakai Aset, Liabilitas, Ekuitas, Pendapatan, HPP, atau Beban.';
        }

        if ($code !== null) {
            if (isset($this->seen[$key])) {
                $errors[] = "Kode {$code} muncul ganda (juga di baris {$this->seen[$key]['line']}).";
            }
            if ($existing?->trashed()) {
                $errors[] = "Kode {$code} milik akun yang sudah dihapus dan tidak bisa dipakai ulang.";
            }
        }

        if ($existing && $type) {
            if ($existing->is_system) {
                if ($existing->type !== $type) {
                    $errors[] = "Akun {$existing->label()} dipakai jurnal otomatis, jadi kelompoknya tidak bisa diganti.";
                }
                if ($header) {
                    $errors[] = "Akun {$existing->label()} dipakai jurnal otomatis, jadi tidak bisa dijadikan akun induk.";
                }
                if (! $active) {
                    $errors[] = "Akun {$existing->label()} dipakai jurnal otomatis, jadi tidak bisa dinonaktifkan.";
                }
            }
            if ($header && ! $existing->is_header && $existing->lines()->exists()) {
                $errors[] = "Akun {$existing->label()} sudah dipakai di jurnal, jadi tidak bisa dijadikan akun induk.";
            }
            if (! $cashBank && in_array($existing->id, $this->cashBankIds, true)) {
                $errors[] = "Akun {$existing->label()} dipakai sebagai akun kas/bank di Pengaturan → Akuntansi, jadi kolom Kas Bank harus Ya.";
            }
        }

        if ($cashBank && $type && ($type !== AccountType::Asset || $header)) {
            $errors[] = 'Kas Bank hanya untuk akun Aset yang bukan akun induk.';
        }

        if ($data['parent_code'] !== null && $type) {
            $parentKey = mb_strtolower($data['parent_code']);
            $fromFile = $this->seen[$parentKey] ?? null;
            $fromDb = $this->byCode[$parentKey] ?? null;

            if ($parentKey === $key) {
                $errors[] = 'Akun tidak bisa menjadi induk dirinya sendiri.';
            } elseif ($fromFile !== null) {
                if (! $fromFile['header']) {
                    $errors[] = "Kode induk {$data['parent_code']} bukan akun induk (baris {$fromFile['line']}).";
                } elseif ($fromFile['type'] !== $type) {
                    $errors[] = "Kode induk {$data['parent_code']} berkelompok {$fromFile['type']->label()}, sedangkan akun ini {$type->label()}.";
                }
            } elseif ($fromDb === null || $fromDb->trashed()) {
                $errors[] = "Kode induk {$data['parent_code']} tidak ditemukan. Tulis akun induknya di baris sebelumnya atau tambahkan di Bagan Akun.";
            } elseif (! $fromDb->is_header) {
                $errors[] = "Kode induk {$data['parent_code']} bukan akun induk.";
            } elseif ($fromDb->type !== $type) {
                $errors[] = "Kode induk {$data['parent_code']} berkelompok {$fromDb->type->label()}, sedangkan akun ini {$type->label()}.";
            }
        }

        if ($errors === [] && $code !== null && $type !== null) {
            $this->seen[$key] = ['type' => $type, 'header' => $header, 'line' => $line];
        }

        return [$data, $errors];
    }

    public function persist(array $data, User $user): string
    {
        $payload = [
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            'normal_balance' => $data['normal_balance'],
            'parent_id' => $data['parent_code'] !== null ? Account::query()->where('code', $data['parent_code'])->value('id') : null,
            'is_header' => $data['is_header'],
            'is_cash_bank' => $data['is_cash_bank'],
            'is_active' => $data['is_active'],
            'description' => $data['description'],
        ];

        $existing = Account::query()->where('code', $data['code'])->first();
        if ($existing !== null) {
            $this->accounts->update($existing, $payload);

            return 'updated';
        }

        $this->accounts->create($payload);

        return 'created';
    }

    private function type(mixed $value): ?AccountType
    {
        $value = mb_strtolower(trim((string) $value));

        return self::TYPES[$value] ?? AccountType::tryFrom($value);
    }

    /** @param list<string> $errors */
    private function yesNo(mixed $value, bool $default, string $label, array &$errors): bool
    {
        $value = mb_strtolower(trim((string) $value));

        return match (true) {
            $value === '' => $default,
            in_array($value, ['ya', 'y', 'yes', '1', 'true'], true) => true,
            in_array($value, ['tidak', 't', 'no', 'n', '0', 'false'], true) => false,
            default => (function () use ($label, $default, &$errors) {
                $errors[] = "{$label} harus Ya atau Tidak.";

                return $default;
            })(),
        };
    }

    /** @param list<string> $errors */
    private function normalBalance(mixed $value, array &$errors): ?NormalBalance
    {
        $value = mb_strtolower(trim((string) $value));

        return match ($value) {
            '' => null,
            'debit', 'd' => NormalBalance::Debit,
            'kredit', 'credit', 'k' => NormalBalance::Credit,
            default => (function () use (&$errors) {
                $errors[] = 'Saldo Normal harus Debit atau Kredit.';

                return null;
            })(),
        };
    }
}
