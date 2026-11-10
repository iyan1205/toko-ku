{{-- Pengaturan → Akuntansi: nyala/mati jurnal otomatis dan akun yang dipakainya (config/accounting.php). --}}
<x-app-layout>
    <x-slot name="header">Akuntansi</x-slot>
    <x-slot name="description">Jurnal otomatis dari penjualan, pembelian, pembayaran, retur, dan pembatalan.</x-slot>

    <form method="POST" action="{{ route('settings.accounting.update') }}" class="grid gap-8">
        @csrf
        @method('PUT')

        <x-ui.form-section title="Jurnal otomatis" description="Bila menyala, setiap transaksi baru langsung dibuatkan jurnal. Transaksi yang terjadi saat dimatikan tidak dijurnal.">
            @unless ($installed)
                <x-ui.alert type="warning">
                    Bagan akun belum dipasang, jadi jurnal otomatis belum berjalan.
                    @can('accounting.coa.view')
                        Pasang di <a href="{{ route('accounts.index') }}" class="font-medium underline">Akuntansi → Bagan Akun</a>.
                    @endcan
                </x-ui.alert>
            @endunless
            <x-ui.checkbox name="auto_journal" label="Buat jurnal otomatis" :checked="$enabled" unchecked-value="0"
                           hint="Gagal membuat jurnal (misal akun dinonaktifkan) tidak mengganggu transaksi; kegagalannya tercatat di Log Aktivitas." />

            <x-slot:footer>
                @can('settings.accounting.update')
                    <x-ui.button type="submit">Simpan</x-ui.button>
                @endcan
            </x-slot:footer>
        </x-ui.form-section>
    </form>

    <x-ui.table :sticky="false" class="mt-8">
        <x-slot:toolbar>
            <div>
                <h2 class="text-sm font-semibold text-slate-900">Akun yang dipakai</h2>
                <p class="text-sm text-slate-500">Ditandai "akun sistem" di Bagan Akun sehingga tidak bisa dihapus atau dinonaktifkan.</p>
            </div>
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th>Untuk</x-ui.table.th>
            <x-ui.table.th>Akun</x-ui.table.th>
        </x-slot:head>
        @foreach ($mapping as $m)
            <tr>
                <x-ui.table.td>{{ $m['role'] }}</x-ui.table.td>
                <x-ui.table.td>
                    @if ($m['account'])
                        <span class="font-mono text-xs text-slate-500">{{ $m['code'] }}</span> {{ $m['account']->name }}
                    @else
                        <x-ui.badge color="danger">{{ $m['code'] }} belum ada</x-ui.badge>
                    @endif
                </x-ui.table.td>
            </tr>
        @endforeach
    </x-ui.table>
</x-app-layout>
