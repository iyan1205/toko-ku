/**
 * Grafik omzet harian di dashboard (Alpine: x-data="grafikPenjualan(data)").
 *
 * chart.js dimuat terpisah (dynamic import) supaya halaman lain tidak ikut mengunduhnya.
 * Satu seri, satu warna (primary). Tooltip menampilkan tanggal, omzet, dan jumlah transaksi.
 * Data yang sama tersedia sebagai tabel untuk pembaca layar.
 */
const WARNA = {
    batang: '#6366f1', // primary-500
    batangHover: '#4f46e5', // primary-600
    grid: '#f1f5f9', // slate-100
    sumbu: '#64748b', // slate-500
};

function rupiahPendek(nilai) {
    if (nilai >= 1_000_000_000) return 'Rp ' + (nilai / 1_000_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' M';
    if (nilai >= 1_000_000) return 'Rp ' + (nilai / 1_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt';
    if (nilai >= 1_000) return 'Rp ' + (nilai / 1_000).toLocaleString('id-ID', { maximumFractionDigits: 0 }) + ' rb';
    return 'Rp ' + nilai.toLocaleString('id-ID');
}

function rupiah(nilai) {
    return 'Rp ' + Math.round(nilai).toLocaleString('id-ID');
}

export default (data) => {
    // Objek Chart sengaja di luar data Alpine: proxy reaktif Alpine merusak internal chart.js.
    let chart = null;

    return {
        periode: 7,
        data,
        gagal: false,

        get terpilih() {
            return this.data.slice(-this.periode);
        },

        get totalPeriode() {
            return this.terpilih.reduce((jumlah, hari) => jumlah + hari.total, 0);
        },

        get kosong() {
            return this.totalPeriode === 0;
        },

        async init() {
            try {
                const { default: Chart } = await import('./chart-batang');
                this.gambar(Chart);
            } catch (e) {
                this.gagal = true;
            }

            this.$watch('periode', () => this.perbarui());
        },

        gambar(Chart) {
            chart = new Chart(this.$refs.kanvas, {
                type: 'bar',
                data: this.dataGrafik(),
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 250 },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            padding: 10,
                            cornerRadius: 8,
                            displayColors: false,
                            titleFont: { family: 'Inter', weight: '600' },
                            bodyFont: { family: 'Inter' },
                            callbacks: {
                                title: (item) => this.terpilih[item[0].dataIndex].title,
                                label: (item) => {
                                    const hari = this.terpilih[item.dataIndex];
                                    return [rupiah(hari.total), hari.count + ' transaksi'];
                                },
                            },
                        },
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            border: { display: false },
                            ticks: { color: WARNA.sumbu, font: { family: 'Inter', size: 11 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 8 },
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: WARNA.grid },
                            border: { display: false },
                            ticks: { color: WARNA.sumbu, font: { family: 'Inter', size: 11 }, maxTicksLimit: 5, callback: (nilai) => rupiahPendek(nilai) },
                        },
                    },
                },
            });
        },

        dataGrafik() {
            return {
                labels: this.terpilih.map((hari) => hari.label),
                datasets: [{
                    label: 'Omzet',
                    data: this.terpilih.map((hari) => hari.total),
                    backgroundColor: WARNA.batang,
                    hoverBackgroundColor: WARNA.batangHover,
                    borderRadius: { topLeft: 4, topRight: 4 },
                    borderSkipped: 'start',
                    maxBarThickness: this.periode === 7 ? 36 : 16,
                    categoryPercentage: 0.7,
                }],
            };
        },

        perbarui() {
            if (!chart) return;
            chart.data = this.dataGrafik();
            chart.update();
        },

        rupiah,
    };
};
