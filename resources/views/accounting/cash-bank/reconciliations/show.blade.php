{{--
    Pencocokan satu rekening koran: mutasi bank ↔ baris jurnal akun rekening. Mutasi yang belum
    tercatat di buku (misal biaya admin, bunga) bisa langsung dicatat sebagai transaksi kas.
--}}
@use('App\Support\Format')
@use('App\Support\Uang')
@use('Illuminate\Support\Js')
@php
    $rp = fn (int $sen) => Format::rupiah(Uang::keDesimal($sen));
    $user = auth()->user();
    $terbuka = ! $statement->isFinalized();
    $bisaCocok = $terbuka && $user->can('accounting.reconciliation.create');
    $bisaCatat = $bisaCocok && $user->can('accounting.cash-bank.create');
    $input = 'block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';
    $sesuai = $report['difference'] === 0;
@endphp

<x-app-layout>
    <x-slot name="header">Rekonsiliasi {{ $statement->cashBankAccount->name }}</x-slot>
    <x-slot name="description">{{ Format::tanggal($statement->period_start) }} – {{ Format::tanggal($statement->period_end) }} · {{ $report['matched'] }} dari {{ $report['total'] }} mutasi cocok</x-slot>
    <x-slot name="actions">
        <x-ui.button variant="secondary" icon="document-text" :href="route('bank-reconciliations.report', $statement)" target="_blank">Laporan PDF</x-ui.button>
        @if ($bisaCocok)
            <x-ui.button variant="ghost" icon="trash" class="text-danger-600" x-data
                         x-on:click="$dispatch('konfirmasi-hapus', { action: {{ Js::from(route('bank-reconciliations.destroy', $statement)) }}, nama: 'rekening koran ini' })">Hapus</x-ui.button>
            <form method="POST" action="{{ route('bank-reconciliations.auto-match', $statement) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary" icon="sparkles">Cocokkan otomatis</x-ui.button>
            </form>
        @endif
        @if ($terbuka && $user->can('accounting.reconciliation.approve'))
            <form method="POST" action="{{ route('bank-reconciliations.finalize', $statement) }}">
                @csrf
                <x-ui.button type="submit" icon="lock-closed" :disabled="$report['matched'] < $report['total']">Kunci rekonsiliasi</x-ui.button>
            </form>
        @endif
    </x-slot>

    @if ($statement->isFinalized())
        <x-ui.alert type="success" class="mb-6" title="Dikunci">Oleh {{ $statement->finalizedBy?->name ?? '-' }}, {{ Format::tanggal($statement->finalized_at, true) }}.</x-ui.alert>
    @elseif ($report['matched'] < $report['total'])
        <x-ui.alert type="info" class="mb-6">Cocokkan setiap mutasi dengan pencatatan di buku, atau catat sebagai transaksi kas bila belum tercatat (misal biaya admin atau bunga). Rekonsiliasi bisa dikunci setelah semua mutasi cocok.</x-ui.alert>
    @endif

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Saldo menurut rekening koran" :value="$rp($report['bank_closing'])" icon="building-library" color="info" :hint="'Per '.Format::tanggal($statement->period_end)" />
        <x-ui.stat-card label="Saldo menurut buku" :value="$rp($report['book_closing'])" icon="book-open" color="neutral" :hint="$statement->cashBankAccount->account?->label()" />
        <x-ui.stat-card label="Selisih setelah penyesuaian" :value="$rp($report['difference'])" icon="scale" :color="$sesuai ? 'success' : 'danger'" :hint="$sesuai ? 'Sesuai' : 'Periksa mutasi yang belum cocok'" />
    </div>

    <div x-data="{ baris: null }">
        <x-ui.table :sticky="false">
            <x-slot:toolbar><h2 class="text-sm font-semibold text-slate-900">Mutasi rekening koran</h2></x-slot:toolbar>
            <x-slot:head>
                <x-ui.table.th>Tanggal</x-ui.table.th>
                <x-ui.table.th>Keterangan</x-ui.table.th>
                <x-ui.table.th align="right">Masuk</x-ui.table.th>
                <x-ui.table.th align="right">Keluar</x-ui.table.th>
                <x-ui.table.th>Di buku</x-ui.table.th>
            </x-slot:head>
            @foreach ($statement->lines->sortBy(['line_date', 'id']) as $line)
                <tr class="align-top">
                    <x-ui.table.td numeric muted>{{ Format::tanggal($line->line_date) }}</x-ui.table.td>
                    <x-ui.table.td>
                        <p class="text-slate-800">{{ $line->description }}</p>
                        @if ($line->reference)
                            <p class="text-xs text-slate-500">{{ $line->reference }}</p>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ $line->isIncoming() ? Format::rupiah($line->amount) : '' }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ $line->isIncoming() ? '' : Format::rupiah(abs((float) $line->amount)) }}</x-ui.table.td>
                    <x-ui.table.td>
                        @if ($line->isMatched())
                            <div class="flex items-center gap-2">
                                <x-ui.badge color="success" dot>{{ $line->match_type === 'auto' ? 'Cocok otomatis' : ($line->match_type === 'created' ? 'Dicatat' : 'Cocok') }}</x-ui.badge>
                                @if ($line->journalLine?->journal)
                                    <a href="{{ route('journals.show', $line->journalLine->journal) }}" class="text-sm text-primary-700 hover:underline">{{ $line->journalLine->journal->number }}</a>
                                @endif
                                @if ($bisaCocok)
                                    <form method="POST" action="{{ route('bank-reconciliations.unmatch', [$statement, $line]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs text-slate-500 hover:text-danger-600 hover:underline">Lepas</button>
                                    </form>
                                @endif
                            </div>
                        @elseif ($bisaCocok)
                            @php
                                $calon = $candidates[$line->id] ?? collect();
                            @endphp
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($calon->isNotEmpty())
                                    <form method="POST" action="{{ route('bank-reconciliations.match', [$statement, $line]) }}" class="flex items-center gap-2">
                                        @csrf
                                        <select name="journal_line_id" class="{{ $input }} max-w-xs" aria-label="Baris jurnal untuk mutasi {{ $line->description }}">
                                            @foreach ($calon as $c)
                                                <option value="{{ $c->id }}">{{ Format::tanggal($c->journal_date) }} · {{ $c->journal_number }} · {{ Format::rupiah((float) $c->debit > 0 ? $c->debit : $c->credit) }} · {{ \Illuminate\Support\Str::limit($c->journal_description, 40) }}</option>
                                            @endforeach
                                        </select>
                                        <x-ui.button type="submit" size="sm" variant="secondary">Cocokkan</x-ui.button>
                                    </form>
                                @else
                                    <span class="text-xs text-slate-500">Tidak ada pencatatan yang sesuai.</span>
                                @endif
                                @if ($bisaCatat)
                                    <x-ui.button type="button" size="sm" variant="ghost" icon="plus"
                                                 x-on:click="baris = { url: {{ Js::from(route('bank-reconciliations.record', [$statement, $line])) }}, keterangan: {{ Js::from($line->description) }}, jumlah: {{ Js::from(Format::rupiah(abs((float) $line->amount))) }}, masuk: {{ Js::from($line->isIncoming()) }} }; $dispatch('open-modal', 'catat-mutasi')">Catat</x-ui.button>
                                @endif
                            </div>
                        @else
                            <x-ui.badge color="warning" dot>Belum cocok</x-ui.badge>
                        @endif
                    </x-ui.table.td>
                </tr>
            @endforeach
        </x-ui.table>

        @if ($bisaCatat)
            <x-ui.modal name="catat-mutasi" title="Catat sebagai transaksi kas" maxWidth="lg">
                <form method="POST" x-bind:action="baris?.url" id="form-catat-mutasi" class="grid gap-4">
                    @csrf
                    <p class="text-sm text-slate-600" x-text="baris ? (baris.masuk ? 'Uang masuk ' : 'Uang keluar ') + baris.jumlah + ' — ' + baris.keterangan : ''"></p>
                    <x-ui.select name="account_id" label="Akun lawan" required :options="$accounts->mapWithKeys(fn ($a) => [$a->id => $a->code.' '.$a->name])" placeholder="Misal Beban Administrasi Bank atau Pendapatan Bunga" />
                    <x-ui.input name="description" label="Keterangan" maxlength="255" x-bind:value="baris?.keterangan" />
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
                    <x-ui.button type="submit" form="form-catat-mutasi">Catat &amp; cocokkan</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Ringkasan rekonsiliasi">
            <dl class="grid gap-2 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">Saldo rekening koran</dt><dd class="tabular-nums">{{ $rp($report['bank_closing']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">+ Di buku, belum di rekening koran (masuk)</dt><dd class="tabular-nums">{{ $rp($report['book_in']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">− Di buku, belum di rekening koran (keluar)</dt><dd class="tabular-nums">{{ $rp($report['book_out']) }}</dd></div>
                <div class="flex justify-between border-t border-slate-200 pt-2 font-medium"><dt>Saldo bank disesuaikan</dt><dd class="tabular-nums">{{ $rp($report['adjusted_bank']) }}</dd></div>
                <div class="mt-3 flex justify-between"><dt class="text-slate-500">Saldo buku</dt><dd class="tabular-nums">{{ $rp($report['book_closing']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">+ Di rekening koran, belum di buku (masuk)</dt><dd class="tabular-nums">{{ $rp($report['bank_in']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">− Di rekening koran, belum di buku (keluar)</dt><dd class="tabular-nums">{{ $rp($report['bank_out']) }}</dd></div>
                <div class="flex justify-between border-t border-slate-200 pt-2 font-medium"><dt>Saldo buku disesuaikan</dt><dd class="tabular-nums">{{ $rp($report['adjusted_book']) }}</dd></div>
                <div @class(['mt-3 flex justify-between rounded-lg px-3 py-2 font-semibold', 'bg-success-50 text-success-700' => $sesuai, 'bg-danger-50 text-danger-700' => ! $sesuai])>
                    <dt>Selisih</dt><dd class="tabular-nums">{{ $rp($report['difference']) }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.table :sticky="false">
            <x-slot:toolbar>
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">Di buku, belum ada di rekening koran</h2>
                    <p class="text-sm text-slate-500">Misal setoran dalam perjalanan atau cek yang belum dicairkan.</p>
                </div>
            </x-slot:toolbar>
            <x-slot:head>
                <x-ui.table.th>Jurnal</x-ui.table.th>
                <x-ui.table.th align="right">Masuk</x-ui.table.th>
                <x-ui.table.th align="right">Keluar</x-ui.table.th>
            </x-slot:head>
            @forelse ($report['unmatched_book'] as $l)
                <tr>
                    <x-ui.table.td>
                        <a href="{{ route('journals.show', $l->journal_id) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $l->journal_number }}</a>
                        <p class="text-xs text-slate-500">{{ Format::tanggal($l->journal_date) }} · {{ $l->journal_description }}</p>
                    </x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ (float) $l->debit > 0 ? Format::rupiah($l->debit) : '' }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ (float) $l->credit > 0 ? Format::rupiah($l->credit) : '' }}</x-ui.table.td>
                </tr>
            @empty
                <x-ui.table.empty colspan="3" icon="check-circle" title="Semua pencatatan periode ini ada di rekening koran" />
            @endforelse
        </x-ui.table>
    </div>
</x-app-layout>
