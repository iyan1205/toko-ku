{{-- Laporan rekonsiliasi bank (PDF, dompdf). --}}
@use('App\Support\Format')
@use('App\Support\Uang')
@php
    $rp = fn (int $sen) => Format::rupiah(Uang::keDesimal($sen));
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rekonsiliasi {{ $statement->cashBankAccount->name }}</title>
    <style>
        @page { margin: 36px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #0f172a; }
        .kepala { width: 100%; border-bottom: 1.5px solid #0f172a; padding-bottom: 8px; margin-bottom: 12px; }
        .kepala td { vertical-align: top; }
        .toko { font-size: 13px; font-weight: bold; }
        .abu { color: #475569; }
        h1 { font-size: 14px; margin: 0; text-align: right; }
        h2 { font-size: 11px; margin: 16px 0 6px; }
        .kanan { text-align: right; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #f1f5f9; text-align: left; padding: 5px; border-bottom: 1px solid #cbd5e1; }
        table.data td { padding: 5px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        table.ringkas { width: 70%; border-collapse: collapse; }
        table.ringkas td { padding: 3px 6px; }
        .tebal td { font-weight: bold; border-top: 1px solid #0f172a; }
    </style>
</head>
<body>
    <table class="kepala">
        <tr>
            <td>
                <div class="toko">{{ $store['nama_toko'] }}</div>
                @if ($store['alamat'] !== '')<div class="abu">{{ $store['alamat'] }}</div>@endif
            </td>
            <td>
                <h1>LAPORAN REKONSILIASI BANK</h1>
                <div class="kanan">{{ $statement->cashBankAccount->label() }}</div>
                <div class="kanan abu">{{ Format::tanggal($statement->period_start) }} – {{ Format::tanggal($statement->period_end) }}</div>
            </td>
        </tr>
    </table>

    <table class="ringkas">
        <tr><td>Saldo menurut rekening koran</td><td class="kanan">{{ $rp($report['bank_closing']) }}</td></tr>
        <tr><td>Ditambah: di buku, belum di rekening koran (masuk)</td><td class="kanan">{{ $rp($report['book_in']) }}</td></tr>
        <tr><td>Dikurangi: di buku, belum di rekening koran (keluar)</td><td class="kanan">({{ $rp($report['book_out']) }})</td></tr>
        <tr class="tebal"><td>Saldo bank disesuaikan</td><td class="kanan">{{ $rp($report['adjusted_bank']) }}</td></tr>
        <tr><td>&nbsp;</td><td></td></tr>
        <tr><td>Saldo menurut buku</td><td class="kanan">{{ $rp($report['book_closing']) }}</td></tr>
        <tr><td>Ditambah: di rekening koran, belum di buku (masuk)</td><td class="kanan">{{ $rp($report['bank_in']) }}</td></tr>
        <tr><td>Dikurangi: di rekening koran, belum di buku (keluar)</td><td class="kanan">({{ $rp($report['bank_out']) }})</td></tr>
        <tr class="tebal"><td>Saldo buku disesuaikan</td><td class="kanan">{{ $rp($report['adjusted_book']) }}</td></tr>
        <tr class="tebal"><td>Selisih</td><td class="kanan">{{ $rp($report['difference']) }}</td></tr>
    </table>

    @if ($report['unmatched_book']->isNotEmpty())
        <h2>Di buku, belum di rekening koran</h2>
        <table class="data">
            <tr><th>Tanggal</th><th>Jurnal</th><th>Keterangan</th><th class="kanan">Masuk</th><th class="kanan">Keluar</th></tr>
            @foreach ($report['unmatched_book'] as $l)
                <tr>
                    <td>{{ Format::tanggal($l->journal_date) }}</td><td>{{ $l->journal_number }}</td><td>{{ $l->journal_description }}</td>
                    <td class="kanan">{{ (float) $l->debit > 0 ? Format::rupiah($l->debit) : '' }}</td>
                    <td class="kanan">{{ (float) $l->credit > 0 ? Format::rupiah($l->credit) : '' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($report['unmatched_bank']->isNotEmpty())
        <h2>Di rekening koran, belum di buku</h2>
        <table class="data">
            <tr><th>Tanggal</th><th>Keterangan</th><th class="kanan">Masuk</th><th class="kanan">Keluar</th></tr>
            @foreach ($report['unmatched_bank'] as $l)
                <tr>
                    <td>{{ Format::tanggal($l->line_date) }}</td><td>{{ $l->description }}</td>
                    <td class="kanan">{{ $l->isIncoming() ? Format::rupiah($l->amount) : '' }}</td>
                    <td class="kanan">{{ $l->isIncoming() ? '' : Format::rupiah(abs((float) $l->amount)) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <p class="abu" style="margin-top: 18px">
        {{ $statement->isFinalized() ? 'Dikunci oleh '.($statement->finalizedBy?->name ?? '-').', '.Format::tanggal($statement->finalized_at, true).'.' : 'Belum dikunci.' }}
        Dicetak {{ Format::tanggal(now(), true) }}.
    </p>
</body>
</html>
