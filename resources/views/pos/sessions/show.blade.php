{{--
    Detail sesi kasir: rekap per metode pembayaran, tunai seharusnya di laci, penutupan (hitung uang fisik),
    dan verifikasi setoran oleh atasan. Bisa dicetak sebagai rekap setoran (Ctrl+P).
--}}
@use('App\Support\Format')
@php
    $user = auth()->user();
    $bisaTutup = $user->can('close', $session);
    $bisaVerifikasi = $user->can('verify', $session);
    $selisih = $session->cash_difference === null ? null : (float) $session->cash_difference;
    $baris = 'flex justify-between gap-4';
@endphp

<x-app-layout>
    <x-slot name="header">Sesi {{ $session->number }}</x-slot>
    <x-slot name="description">{{ $session->user?->name }} · {{ $session->warehouse?->name }} · {{ Format::tanggal($session->opened_at, true) }}{{ $session->closed_at ? ' s.d. '.Format::tanggal($session->closed_at, true) : '' }}</x-slot>
    <x-slot name="actions">
        <x-ui.button variant="secondary" icon="printer" x-data x-on:click="window.print()">Cetak rekap</x-ui.button>
        @if ($bisaVerifikasi)
            <x-ui.button variant="secondary" icon="check-badge" x-data x-on:click="$dispatch('open-modal', 'verifikasi-sesi')">Verifikasi setoran</x-ui.button>
        @endif
        @if ($bisaTutup)
            <x-ui.button icon="stop-circle" x-data x-on:click="$dispatch('open-modal', 'tutup-sesi')">Tutup sesi</x-ui.button>
        @endif
    </x-slot>

    @if ($session->isOpen())
        <x-ui.alert type="success" class="mb-6" title="Sesi masih terbuka">
            Angka di bawah terus bertambah selama kasir menjual. Saat tutup sesi, hitung uang fisik di laci lalu isikan; selisihnya tercatat.
        </x-ui.alert>
    @elseif ($session->verified_at)
        <x-ui.alert type="info" class="mb-6" title="Setoran sudah diverifikasi">
            Oleh {{ $session->verifiedBy?->name }} pada {{ Format::tanggal($session->verified_at, true) }}.{{ $session->verification_note ? ' Catatan: '.$session->verification_note : '' }}
        </x-ui.alert>
    @endif

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Penjualan" :value="Format::rupiah($summary['sales_total'])" icon="receipt-percent"
                        :hint="Format::jumlah($summary['sales_count']).' transaksi'.($summary['voided_count'] > 0 ? ', '.$summary['voided_count'].' dibatalkan' : '')" />
        <x-ui.stat-card label="Modal awal" :value="Format::rupiah($session->opening_cash)" icon="banknotes" color="neutral" />
        <x-ui.stat-card label="Tunai seharusnya" :value="Format::rupiah($summary['expected_cash'])" icon="calculator" color="neutral" hint="modal + tunai masuk − tunai keluar" />
        <x-ui.stat-card label="Selisih" :value="$selisih === null ? '—' : ($selisih > 0 ? '+' : '').Format::rupiah($selisih)" icon="scale"
                        :color="$selisih === null || $selisih == 0 ? 'neutral' : ($selisih > 0 ? 'success' : 'danger')"
                        :hint="$session->closing_cash !== null ? 'uang fisik '.Format::rupiah($session->closing_cash) : 'diisi saat tutup sesi'" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Rincian">
            <dl class="grid gap-3 text-sm">
                <div class="{{ $baris }}"><dt class="text-slate-500">Status</dt>
                    <dd>
                        @if ($session->isOpen())
                            <x-ui.badge color="success" dot>Terbuka</x-ui.badge>
                        @elseif ($session->verified_at)
                            <x-ui.badge color="info" dot>Diverifikasi</x-ui.badge>
                        @else
                            <x-ui.badge color="neutral" dot>Ditutup</x-ui.badge>
                        @endif
                    </dd>
                </div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Kasir</dt><dd class="text-right text-slate-900">{{ $session->user?->name }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Gudang penjualan</dt><dd class="text-right text-slate-900">{{ $session->warehouse?->name }}</dd></div>
                @if ($session->branch)
                    <div class="{{ $baris }}"><dt class="text-slate-500">Cabang</dt><dd class="text-right text-slate-900">{{ $session->branch->name }}</dd></div>
                @endif
                <div class="{{ $baris }}"><dt class="text-slate-500">Dibuka</dt><dd class="text-right text-slate-900">{{ Format::tanggal($session->opened_at, true) }}</dd></div>
                @if ($session->closed_at)
                    <div class="{{ $baris }}"><dt class="text-slate-500">Ditutup</dt><dd class="text-right text-slate-900">{{ Format::tanggal($session->closed_at, true) }}{{ $session->closedBy && $session->closed_by !== $session->user_id ? ' oleh '.$session->closedBy->name : '' }}</dd></div>
                @endif
                @if ($session->opening_note)
                    <div><dt class="text-slate-500">Catatan buka</dt><dd class="mt-1 text-slate-900">{{ $session->opening_note }}</dd></div>
                @endif
                @if ($session->closing_note)
                    <div><dt class="text-slate-500">Catatan tutup</dt><dd class="mt-1 text-slate-900">{{ $session->closing_note }}</dd></div>
                @endif
            </dl>
        </x-ui.card>

        <div class="grid gap-6 lg:col-span-2">
            <x-ui.card title="Rekap per metode pembayaran" :padding="false">
                <x-ui.table :sticky="false">
                    <x-slot:head>
                        <x-ui.table.th>Metode</x-ui.table.th>
                        <x-ui.table.th align="right">Masuk</x-ui.table.th>
                        <x-ui.table.th align="right">Keluar (retur)</x-ui.table.th>
                        <x-ui.table.th align="right">Bersih</x-ui.table.th>
                    </x-slot:head>
                    @foreach ($summary['methods'] as $m)
                        <tr>
                            <x-ui.table.td class="font-medium text-slate-900">{{ $m['label'] }}</x-ui.table.td>
                            <x-ui.table.td align="right" numeric>@rupiah($m['in'])</x-ui.table.td>
                            <x-ui.table.td align="right" numeric muted>{{ (float) $m['out'] > 0 ? '− '.Format::rupiah($m['out']) : '—' }}</x-ui.table.td>
                            <x-ui.table.td align="right" numeric class="font-medium text-slate-900">@rupiah($m['net'])</x-ui.table.td>
                        </tr>
                    @endforeach
                    <x-slot:footer>
                        <p class="text-sm text-slate-500">Termasuk pelunasan piutang yang diterima kasir selama sesi ini. Penjualan yang dibatalkan tidak dihitung.</p>
                    </x-slot:footer>
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Transaksi dalam sesi ini" :padding="false" class="print:hidden">
                @if ($sales->isEmpty())
                    <x-ui.empty-state icon="receipt-percent" title="Belum ada transaksi" description="Penjualan selama sesi ini terbuka akan tampil di sini." />
                @else
                    <x-ui.table :sticky="false">
                        <x-slot:head>
                            <x-ui.table.th>Nomor</x-ui.table.th>
                            <x-ui.table.th>Waktu</x-ui.table.th>
                            <x-ui.table.th>Metode</x-ui.table.th>
                            <x-ui.table.th align="right">Total</x-ui.table.th>
                        </x-slot:head>
                        @foreach ($sales as $p)
                            <tr @class(['text-slate-400' => $p->dibatalkan()])>
                                <x-ui.table.td>
                                    <a href="{{ route('sales.show', $p) }}" @class(['font-medium hover:underline', 'text-slate-900' => ! $p->dibatalkan(), 'line-through' => $p->dibatalkan()])>{{ $p->number }}</a>
                                    <p class="text-xs text-slate-500">{{ $p->customer?->name }}</p>
                                </x-ui.table.td>
                                <x-ui.table.td numeric muted>{{ $p->sold_at->format('H:i') }}</x-ui.table.td>
                                <x-ui.table.td muted>{{ $p->payment_method->label() }}</x-ui.table.td>
                                <x-ui.table.td align="right" numeric>@rupiah($p->grand_total)</x-ui.table.td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                    @if ($sales->count() >= 50)
                        <p class="px-6 py-3 text-sm text-slate-500"><a href="{{ route('sales.index', ['sesi' => $session->id]) }}" class="text-primary-700 hover:underline">Lihat semua transaksi sesi ini</a></p>
                    @endif
                @endif
            </x-ui.card>
        </div>
    </div>

    @if ($bisaTutup)
        <x-ui.modal name="tutup-sesi" title="Tutup sesi {{ $session->number }}" :show="$errors->hasAny(['closing_cash', 'closing_note'])"
                    description="Hitung semua uang tunai di laci (termasuk modal awal), lalu isi jumlahnya.">
            <form id="form-tutup-sesi" method="POST" action="{{ route('pos.sessions.close', $session) }}" class="grid gap-4"
                  x-data="{ fisik: {{ \Illuminate\Support\Js::from(old('closing_cash', '')) }}, seharusnya: {{ (float) $summary['expected_cash'] }} }">
                @csrf
                <div class="rounded-lg bg-slate-50 p-4 text-sm">
                    <div class="{{ $baris }}"><span class="text-slate-500">Tunai seharusnya</span><span class="font-semibold tabular-nums text-slate-900">@rupiah($summary['expected_cash'])</span></div>
                    <div class="{{ $baris }} mt-1" x-show="fisik !== ''">
                        <span class="text-slate-500">Selisih</span>
                        <span class="font-semibold tabular-nums"
                              :class="Number(fisik) - seharusnya === 0 ? 'text-slate-900' : (Number(fisik) - seharusnya > 0 ? 'text-success-700' : 'text-danger-600')"
                              x-text="(Number(fisik) - seharusnya > 0 ? '+' : '') + 'Rp ' + (Number(fisik) - seharusnya).toLocaleString('id-ID')"></span>
                    </div>
                </div>
                <x-ui.input name="closing_cash" label="Uang fisik di laci" type="number" prefix="Rp" min="0" step="any" inputmode="decimal" required x-model="fisik" />
                <x-ui.input name="closing_note" label="Catatan" maxlength="255" placeholder="Misal penyebab selisih" />
                <p class="text-xs text-slate-500">Setelah ditutup, penjualan berikutnya tidak masuk ke sesi ini. Buka sesi baru untuk shift berikutnya.</p>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
                <x-ui.button type="submit" form="form-tutup-sesi" icon="stop-circle">Tutup sesi</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($bisaVerifikasi)
        <x-ui.modal name="verifikasi-sesi" title="Verifikasi setoran {{ $session->number }}" :show="$errors->has('verification_note')"
                    description="Tandai bahwa uang setoran sudah diterima dan dicocokkan.">
            <form id="form-verifikasi-sesi" method="POST" action="{{ route('pos.sessions.verify', $session) }}" class="grid gap-4">
                @csrf
                <x-ui.input name="verification_note" label="Catatan" maxlength="255" placeholder="Opsional" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
                <x-ui.button type="submit" form="form-verifikasi-sesi" icon="check-badge">Verifikasi</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</x-app-layout>
