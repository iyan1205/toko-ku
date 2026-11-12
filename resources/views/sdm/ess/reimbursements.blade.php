{{-- Pengajuan reimbursement sendiri dengan bukti (foto/PDF struk), dan riwayatnya. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Reimbursement Saya</x-slot>
    <x-slot name="description">Penggantian biaya kerja yang Anda bayar lebih dulu. Lampirkan foto atau PDF buktinya.</x-slot>

    @include('sdm.ess.nav')

    <div class="grid gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('ess.reimbursements.store') }}" enctype="multipart/form-data" class="lg:col-span-1" x-data="{ kirim: false }" x-on:submit="kirim = true">
            @csrf
            <x-ui.card title="Ajukan reimbursement">
                <div class="grid gap-4">
                    <x-ui.input name="expense_date" label="Tanggal biaya" type="date" required :value="now()->format('Y-m-d')" :max="now()->format('Y-m-d')" />
                    <x-ui.select name="category" label="Kategori" required :options="config('sdm.reimbursement_categories')" placeholder="Pilih kategori" />
                    <x-ui.input name="amount" label="Jumlah (Rp)" type="number" min="1000" step="1" required />
                    <x-ui.textarea name="reason" label="Keperluan" rows="3" maxlength="500" required />
                    <x-ui.field for="f-receipts" label="Bukti (struk/nota)" required hint="Foto atau PDF, maksimal 5 berkas.">
                        <input id="f-receipts" type="file" name="receipts[]" multiple required accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment"
                               class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-slate-200">
                    </x-ui.field>
                    @foreach (['receipts', 'receipts.0', 'receipts.1', 'receipts.2', 'receipts.3', 'receipts.4'] as $kunci)
                        @error($kunci)
                            <p class="text-sm text-danger-600">{{ $message }}</p>
                        @enderror
                    @endforeach
                    <x-ui.button type="submit" icon="paper-airplane" class="justify-center" x-bind:disabled="kirim">Ajukan</x-ui.button>
                </div>
            </x-ui.card>
        </form>

        <x-ui.table :paginator="$items" :sticky="false" class="lg:col-span-2">
            <x-slot:toolbar><h2 class="text-sm font-semibold text-slate-900">Riwayat reimbursement</h2></x-slot:toolbar>
            <x-slot:head>
                <x-ui.table.th>Pengajuan</x-ui.table.th>
                <x-ui.table.th>Status</x-ui.table.th>
                <x-ui.table.th align="right">Jumlah</x-ui.table.th>
                <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
            </x-slot:head>
            @forelse ($items as $r)
                <tr class="align-top">
                    <x-ui.table.td>
                        <p class="font-medium text-slate-900">{{ $r->categoryLabel() }} · {{ Format::tanggal($r->expense_date) }}</p>
                        <p class="text-xs text-slate-500">{{ $r->number }} · {{ \Illuminate\Support\Str::limit($r->reason, 60) }}</p>
                        @if ($r->attachments->isNotEmpty())
                            <p class="mt-1 flex flex-wrap gap-2 text-xs">
                                @foreach ($r->attachments as $a)
                                    <a href="{{ route('attachments.show', $a) }}" target="_blank" class="text-primary-700 hover:underline">{{ \Illuminate\Support\Str::limit($a->original_name, 24) }}</a>
                                @endforeach
                            </p>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td>
                        <x-ui.badge :color="$r->statusColor()" dot>{{ $r->statusLabel() }}</x-ui.badge>
                        @if ($r->cancel_reason && $r->is_rejected)
                            <p class="mt-1 text-xs text-slate-500">{{ $r->cancel_reason }}</p>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td align="right" numeric>@rupiah($r->amount)</x-ui.table.td>
                    <x-ui.table.td align="right">
                        @if ($r->isPending())
                            <form method="POST" action="{{ route('ess.reimbursements.cancel', $r->id) }}">
                                @csrf
                                <button type="submit" class="text-xs font-medium text-danger-600 hover:underline">Batalkan</button>
                            </form>
                        @endif
                    </x-ui.table.td>
                </tr>
            @empty
                <x-ui.table.empty colspan="4" icon="receipt-percent" title="Belum ada pengajuan reimbursement" />
            @endforelse
        </x-ui.table>
    </div>

    @include('sdm.ess.spacer')
</x-app-layout>
