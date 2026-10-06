@php($edit = $supplier->exists)

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah supplier' : 'Tambah supplier' }}</x-slot>

    <form method="POST" action="{{ $edit ? route('suppliers.update', $supplier) : route('suppliers.store') }}"
          class="max-w-2xl space-y-5 rounded-lg border border-stone-200 bg-white p-6">
        @csrf
        @if ($edit) @method('PUT') @endif

        @if ($edit)
            <p class="text-sm text-stone-500">Kode supplier: <span class="font-medium text-stone-900">{{ $supplier->code }}</span></p>
        @else
            <p class="text-sm text-stone-500">Kode supplier dibuat otomatis (SUP-0001, SUP-0002, dan seterusnya).</p>
        @endif

        <x-isian label="Nama supplier" name="name" :value="$supplier->name" :wajib="true" maxlength="150" />

        <div class="grid gap-5 sm:grid-cols-2">
            <x-isian label="Nama kontak" name="contact_name" :value="$supplier->contact_name" maxlength="100" />
            <x-isian label="Nomor telepon" name="phone" type="tel" :value="$supplier->phone" maxlength="20" />
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <x-isian label="Email" name="email" type="email" :value="$supplier->email" maxlength="255" />
            <x-isian label="NPWP" name="npwp" :value="$supplier->npwp" maxlength="30" hint="Angka, titik, dan strip." />
        </div>

        <div>
            <label for="address" class="block text-sm font-medium text-stone-700">Alamat <span class="font-normal text-stone-400">(opsional)</span></label>
            <textarea id="address" name="address" rows="2" maxlength="1000"
                      class="mt-1 block w-full rounded-md border-stone-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600">{{ old('address', $supplier->address) }}</textarea>
            @error('address') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="note" class="block text-sm font-medium text-stone-700">Catatan <span class="font-normal text-stone-400">(opsional)</span></label>
            <textarea id="note" name="note" rows="2" maxlength="1000"
                      class="mt-1 block w-full rounded-md border-stone-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600">{{ old('note', $supplier->note) }}</textarea>
            @error('note') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex justify-end gap-2 border-t border-stone-100 pt-5">
            <a href="{{ $edit ? route('suppliers.show', $supplier) : route('suppliers.index') }}"
               class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Batal</a>
            <button type="submit"
                    class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">
                {{ $edit ? 'Simpan perubahan' : 'Simpan supplier' }}
            </button>
        </div>
    </form>
</x-app-layout>
