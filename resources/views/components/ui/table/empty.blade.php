{{--
    Baris "data kosong" di dalam tabel. Slot (opsional) untuk tombol aksi.

    <x-ui.table.empty colspan="5" title="Tidak ada produk yang cocok" description="Ubah kata kunci atau filter." />
--}}
@props(['colspan', 'icon' => 'inbox', 'title' => 'Belum ada data', 'description' => null])

<tr class="hover:!bg-white">
    <td colspan="{{ $colspan }}">
        <x-ui.empty-state :icon="$icon" :title="$title" :description="$description">{{ $slot }}</x-ui.empty-state>
    </td>
</tr>
