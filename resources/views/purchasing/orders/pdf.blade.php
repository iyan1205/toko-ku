{{-- Purchase order untuk dikirim ke supplier (PDF, dompdf). Draft diberi tanda DRAFT. --}}
@use('App\Support\Format')
@use('App\Enums\PurchaseOrderStatus')
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $order->number }}</title>
    <style>
        @page { margin: 36px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #0f172a; }
        .kepala { width: 100%; border-bottom: 1.5px solid #0f172a; padding-bottom: 8px; margin-bottom: 12px; }
        .kepala td { vertical-align: top; }
        .toko { font-size: 13px; font-weight: bold; }
        .abu { color: #475569; }
        h1 { font-size: 15px; margin: 0; text-align: right; }
        .kanan { text-align: right; }
        .info { width: 100%; margin-bottom: 12px; border-collapse: collapse; }
        .info td { width: 50%; vertical-align: top; padding: 6px 8px; border: 1px solid #e2e8f0; }
        .label { color: #64748b; font-size: 9px; text-transform: uppercase; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #f1f5f9; text-align: left; padding: 6px; border-bottom: 1px solid #cbd5e1; }
        table.data td { padding: 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .total { width: 45%; margin-left: 55%; margin-top: 8px; border-collapse: collapse; }
        .total td { padding: 3px 6px; }
        .total .akhir td { border-top: 1px solid #0f172a; font-weight: bold; font-size: 11px; }
        .draft { position: fixed; top: 300px; left: 120px; font-size: 90px; color: #e2e8f0; transform: rotate(-30deg); }
        .ttd { width: 100%; margin-top: 36px; }
        .ttd td { width: 50%; text-align: center; vertical-align: top; }
        .garis { margin: 48px 40px 0; border-top: 1px solid #0f172a; padding-top: 4px; }
    </style>
</head>
<body>
    @if ($order->status === PurchaseOrderStatus::Draft)
        <div class="draft">DRAFT</div>
    @endif

    <table class="kepala">
        <tr>
            <td>
                <div class="toko">{{ $store['nama_toko'] }}</div>
                @if ($store['alamat'] !== '')<div class="abu">{{ $store['alamat'] }}</div>@endif
                @if ($store['telepon'] !== '')<div class="abu">Telp. {{ $store['telepon'] }}</div>@endif
                @if (($store['npwp'] ?? '') !== '')<div class="abu">NPWP {{ $store['npwp'] }}</div>@endif
            </td>
            <td>
                <h1>PURCHASE ORDER</h1>
                <div class="kanan">{{ $order->number }}</div>
                <div class="kanan abu">{{ Format::tanggal($order->order_date) }}</div>
            </td>
        </tr>
    </table>

    <table class="info">
        <tr>
            <td>
                <div class="label">Kepada</div>
                <strong>{{ $order->supplier->name }}</strong>
                @if ($order->supplier->contact_name)<div>u.p. {{ $order->supplier->contact_name }}</div>@endif
                @if ($order->supplier->address)<div class="abu">{{ $order->supplier->address }}</div>@endif
                @if ($order->supplier->phone)<div class="abu">Telp. {{ $order->supplier->phone }}</div>@endif
            </td>
            <td>
                <div class="label">Kirim ke</div>
                <strong>{{ $order->warehouse->name }}</strong>
                @if ($order->warehouse->address)<div class="abu">{{ $order->warehouse->address }}</div>@endif
                @if ($order->expected_date)<div style="margin-top: 4px">Perkiraan tiba: <strong>{{ Format::tanggal($order->expected_date) }}</strong></div>@endif
            </td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr><th style="width: 24px">No</th><th>Produk</th><th class="kanan">Jumlah</th><th class="kanan">Harga</th><th class="kanan">Diskon</th><th class="kanan">Subtotal</th></tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->product_name }}<div class="abu">{{ $item->sku }}</div></td>
                    <td class="kanan">{{ Format::jumlah($item->quantity) }} {{ $item->unit_name }}</td>
                    <td class="kanan">{{ Format::rupiah($item->unit_price) }}</td>
                    <td class="kanan">{{ (float) $item->discount > 0 ? Format::rupiah($item->discount) : '-' }}</td>
                    <td class="kanan">{{ Format::rupiah($item->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="total">
        <tr><td>Subtotal</td><td class="kanan">{{ Format::rupiah((float) $order->subtotal - (float) $order->item_discount) }}</td></tr>
        @if ((float) $order->discount > 0)<tr><td>Diskon</td><td class="kanan">- {{ Format::rupiah($order->discount) }}</td></tr>@endif
        @if ((float) $order->tax > 0)<tr><td>Pajak</td><td class="kanan">{{ Format::rupiah($order->tax) }}</td></tr>@endif
        @if ((float) $order->other_cost > 0)<tr><td>Biaya lain</td><td class="kanan">{{ Format::rupiah($order->other_cost) }}</td></tr>@endif
        <tr class="akhir"><td>Total</td><td class="kanan">{{ Format::rupiah($order->grand_total) }}</td></tr>
    </table>

    @if ($order->note)
        <p><span class="label">Catatan:</span> {!! nl2br(e($order->note)) !!}</p>
    @endif

    <table class="ttd">
        <tr>
            <td>Dibuat oleh<div class="garis">{{ $order->user?->name }}</div></td>
            <td>Disetujui oleh<div class="garis">{{ $order->approvedBy?->name ?? ' ' }}</div></td>
        </tr>
    </table>
</body>
</html>
