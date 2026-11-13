{{-- Rekap absensi (PDF, dompdf) dengan kop toko. --}}
@use('App\Exports\Sdm\AttendanceRecapExport')
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rekap Absensi</title>
    <style>
        @page { margin: 28px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5px; color: #0f172a; }
        .kepala { width: 100%; border-bottom: 1.5px solid #0f172a; padding-bottom: 6px; margin-bottom: 10px; }
        .kepala td { vertical-align: top; }
        .toko { font-size: 12px; font-weight: bold; }
        .abu { color: #475569; }
        h1 { font-size: 13px; margin: 0; text-align: right; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #f1f5f9; padding: 4px 3px; border-bottom: 1px solid #cbd5e1; text-align: left; }
        table.data td { padding: 3px; border-bottom: 1px solid #e2e8f0; }
        .kanan { text-align: right !important; }
    </style>
</head>
<body>
    <table class="kepala">
        <tr>
            <td>
                <div class="toko">{{ $store['nama_toko'] ?? '' }}</div>
                @if (($store['alamat'] ?? '') !== '')<div class="abu">{{ $store['alamat'] }}</div>@endif
            </td>
            <td><h1>Rekap Absensi</h1><div class="abu" style="text-align: right">{{ $periodLabel }}</div></td>
        </tr>
    </table>
    <table class="data">
        <thead>
            <tr>
                @foreach ($columns as $c)
                    <th @class(['kanan' => $c['type'] !== 'text'])>{{ $c['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($summaries as $s)
                @php
                    $row = AttendanceRecapExport::row($s);
                @endphp
                <tr>
                    @foreach ($columns as $c)
                        <td @class(['kanan' => $c['type'] !== 'text'])>{{ AttendanceRecapExport::display($c, $row[$c['key']]) }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
