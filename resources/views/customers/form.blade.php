@php($edit = $customer->exists)

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah pelanggan' : 'Tambah pelanggan' }}</x-slot>

    <form method="POST" action="{{ $edit ? route('customers.update', $customer) : route('customers.store') }}"
          class="max-w-2xl space-y-5 rounded-lg border border-stone-200 bg-white p-6">
        @csrf
        @if ($edit) @method('PUT') @endif

        @if ($edit)
            <p class="text-sm text-stone-500">Kode pelanggan: <span class="font-medium text-stone-900">{{ $customer->code }}</span></p>
        @else
            <p class="text-sm text-stone-500">Kode pelanggan dibuat otomatis (CUS-0001, CUS-0002, dan seterusnya).</p>
        @endif

        <x-isian label="Nama pelanggan" name="name" :value="$customer->name" :wajib="true" maxlength="150" />

        <div class="grid gap-5 sm:grid-cols-2">
            <x-isian label="Nomor HP" name="phone" type="tel" :value="$customer->phone" maxlength="20" />
            <x-isian label="Email" name="email" type="email" :value="$customer->email" maxlength="255" />
        </div>

        <div>
            <label for="address" class="block text-sm font-medium text-stone-700">Alamat <span class="font-normal text-stone-400">(opsional)</span></label>
            <textarea id="address" name="address" rows="2" maxlength="1000"
                      class="mt-1 block w-full rounded-md border-stone-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600">{{ old('address', $customer->address) }}</textarea>
            @error('address') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="note" class="block text-sm font-medium text-stone-700">Catatan <span class="font-normal text-stone-400">(opsional)</span></label>
            <textarea id="note" name="note" rows="2" maxlength="1000"
                      class="mt-1 block w-full rounded-md border-stone-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600">{{ old('note', $customer->note) }}</textarea>
            @error('note') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <p class="rounded-md bg-stone-50 px-4 py-3 text-sm text-stone-600">
            Saldo piutang tidak diisi manual. Saldo bertambah dari penjualan dengan metode Piutang dan berkurang saat pelanggan membayar,
            sehingga selalu ada riwayatnya.
        </p>

        <div class="flex justify-end gap-2 border-t border-stone-100 pt-5">
            <a href="{{ $edit ? route('customers.show', $customer) : route('customers.index') }}"
               class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Batal</a>
            <button type="submit"
                    class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">
                {{ $edit ? 'Simpan perubahan' : 'Simpan pelanggan' }}
            </button>
        </div>
    </form>
</x-app-layout>
