<?php

namespace App\Exports;

use App\Services\Settings\StoreProfileService;
use App\Support\Format;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\Response;

/**
 * Satu definisi untuk sebuah tabel daftar: query + filter, kolom yang bisa diurutkan, dan kolom
 * ekspor. Dipakai bersama oleh halaman daftar (paginate), ekspor Excel, dan ekspor PDF, sehingga
 * isi ekspor selalu sama dengan yang tampil di layar (filter dan urutan yang sama).
 *
 *   $list = ProductsExport::fromRequest($request, $filter);
 *   $list->paginate(15);              // halaman daftar
 *   $list->download('xlsx'|'pdf');    // tombol Ekspor
 *
 * Urutan: ?sort=kunci&dir=asc|desc. Hanya kunci di sorts() yang dipakai (whitelist); kunci lain diabaikan.
 */
abstract class ListExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    use Exportable;

    public const FORMATS = ['xlsx', 'pdf'];

    /** PDF dibatasi agar tetap cepat dibuat; Excel memuat semua baris. */
    public const PDF_LIMIT = 1000;

    protected ?string $sort = null;

    protected string $direction = 'asc';

    /** @param array<string, mixed> $filters filter yang sudah divalidasi controller, boleh berisi sort/dir */
    public function __construct(protected readonly array $filters = [])
    {
        $sort = $filters['sort'] ?? null;
        $this->sort = is_string($sort) && array_key_exists($sort, $this->sorts()) ? $sort : null;
        $this->direction = ($filters['dir'] ?? null) === 'desc' ? 'desc' : 'asc';
    }

    /** Format ekspor dari ?format=xlsx|pdf (bawaan xlsx). */
    public static function formatFrom(Request $request): string
    {
        return $request->validate(['format' => ['nullable', Rule::in(self::FORMATS)]])['format'] ?? 'xlsx';
    }

    /** @param array<string, mixed> $filters */
    public static function fromRequest(Request $request, array $filters = []): static
    {
        return new static($filters + $request->only(['sort', 'dir']));
    }

    /** Judul dokumen, misal "Daftar Produk". */
    abstract public function documentTitle(): string;

    /** Query dengan filter, tanpa urutan. Muat relasi yang dipakai columns() di sini. */
    abstract protected function baseQuery(): Builder;

    /** Urutan bila pengguna tidak memilih kolom. */
    abstract protected function defaultOrder(Builder $query): void;

    /**
     * Kolom ekspor. type menentukan format: money (Rupiah), qty (jumlah), date, datetime, text.
     *
     * @return list<array{label: string, value: Closure(Model): mixed, type?: string}>
     */
    abstract protected function columns(): array;

    /**
     * Kolom yang bisa diurutkan: kunci URL => nama kolom, atau closure(Builder, arah).
     *
     * @return array<string, string|Closure(Builder, string): mixed>
     */
    protected function sorts(): array
    {
        return [];
    }

    /**
     * Keterangan filter di kepala PDF, misal ['Gudang' => 'Gudang Utama'].
     *
     * @return array<string, string>
     */
    protected function filterSummary(): array
    {
        return [];
    }

    public function query(): Builder
    {
        $query = $this->baseQuery();

        if ($this->sort === null) {
            $this->defaultOrder($query);

            return $query;
        }

        $column = $this->sorts()[$this->sort];
        is_string($column) ? $query->orderBy($column, $this->direction) : $column($query, $this->direction);

        // Pemutus seri agar urutan (dan pagination/chunk ekspor) selalu tetap.
        return $query->orderBy($query->getModel()->getQualifiedKeyName(), $this->direction);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->query()->paginate($perPage)->withQueryString();
    }

    public function download(string $format): Response
    {
        return $format === 'pdf'
            ? $this->downloadPdf()
            : Excel::download($this, $this->filename().'.xlsx');
    }

    /** @return list<string> */
    public function headings(): array
    {
        return array_map(fn (array $column) => $column['label'], $this->columns());
    }

    /**
     * @param  Model  $row
     * @return list<mixed>
     */
    public function map($row): array
    {
        return array_map(function (array $column) use ($row) {
            $value = ($column['value'])($row);

            return match ($column['type'] ?? 'text') {
                'money', 'qty' => $value === null ? null : (float) $value,
                'date' => $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value,
                'datetime' => $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i') : $value,
                default => $value,
            };
        }, $this->columns());
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }

    /** Nama lembar Excel: maksimal 31 karakter, tanpa karakter yang dilarang Excel. */
    public function title(): string
    {
        return Str::limit(str_replace(['/', '\\', '?', '*', '[', ']', ':'], '-', $this->documentTitle()), 31, '');
    }

    protected function filename(): string
    {
        return Str::slug($this->documentTitle()).'-'.now()->format('Ymd');
    }

    protected function downloadPdf(): Response
    {
        $columns = $this->columns();
        $rows = $this->query()->limit(self::PDF_LIMIT + 1)->get();

        $pdf = Pdf::loadView('exports.table', [
            'title' => $this->documentTitle(),
            'store' => app(StoreProfileService::class)->ambil(),
            'filters' => $this->filterSummary(),
            'columns' => $columns,
            'rows' => $rows->take(self::PDF_LIMIT)->map(fn (Model $row) => array_map(
                fn (array $column) => $this->pdfValue($column, ($column['value'])($row)),
                $columns,
            )),
            'truncated' => $rows->count() > self::PDF_LIMIT,
            'limit' => self::PDF_LIMIT,
        ])->setPaper('a4', count($columns) > 6 ? 'landscape' : 'portrait');

        return $pdf->download($this->filename().'.pdf');
    }

    /** @param array{type?: string} $column */
    private function pdfValue(array $column, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return match ($column['type'] ?? 'text') {
            'money' => Format::rupiah($value),
            'qty' => Format::jumlah($value),
            'date' => Format::tanggal($value),
            'datetime' => Format::tanggal($value, true),
            default => (string) $value,
        };
    }
}
