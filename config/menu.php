<?php

/*
| Daftar menu sidebar. 'permission' boleh string, array (salah satu cukup), atau null (semua pengguna login).
| Item baru muncul otomatis setelah route-nya didaftarkan pada tahap terkait.
*/
return [
    ['label' => 'Dashboard',    'route' => 'dashboard',          'active' => 'dashboard',          'icon' => '📊', 'permission' => null],
    ['label' => 'Kasir',        'route' => 'pos.index',          'active' => 'pos.*',              'icon' => '🛒', 'permission' => 'create-sale'],
    ['label' => 'Penjualan',    'route' => 'sales.index',        'active' => 'sales.*',            'icon' => '🧾', 'permission' => ['view-sales', 'view-own-sales']],
    ['label' => 'Pembelian',    'route' => 'purchases.index',    'active' => 'purchases.*',        'icon' => '📥', 'permission' => ['view-purchases', 'create-purchase']],
    ['label' => 'Produk',       'route' => 'products.index',     'active' => 'products.*',         'icon' => '📦', 'permission' => ['manage-products', 'view-products']],
    ['label' => 'Kategori',     'route' => 'categories.index',   'active' => 'categories.*',       'icon' => '🏷️', 'permission' => 'manage-categories'],
    ['label' => 'Satuan',       'route' => 'units.index',        'active' => 'units.*',            'icon' => '📏', 'permission' => 'manage-products'],
    ['label' => 'Stok',         'route' => 'stock.index',        'active' => 'stock.*',            'icon' => '🗃️', 'permission' => ['manage-stock', 'view-stock']],
    ['label' => 'Stok Opname',  'route' => 'stock-opnames.index', 'active' => 'stock-opnames.*',   'icon' => '📝', 'permission' => 'stock-opname'],
    ['label' => 'Supplier',     'route' => 'suppliers.index',    'active' => 'suppliers.*',        'icon' => '🚚', 'permission' => 'manage-suppliers'],
    ['label' => 'Pelanggan',    'route' => 'customers.index',    'active' => 'customers.*',        'icon' => '👥', 'permission' => 'manage-customers'],
    ['label' => 'Hutang',       'route' => 'payables.index',     'active' => 'payables.*',         'icon' => '💸', 'permission' => 'manage-payables'],
    ['label' => 'Piutang',      'route' => 'receivables.index',  'active' => 'receivables.*',      'icon' => '💰', 'permission' => 'manage-receivables'],
    ['label' => 'Laporan',      'route' => 'reports.index',      'active' => 'reports.*',          'icon' => '📈', 'permission' => 'view-reports'],
    ['label' => 'Pengguna',     'route' => 'users.index',        'active' => 'users.*',            'icon' => '👤', 'permission' => 'manage-users'],
    ['label' => 'Pengaturan',   'route' => 'settings.edit',      'active' => 'settings.*',         'icon' => '⚙️', 'permission' => 'manage-settings'],
];
