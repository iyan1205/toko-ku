<?php

namespace App\Imports\Definitions;

use App\Imports\ImportDefinition;
use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerService;
use Illuminate\Support\Facades\Validator;

/** Impor pelanggan. Kode yang sudah ada → diperbarui; kode kosong → pelanggan baru (kode otomatis). */
class CustomersImport extends ImportDefinition
{
    /** @var array<string, Customer> kode huruf kecil => pelanggan (termasuk yang terhapus) */
    private array $byCode = [];

    /** @var array<string, int> kode di berkas => baris pertamanya */
    private array $seen = [];

    public function __construct(private readonly CustomerService $customers) {}

    public function key(): string
    {
        return 'customers';
    }

    public function title(): string
    {
        return 'Pelanggan';
    }

    public function indexRoute(): string
    {
        return 'customers.index';
    }

    public function matchingRule(): string
    {
        return 'Baris dengan Kode yang sudah ada memperbarui pelanggan itu. Kode kosong = pelanggan baru dengan kode otomatis (CUS-0001, …).';
    }

    public function columns(): array
    {
        return [
            'kode' => ['label' => 'Kode', 'hint' => 'Kosongkan untuk pelanggan baru.', 'example' => 'CUS-0001', 'text' => true],
            'nama' => ['label' => 'Nama', 'required' => true, 'hint' => 'Maksimal 150 karakter.', 'example' => 'Bu Siti'],
            'telepon' => ['label' => 'Telepon', 'hint' => 'Angka, spasi, + dan -.', 'example' => '081234567890', 'text' => true],
            'email' => ['label' => 'Email', 'example' => 'siti@contoh.id'],
            'alamat' => ['label' => 'Alamat', 'example' => 'Jl. Melati 5, Bekasi'],
            'catatan' => ['label' => 'Catatan', 'example' => 'Langganan katering'],
        ];
    }

    public function prepare(): void
    {
        $this->byCode = Customer::withTrashed()->get()->keyBy(fn (Customer $c) => mb_strtolower($c->code))->all();
        $this->seen = [];
    }

    public function validate(array $row, int $line): array
    {
        $data = [
            'code' => ($code = $this->text($row['kode'] ?? null)) ? mb_strtoupper($code) : null,
            'name' => $this->text($row['nama'] ?? null),
            'phone' => $this->phone($row['telepon'] ?? null),
            'email' => $this->text($row['email'] ?? null),
            'address' => $this->text($row['alamat'] ?? null),
            'note' => $this->text($row['catatan'] ?? null),
        ];

        $errors = Validator::make($data, [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['phone.regex' => 'Telepon hanya boleh berisi angka, spasi, tanda + dan -.'], [
            'name' => 'Nama', 'phone' => 'Telepon', 'email' => 'Email', 'address' => 'Alamat', 'note' => 'Catatan',
        ])->errors()->all();

        if ($data['code'] !== null) {
            $key = mb_strtolower($data['code']);
            $existing = $this->byCode[$key] ?? null;

            if ($existing === null) {
                $errors[] = "Kode {$data['code']} tidak ditemukan. Kosongkan kolom Kode untuk pelanggan baru.";
            } elseif ($existing->trashed()) {
                $errors[] = "Kode {$data['code']} milik pelanggan yang sudah dihapus.";
            } elseif ($existing->is_default) {
                $errors[] = 'Pelanggan "Umum" adalah bawaan sistem dan tidak bisa diubah lewat impor.';
            }

            if (isset($this->seen[$key])) {
                $errors[] = "Kode {$data['code']} muncul ganda (juga di baris {$this->seen[$key]}).";
            }
            $this->seen[$key] ??= $line;
        }

        return [$data, $errors];
    }

    public function persist(array $data, User $user): string
    {
        $fields = array_intersect_key($data, array_flip(['name', 'phone', 'email', 'address', 'note']));

        if ($data['code'] !== null) {
            $this->customers->perbarui($this->byCode[mb_strtolower($data['code'])], $fields);

            return 'updated';
        }

        $this->customers->buat($fields);

        return 'created';
    }
}
