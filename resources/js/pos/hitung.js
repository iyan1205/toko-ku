/**
 * Hitungan uang layar Kasir dengan bilangan bulat, sama persis dengan server
 * (App\Support\Uang dan App\Services\SaleService). Semua angka uang dalam sen (Rupiah x100),
 * jumlah barang dalam milli (x1000). Pembulatan setengah ke atas.
 */

/** "12500.5" / 12500.5 -> 1250050 */
export function sen(nilai) {
    const n = Number(String(nilai ?? '').replace(',', '.'));
    return Number.isFinite(n) ? Math.round(n * 100) : 0;
}

/** 2.5 -> 2500 */
export function milli(nilai) {
    const n = Number(String(nilai ?? '').replace(',', '.'));
    return Number.isFinite(n) ? Math.round(n * 1000) : 0;
}

/** 1250050 -> 12500.5 (angka untuk dikirim ke server) */
export function rupiahDari(senNilai) {
    return Math.round(senNilai) / 100;
}

/** Harga setelah diskon tetap produk (persen, hingga 2 desimal). Sama dengan Uang::kurangiPersen(). */
export function kurangiPersen(senNilai, persen) {
    const bp = Math.round((Number(persen) || 0) * 100);
    return Math.floor((senNilai * (10000 - bp) * 2 + 10000) / 20000);
}

/** jumlah (milli) x harga (sen). Sama dengan Uang::kali(). */
export function kali(milliNilai, senNilai) {
    return Math.floor((milliNilai * senNilai * 2 + 1000) / 2000);
}

/**
 * Pajak dari dasar (total setelah diskon). Sama dengan SaleService::pajak().
 * @param {{enabled: boolean, rateBp: number, inclusive: boolean}} pajak
 */
export function hitungPajak(dasar, pajak) {
    const r = pajak.enabled ? pajak.rateBp : 0;
    if (r <= 0 || dasar <= 0) return 0;
    return pajak.inclusive
        ? dasar - Math.floor((dasar * 10000 * 2 + (10000 + r)) / (2 * (10000 + r)))
        : Math.floor((dasar * r * 2 + 10000) / 20000);
}

/**
 * Ringkasan pembayaran, sama dengan SaleService::pembayaran(): kembalian hanya dari tunai,
 * non-tunai tidak boleh melebihi total.
 * @param {Array<{method: string, sen: number}>} baris
 */
export function hitungBayar(total, baris) {
    const tunai = baris.filter((b) => b.method === 'cash').reduce((s, b) => s + b.sen, 0);
    const nonTunai = baris.filter((b) => b.method !== 'cash').reduce((s, b) => s + b.sen, 0);
    const tunaiDipakai = Math.max(0, Math.min(tunai, total - nonTunai));

    return {
        tunai,
        nonTunai,
        diterima: tunai + nonTunai,
        lebihNonTunai: nonTunai > total,
        kembalian: tunai - tunaiDipakai,
        sisa: total - Math.min(total, nonTunai) - tunaiDipakai,
    };
}

/** Format Rupiah dari sen: 1250000 -> "Rp 12.500" */
export function rp(senNilai) {
    return 'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format((senNilai || 0) / 100);
}

/** Format jumlah: 2.5 -> "2,5" */
export function angka(nilai) {
    return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 3 }).format(Number(nilai) || 0);
}
