// Hanya bagian chart.js yang dipakai grafik batang dashboard, supaya ukuran unduhan kecil.
import { BarController, BarElement, CategoryScale, Chart, LinearScale, Tooltip } from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip);

export default Chart;
