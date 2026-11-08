import defaultTheme from 'tailwindcss/defaultTheme';
import colors from 'tailwindcss/colors';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    // Dark mode disiapkan lewat kelas `dark` di <html>; belum dipakai di halaman mana pun.
    darkMode: 'class',

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },

            /*
            | Token warna redesign (docs/REDESIGN-TOKOKU.md). Komponen baru di
            | resources/views/components/ui hanya memakai token ini + netral `slate`.
            |
            | `primary` = aksi utama (dipakai hemat), `success`/`warning`/`danger`/`info` = status.
            */
            colors: {
                primary: colors.indigo,
                success: colors.emerald,
                warning: colors.amber,
                danger: colors.red,
                info: colors.sky,

                // Halaman lama memakai stone-* (abu hangat) sebagai netral. Dipetakan ke slate
                // agar netralnya seragam dengan komponen baru tanpa mengubah tiap halaman.
                stone: colors.slate,

            /*
            | WARNA LAMA, masih dipakai halaman yang belum dipindah ke komponen baru.
            | Dihapus setelah semua halaman memakai token di atas (Fase 2).
            |
            | Warna merek mengikuti identitas Dkriuk (dkriuk.com):
            | biruabu-abu (slate) sebagai warna utama, oranye lembut sebagai aksen.
            |
            | `brand`  = slate biru -> aksi utama, navigasi aktif, tautan, fokus
            | `accent` = oranye -> penanda penting (badge, sorotan)
            |
            | Palette yang lebih profesional dan tidak terlalu mencolok.
            */
                brand: {
                    50: '#f8fafc',
                    100: '#f1f5f9',
                    200: '#e2e8f0',
                    300: '#cbd5e1',
                    400: '#94a3b8',
                    500: '#64748b',
                    600: '#475569',
                    700: '#334155',
                    800: '#1e293b',
                    900: '#0f172a',
                    950: '#020617',
                },
                accent: {
                    50: '#fffbeb',
                    100: '#fef3c7',
                    200: '#fde68a',
                    300: '#fcd34d',
                    400: '#fbbf24',
                    500: '#f59e0b',
                    600: '#d97706',
                    700: '#b45309',
                    800: '#92400e',
                    900: '#78350f',
                    950: '#451a03',
                },
            },

            boxShadow: {
                // Bayangan lembut untuk kartu; lebih halus dari shadow-md bawaan.
                card: '0 1px 3px 0 rgb(0 0 0 / 0.04), 0 1px 4px 0 rgb(0 0 0 / 0.06)',
                // Bayangan untuk elemen yang "mengambang" (dropdown, modal).
                float: '0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1)',
            },

            borderRadius: {
                card: '0.75rem',
            },

            // Typography yang lebih bersih
            typography: {
                DEFAULT: {
                    css: {
                        maxWidth: 'none',
                        color: '#374151',
                        fontSize: '1rem',
                        fontFamily: ['Inter', 'system-ui', 'sans-serif'],
                    },
                },
            },

            // Transitions yang lebih halus
            transitionTimingFunction: {
                smooth: 'cubic-bezier(0.4, 0, 0.2, 1)',
            },
        },
    },

    plugins: [forms],
};