{{-- Surat jalan transfer stok (PDF, dompdf). --}}
@use('App\Support\Format')
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Surat Jalan {{ $transfer->number }}</title>
    <style>
        @page { margin: 36px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #0f172a; }
        .kepala { width: 100%; border-bottom: 1.5px solid #0f172a; padding-bottom: 8px; margin-bottom: 12px; }
        .kepala td { vertical-align: top; }
        .toko { font-size: 13px; font-weight: bold; }
        .abu { color: #475569; }
        h1 { font-size: 15px; margin: 0; text-align: right; }
        .nomor { text-align: right; font-size: 11px; }
        .info { width: 100%; margin-bottom: 12px; }
        .info td { width: 50%; vertical-align: top; padding: 6px 8px; border: 1px solid #e2e8f0; }
        .label { color: #64748b; font-size: 9px; text-transform: uppercase; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #f1f5f9; text-align: left; padding: 6px; border-bottom: 1px solid #cbd5e1; }
        table.data td { padding: 6px; border-bottom: 1px solid #e2e8f0; }
        .kanan { text-align: right; }
        .ttd { width: 100%; margin-top: 36px; }
        .ttd td { width: 33%; text-align: center; vertical-align: top; }
        .garis { margin: 48px 16px 0; border-top: 1px solid #0f172a; padding-top: 4px; }
        .batal { color: #b91c1c; font-weight: bold; font-size: 12px; margin-bottom: 8px; }
    </style>
</head>
<body>
    <table class="kepala">
        <tr>
            <td>
                <div class="toko">{{ $store['nama_toko'] }}</div>
                @if ($store['alamat'] !== '')<div class="abu">{{ $store['alamat'] }}</div>@endif
                @if ($store['telepon'] !== '')<div class="abu">Telp. {{ $store['telepon'] }}</div>@endif
            </td>
            <td>
                <h1>SURAT JALAN</h1>
                <div class="nomor">{{ $transfer->number }}</div>
                <div class="nomor abu">{{ Format::tanggal($transfer->transfer_date) }}</div>
            </td>
        </tr>
    </table>

    @if ($transfer->dibatalkan())
        <div class="batal">DIBATALKAN: {{ $transfer->void_reason }}</div>
    @endif

    <table class="info">
        <tr>
            <td>
                <div class="label">Dari gudang</div>
                <strong>{{ $transfer->fromWarehouse->name }}</strong>
                @if ($transfer->fromWarehouse->address)<div class="abu">{{ $transfer->fromWarehouse->address }}</div>@endif
            </td>
            <td>
                <div class="label">Ke gudang</div>
                <strong>{{ $transfer->toWarehouse->name }}</strong>
                @if ($transfer->toWarehouse->address)<div class="abu">{{ $transfer->toWarehouse->address }}</div>@endif
            </td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr><th style="width: 24px">No</th><th>SKU</th><th>Produk</th><th class="kanan">Jumlah</th><th>Satuan</th></tr>
        </thead>
        <tbody>
            @foreach ($transfer->items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->sku }}</td>
                    <td>{{ $item->product_name }}</td>
                    <td class="kanan">{{ Format::jumlah($item->quantity) }}</td>
                    <td>{{ $item->unit_name }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($transfer->note)
        <p><span class="label">Catatan:</span> {{ $transfer->note }}</p>
    @endif

    <table class="ttd">
        <tr>
            <td>Dibuat oleh<div class="garis">{{ $transfer->user?->name }}</div></td>
            <td>Pengirim<div class="garis">&nbsp;</div></td>
            <td>Penerima<div class="garis">&nbsp;</div></td>
        </tr>
    </table>
</body>
</html>
