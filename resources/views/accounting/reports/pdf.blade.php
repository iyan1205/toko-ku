{{-- Laporan keuangan (PDF, dompdf) dengan kop toko. --}}
@use('App\Support\Format')
@use('App\Support\Uang')
@php
    $rp = fn (?int $sen) => $sen === null ? '' : ($sen < 0 ? '('.Format::rupiah(Uang::keDesimal(-$sen)).')' : Format::rupiah(Uang::keDesimal($sen)));
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $report['title'] }}</title>
    <style>
        @page { margin: 36px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #0f172a; }
        .kepala { width: 100%; border-bottom: 1.5px solid #0f172a; padding-bottom: 8px; margin-bottom: 12px; }
        .kepala td { vertical-align: top; }
        .toko { font-size: 13px; font-weight: bold; }
        .abu { color: #475569; }
        h1 { font-size: 14px; margin: 0; text-align: right; }
        .kanan { text-align: right; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #f1f5f9; text-align: left; padding: 5px; border-bottom: 1px solid #cbd5e1; }
        table.data th.kanan { text-align: right; }
        table.data td { padding: 4px 5px; border-bottom: 1px solid #e2e8f0; }
        tr.section td { background: #f8fafc; font-weight: bold; text-transform: uppercase; font-size: 9px; color: #334155; }
        tr.total td { font-weight: bold; }
        tr.grand td { font-weight: bold; border-top: 1px solid #0f172a; }
        td.akun { padding-left: 16px; }
        .catatan { margin-top: 10px; color: #475569; }
    </style>
</head>
<body>
    <table class="kepala">
        <tr>
            <td>
                <div class="toko">{{ $store['nama_toko'] }}</div>
                @if (($store['alamat'] ?? '') !== '')<div class="abu">{{ $store['alamat'] }}</div>@endif
                @if (($store['telepon'] ?? '') !== '')<div class="abu">{{ $store['telepon'] }}</div>@endif
            </td>
            <td>
                <h1>{{ mb_strtoupper($report['title']) }}</h1>
                <div class="kanan abu">{{ $report['subtitle'] ?? '' }}</div>
            </td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th>Keterangan</th>
                @foreach ($report['columns'] as $kolom)
                    <th class="kanan">{{ $kolom }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($report['rows'] as $row)
                @if ($row['type'] === 'section')
                    <tr class="section"><td colspan="{{ count($report['columns']) + 1 }}">{{ $row['label'] }}</td></tr>
                @else
                    <tr class="{{ $row['type'] }}">
                        <td class="{{ $row['type'] === 'account' ? 'akun' : '' }}">{{ $row['label'] }}</td>
                        @foreach ($row['values'] as $nilai)
                            <td class="kanan">{{ $rp($nilai) }}</td>
                        @endforeach
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>

    @isset($report['check'])
        <p class="catatan">{{ $report['check']['message'] }}</p>
    @endisset
    @isset($report['note'])
        <p class="catatan">{{ $report['note'] }}</p>
    @endisset
    <p class="catatan">Dicetak {{ Format::tanggal(now(), true) }}</p>
</body>
</html>
