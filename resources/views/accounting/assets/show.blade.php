{{-- Kartu aset: rincian, nilai buku, riwayat dan jadwal penyusutan, pelepasan, jurnal. --}}
@use('App\Support\Format')
@use('App\Support\Uang')
@php
    $user = auth()->user();
    $baris = 'flex justify-between gap-4';
    $bisaHapus = ! $asset->isDisposed() && $asset->depreciations->isEmpty() && $user->can('accounting.assets.delete');
    $bisaLepas = ! $asset->isDisposed() && $user->can('accounting.assets.void');
@endphp

<x-app-layout>
    <x-slot name="header">{{ $asset->name }}</x-slot>
    <x-slot name="description">{{ $asset->code }} · {{ $asset->category?->name }} · {{ $asset->branch?->name }}</x-slot>
    <x-slot name="actions">
        @if ($bisaHapus)
            <x-ui.button variant="ghost" icon="trash" class="text-danger-600" x-data x-on:click="$dispatch('open-modal', 'hapus-aset')">Hapus</x-ui.button>
        @endif
        @can('accounting.assets.update')
            <x-ui.button variant="secondary" icon="pencil-square" :href="route('fixed-assets.edit', $asset)">Ubah keterangan</x-ui.button>
        @endcan
        @if ($bisaLepas)
            <x-ui.button variant="danger" icon="arrow-right-start-on-rectangle" x-data x-on:click="$dispatch('open-modal', 'lepas-aset')">Jual / hapus aset</x-ui.button>
        @endif
    </x-slot>

    @if ($asset->isDisposed())
        <x-ui.alert type="info" class="mb-6" title="{{ $asset->disposal_type === 'sold' ? 'Dijual' : 'Dihapus' }} {{ Format::tanggal($asset->disposed_at) }}">
            @if ($asset->disposal_type === 'sold')
                Harga jual @rupiah($asset->disposal_amount) ke {{ $asset->disposalCashBankAccount?->name }}.
            @endif
            {{ $asset->disposal_note }}
        </x-ui.alert>
    @endif

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Harga perolehan" :value="Format::rupiah($asset->acquisition_cost)" icon="banknotes" color="neutral" :hint="Format::tanggal($asset->acquisition_date)" />
        <x-ui.stat-card label="Akumulasi penyusutan" :value="Format::rupiah($asset->accumulated_depreciation)" icon="arrow-trending-down" color="warning" />
        <x-ui.stat-card label="Nilai buku" :value="$asset->isDisposed() ? '—' : Format::rupiah($asset->bookValue())" icon="scale" :hint="'Nilai sisa '.Format::rupiah($asset->residual_value)" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Rincian">
            <dl class="grid gap-3 text-sm">
                <div class="{{ $baris }}"><dt class="text-slate-500">Metode</dt><dd class="text-right text-slate-900">{{ $asset->method->label() }}{{ $asset->declining_rate ? ' '.(float) $asset->declining_rate.'%/tahun' : '' }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Umur ekonomis</dt><dd class="text-slate-900">{{ $asset->useful_life_months }} bulan</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Mulai disusutkan</dt><dd class="text-slate-900">{{ Format::bulan($asset->depreciation_start) }}</dd></div>
                @if ((float) $asset->opening_accumulated > 0)
                    <div class="{{ $baris }}"><dt class="text-slate-500">Akumulasi awal</dt><dd class="text-slate-900">@rupiah($asset->opening_accumulated)</dd></div>
                @endif
                <div class="{{ $baris }}"><dt class="text-slate-500">Lokasi</dt><dd class="text-right text-slate-900">{{ $asset->location ?? '—' }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Penanggung jawab</dt><dd class="text-right text-slate-900">{{ $asset->employee?->name ?? $asset->holder_name ?? '—' }}</dd></div>
                @if ($asset->serial_number)
                    <div class="{{ $baris }}"><dt class="text-slate-500">Nomor seri</dt><dd class="text-slate-900">{{ $asset->serial_number }}</dd></div>
                @endif
                @if ($asset->costCenter)
                    <div class="{{ $baris }}"><dt class="text-slate-500">Cost center</dt><dd class="text-slate-900">{{ $asset->costCenter->label() }}</dd></div>
                @endif
                <div class="{{ $baris }}"><dt class="text-slate-500">Akun aset</dt><dd class="text-right text-slate-900">{{ $asset->category?->assetAccount?->label() }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Sumber dana</dt><dd class="text-right text-slate-900">{{ $asset->creditAccount?->label() }}</dd></div>
            </dl>
        </x-ui.card>

        <div class="grid gap-6 lg:col-span-2">
            <x-ui.table :sticky="false">
                <x-slot:toolbar><h2 class="text-sm font-semibold text-slate-900">Penyusutan yang sudah dijurnal</h2></x-slot:toolbar>
                <x-slot:head>
                    <x-ui.table.th>Periode</x-ui.table.th>
                    <x-ui.table.th align="right">Penyusutan</x-ui.table.th>
                </x-slot:head>
                @forelse ($asset->depreciations as $d)
                    <tr>
                        <x-ui.table.td><a href="{{ route('depreciation-runs.show', $d->depreciation_run_id) }}" class="text-primary-700 hover:underline">{{ Format::bulan($d->period.'-01') }}</a></x-ui.table.td>
                        <x-ui.table.td align="right" numeric>@rupiah($d->amount)</x-ui.table.td>
                    </tr>
                @empty
                    <x-ui.table.empty colspan="2" icon="calendar" title="Belum disusutkan" description="Penyusutan dijurnal setiap awal bulan untuk bulan sebelumnya." />
                @endforelse
            </x-ui.table>

            @if ($schedule !== [])
                <x-ui.table :sticky="false">
                    <x-slot:toolbar><h2 class="text-sm font-semibold text-slate-900">Jadwal penyusutan berikutnya</h2></x-slot:toolbar>
                    <x-slot:head>
                        <x-ui.table.th>Periode</x-ui.table.th>
                        <x-ui.table.th align="right">Penyusutan</x-ui.table.th>
                        <x-ui.table.th align="right">Nilai buku</x-ui.table.th>
                    </x-slot:head>
                    @foreach (array_slice($schedule, 0, 24) as $row)
                        <tr>
                            <x-ui.table.td muted>{{ Format::bulan($row['period'].'-01') }}</x-ui.table.td>
                            <x-ui.table.td align="right" numeric>{{ Format::rupiah(Uang::keDesimal($row['amount'])) }}</x-ui.table.td>
                            <x-ui.table.td align="right" numeric>{{ Format::rupiah(Uang::keDesimal($row['book_value'])) }}</x-ui.table.td>
                        </tr>
                    @endforeach
                    @if (count($schedule) > 24)
                        <x-slot:footer><p class="text-sm text-slate-500">{{ count($schedule) - 24 }} bulan lagi sampai habis umur ({{ Format::bulan(end($schedule)['period'].'-01') }}).</p></x-slot:footer>
                    @endif
                </x-ui.table>
            @endif
        </div>
    </div>

    <x-ui.attachments :document="$asset" class="mt-6" />
    <x-ui.journal-links :document="$asset" class="mt-6" />

    @if ($bisaLepas)
        <x-ui.modal name="lepas-aset" title="Jual atau hapus aset" description="Penyusutan harus sudah dihitung sampai bulan sebelum tanggal pelepasan. Selisih dengan nilai buku dicatat sebagai laba atau rugi." maxWidth="lg">
            <form method="POST" action="{{ route('fixed-assets.dispose', $asset) }}" id="form-lepas" class="grid gap-4" x-data="{ jenis: 'sold' }">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select name="disposal_type" label="Jenis" required x-model="jenis" :options="['sold' => 'Dijual', 'scrapped' => 'Dihapus (rusak/hilang)']" selected="sold" />
                    <x-ui.input name="disposed_at" label="Tanggal" type="date" required :value="now()->format('Y-m-d')" :max="now()->format('Y-m-d')" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2" x-show="jenis === 'sold'">
                    <x-ui.input name="disposal_amount" label="Harga jual (Rp)" type="number" min="0" step="0.01" />
                    <x-ui.select name="disposal_cash_bank_account_id" label="Diterima di" :options="$rekening->pluck('name', 'id')" placeholder="Pilih rekening" />
                </div>
                <x-ui.input name="disposal_note" label="Catatan" maxlength="255" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
                <x-ui.button type="submit" form="form-lepas" variant="danger">Lepas aset</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
    @if ($bisaHapus)
        <x-ui.void-dialog name="hapus-aset" :action="route('fixed-assets.destroy', $asset)" :title="'Hapus '.$asset->code.'?'" confirm="Ya, hapus" label="Alasan">
            Jurnal perolehannya dibalik. Pakai ini hanya untuk aset yang salah catat; aset yang sudah tidak dipakai dilepas dengan Jual / hapus aset.
        </x-ui.void-dialog>
    @endif
</x-app-layout>
