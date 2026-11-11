{{-- Pengaturan → Akuntansi: nyala/mati jurnal otomatis dan pemetaan akun (AccountMappingService). --}}
@use('App\Enums\AccountType')
@php
    $pilihan = function (array $definition) use ($accounts) {
        return $accounts
            ->filter(fn ($a) => in_array($a->type->value, $definition['types'], true) && (! ($definition['cash_bank'] ?? false) || $a->is_cash_bank))
            ->mapWithKeys(fn ($a) => [$a->id => $a->code.' '.$a->name]);
    };
    $kasBank = $accounts->where('is_cash_bank', true)->mapWithKeys(fn ($a) => [$a->id => $a->code.' '.$a->name]);
    $bolehUbah = auth()->user()->can('settings.accounting.update');
@endphp

<x-app-layout>
    <x-slot name="header">Akuntansi</x-slot>
    <x-slot name="description">Jurnal otomatis dari penjualan, pembelian, pembayaran, retur, stok, dan pembatalan, beserta akun yang dipakainya.</x-slot>

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
        </x-ui.form-section>

        @if ($installed)
            @foreach ($roles as $kelompok => $daftar)
                <x-ui.form-section :title="'Akun '.mb_strtolower($kelompok)" :description="$loop->first ? 'Akun yang dipilih ditandai akun sistem di Bagan Akun sehingga tidak bisa dihapus atau dinonaktifkan.' : null">
                    <div class="grid gap-5 sm:grid-cols-2">
                        @foreach ($daftar as $peran)
                            @php
                                $def = $peran['definition'];
                                $hint = trim(($def['hint'] ?? '').' Bawaan: '.$def['default'].'.');
                                if (! $peran['account'] && ! ($def['optional'] ?? false)) {
                                    $hint = "Akun bawaan {$def['default']} belum ada. Pilih akun lain atau tambahkan di Bagan Akun.";
                                }
                            @endphp
                            <x-ui.select :name="'roles['.$peran['key'].']'" :label="$def['label']" :options="$pilihan($def)" :selected="$peran['account']?->id"
                                         :placeholder="($def['optional'] ?? false) ? 'Tidak dipisah' : 'Pilih akun'" :hint="$hint"
                                         :required="! ($def['optional'] ?? false)" :disabled="! $bolehUbah" />
                        @endforeach
                    </div>
                </x-ui.form-section>
            @endforeach

            <x-ui.form-section title="Akun per metode pembayaran" description="Uang masuk/keluar lewat metode ini dicatat ke akun kas/bank yang dipilih, misal QRIS ke rekening BCA. Hanya akun bertanda kas/bank di Bagan Akun yang bisa dipilih.">
                <div class="grid gap-5 sm:grid-cols-2">
                    @foreach ($paymentMethods as $m)
                        <x-ui.select :name="'payments['.$m['method']->value.']'" :label="$m['method']->label()" :options="$kasBank" :selected="$m['account_id']"
                                     :placeholder="'Ikut akun '.($m['fallback']?->label() ?? ($m['method']->value === 'cash' ? 'Kas' : 'Bank'))" :disabled="! $bolehUbah" />
                    @endforeach
                </div>
            </x-ui.form-section>
        @endif

        @if ($bolehUbah)
            <div class="flex justify-end">
                <x-ui.button type="submit">Simpan pengaturan</x-ui.button>
            </div>
        @endif
    </form>
</x-app-layout>
