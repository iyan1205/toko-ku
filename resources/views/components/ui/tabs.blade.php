{{--
    Tab di sisi browser (Alpine). Isi tiap tab ditulis dengan x-ui.tab-panel yang namanya sama.
    Panah kiri/kanan berpindah tab. Nama tab harus unik di satu halaman.

    <x-ui.tabs :tabs="['umum' => 'Umum', 'harga' => 'Harga & Stok']">
        <x-ui.tab-panel name="umum">...</x-ui.tab-panel>
        <x-ui.tab-panel name="harga">...</x-ui.tab-panel>
    </x-ui.tabs>
--}}
@props(['tabs' => [], 'active' => null])

@php($active ??= array_key_first($tabs))

<div x-data="{
        tab: @js($active),
        keys: @js(array_keys($tabs)),
        pindah(arah) {
            const i = this.keys.indexOf(this.tab);
            this.tab = this.keys[(i + arah + this.keys.length) % this.keys.length];
            this.$nextTick(() => document.getElementById('tab-' + this.tab)?.focus());
        },
     }"
     {{ $attributes }}>
    <div class="border-b border-slate-200">
        <nav class="-mb-px flex gap-6 overflow-x-auto" role="tablist"
             x-on:keydown.right.prevent="pindah(1)" x-on:keydown.left.prevent="pindah(-1)">
            @foreach ($tabs as $key => $label)
                <button type="button" role="tab" id="tab-{{ $key }}" aria-controls="panel-{{ $key }}"
                        x-on:click="tab = @js($key)"
                        x-bind:aria-selected="tab === @js($key)"
                        x-bind:tabindex="tab === @js($key) ? 0 : -1"
                        x-bind:class="tab === @js($key)
                            ? 'border-primary-600 text-primary-600'
                            : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'"
                        class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition-colors focus:outline-none focus-visible:text-primary-700">
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    <div class="pt-5">
        {{ $slot }}
    </div>
</div>
