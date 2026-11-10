<?php

/*
|--------------------------------------------------------------------------
| Lampiran dokumen (trait App\Models\Concerns\HasAttachments)
|--------------------------------------------------------------------------
| types: alias morph dokumen => izin untuk melihat (view) dan menambah/menghapus (update)
| lampirannya. Menambah dokumen: pakai HasAttachments di modelnya, daftarkan aliasnya di
| morphMap (AppServiceProvider) dan di sini, lalu tampilkan <x-ui.attachments :document="..." />.
|
| max_kb dibatasi juga oleh upload_max_filesize & post_max_size PHP (lihat docs/DEPLOY.md).
*/

return [
    'disk' => 'local',

    'max_kb' => 10240,

    'mimes' => ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'xlsx', 'xls', 'csv', 'doc', 'docx'],

    'types' => [
        'purchase_order' => ['label' => 'Purchase order', 'view' => 'purchasing.orders.view', 'update' => 'purchasing.orders.update'],
        'purchase' => ['label' => 'Faktur pembelian', 'view' => 'purchasing.invoices.view', 'update' => 'purchasing.invoices.create'],
        'goods_receipt' => ['label' => 'Penerimaan barang', 'view' => 'purchasing.receipts.view', 'update' => 'purchasing.receipts.create'],
        'journal' => ['label' => 'Jurnal', 'view' => 'accounting.journals.view', 'update' => 'accounting.journals.create'],
    ],
];
