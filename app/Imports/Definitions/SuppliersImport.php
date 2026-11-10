<?php

namespace App\Imports\Definitions;

use App\Imports\ImportDefinition;
use App\Models\Supplier;
use App\Models\User;
use App\Services\SupplierService;
use Illuminate\Support\Facades\Validator;

/** Impor supplier. Kode yang sudah ada → diperbarui; kode kosong → supplier baru (kode otomatis). */
class SuppliersImport extends ImportDefinition
{
    /** @var array<string, Supplier> */
    private array $byCode = [];

    /** @var array<string, int> */
    private array $seen = [];

    public function __construct(private readonly SupplierService $suppliers) {}

    public function key(): string
    {
        return 'suppliers';
    }

    public function title(): string
    {
        return 'Supplier';
    }

    public function indexRoute(): string
    {
        return 'suppliers.index';
    }

    public function matchingRule(): string
    {
        return 'Baris dengan Kode yang sudah ada memperbarui supplier itu. Kode kosong = supplier baru dengan kode otomatis (SUP-0001, …).';
    }

    public function columns(): array
    {
        return [
            'kode' => ['label' => 'Kode', 'hint' => 'Kosongkan untuk supplier baru.', 'example' => 'SUP-0001', 'text' => true],
            'nama' => ['label' => 'Nama', 'required' => true, 'hint' => 'Maksimal 150 karakter.', 'example' => 'PT Sumber Rejeki'],
            'kontak' => ['label' => 'Kontak', 'hint' => 'Nama orang yang dihubungi.', 'example' => 'Pak Budi'],
            'telepon' => ['label' => 'Telepon', 'hint' => 'Angka, spasi, + dan -.', 'example' => '081234567890', 'text' => true],
            'email' => ['label' => 'Email', 'example' => 'order@sumber.co.id'],
            'alamat' => ['label' => 'Alamat', 'example' => 'Jl. Raya Industri 10, Cikarang'],
            'npwp' => ['label' => 'NPWP', 'hint' => 'Angka, titik, dan tanda -.', 'example' => '01.234.567.8-901.000', 'text' => true],
            'catatan' => ['label' => 'Catatan', 'example' => 'Kirim Senin & Kamis'],
        ];
    }

    public function prepare(): void
    {
        $this->byCode = Supplier::withTrashed()->get()->keyBy(fn (Supplier $s) => mb_strtolower($s->code))->all();
        $this->seen = [];
    }

    public function validate(array $row, int $line): array
    {
        $data = [
            'code' => ($code = $this->text($row['kode'] ?? null)) ? mb_strtoupper($code) : null,
            'name' => $this->text($row['nama'] ?? null),
            'contact_name' => $this->text($row['kontak'] ?? null),
            'phone' => $this->phone($row['telepon'] ?? null),
            'email' => $this->text($row['email'] ?? null),
            'address' => $this->text($row['alamat'] ?? null),
            'npwp' => $this->text($row['npwp'] ?? null),
            'note' => $this->text($row['catatan'] ?? null),
        ];

        $errors = Validator::make($data, [
            'name' => ['required', 'string', 'max:150'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'npwp' => ['nullable', 'string', 'max:30', 'regex:/^[0-9.\-]+$/'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'phone.regex' => 'Telepon hanya boleh berisi angka, spasi, tanda + dan -.',
            'npwp.regex' => 'NPWP hanya boleh berisi angka, titik, dan tanda -.',
        ], [
            'name' => 'Nama', 'contact_name' => 'Kontak', 'phone' => 'Telepon', 'email' => 'Email',
            'address' => 'Alamat', 'npwp' => 'NPWP', 'note' => 'Catatan',
        ])->errors()->all();

        if ($data['code'] !== null) {
            $key = mb_strtolower($data['code']);
            $existing = $this->byCode[$key] ?? null;

            if ($existing === null) {
                $errors[] = "Kode {$data['code']} tidak ditemukan. Kosongkan kolom Kode untuk supplier baru.";
            } elseif ($existing->trashed()) {
                $errors[] = "Kode {$data['code']} milik supplier yang sudah dihapus.";
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
        $fields = array_intersect_key($data, array_flip(['name', 'contact_name', 'phone', 'email', 'address', 'npwp', 'note']));

        if ($data['code'] !== null) {
            $this->suppliers->perbarui($this->byCode[mb_strtolower($data['code'])], $fields);

            return 'updated';
        }

        $this->suppliers->buat($fields);

        return 'created';
    }
}
