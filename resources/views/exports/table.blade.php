{{--
    PDF tabel daftar (App\Exports\ListExport). Dirender dompdf: CSS sederhana, font DejaVu Sans
    (bawaan dompdf, mendukung karakter Indonesia), tanpa aset eksternal.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 28px 28px 36px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9px; color: #0f172a; }
        .kepala { width: 100%; border-bottom: 1.5px solid #0f172a; padding-bottom: 6px; margin-bottom: 8px; }
        .kepala td { vertical-align: bottom; }
        .toko { font-size: 12px; font-weight: bold; }
        .alamat { color: #475569; }
        h1 { font-size: 13px; margin: 0; text-align: right; }
        .dicetak { color: #475569; text-align: right; }
        .filter { margin-bottom: 8px; color: #334155; }
        .filter span { margin-right: 12px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #f1f5f9; text-align: left; font-weight: bold; padding: 5px 4px; border-bottom: 1px solid #cbd5e1; }
        table.data td { padding: 4px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        table.data tr:nth-child(even) td { background: #f8fafc; }
        .kanan { text-align: right; white-space: nowrap; }
        .catatan { margin-top: 8px; color: #b45309; }
        .kosong { padding: 16px; text-align: center; color: #64748b; }
        footer { position: fixed; bottom: -22px; left: 0; right: 0; color: #94a3b8; font-size: 8px; }
    </style>
</head>
<body>
    <table class="kepala">
        <tr>
            <td>
                <div class="toko">{{ $store['nama_toko'] }}</div>
                @if ($store['alamat'] !== '')
                    <div class="alamat">{{ $store['alamat'] }}</div>
                @endif
            </td>
            <td>
                <h1>{{ $title }}</h1>
                <div class="dicetak">Dicetak {{ \App\Support\Format::tanggal(now(), true) }}</div>
            </td>
        </tr>
    </table>

    @if ($filters !== [])
        <div class="filter">
            @foreach ($filters as $label => $value)
                <span><strong>{{ $label }}:</strong> {{ $value }}</span>
            @endforeach
        </div>
    @endif

    <table class="data">
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th @class(['kanan' => in_array($column['type'] ?? 'text', ['money', 'qty'], true)])>{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $i => $value)
                        <td @class(['kanan' => in_array($columns[$i]['type'] ?? 'text', ['money', 'qty'], true)])>{{ $value }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) }}" class="kosong">Tidak ada data yang cocok dengan filter.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($truncated)
        <p class="catatan">Hanya {{ number_format($limit, 0, ',', '.') }} baris pertama yang ditampilkan. Gunakan ekspor Excel untuk data lengkap.</p>
    @endif

    <footer>{{ $title }} · {{ $store['nama_toko'] }}</footer>
</body>
</html>
