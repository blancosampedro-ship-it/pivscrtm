import { defineConfig, minimal2023Preset } from '@vite-pwa/assets-generator/config';

export default defineConfig({
    preset: {
        ...minimal2023Preset,
        transparent: {
            sizes: [64, 192, 512],
            favicons: [[48, 'favicon.ico']],
        },
        maskable: {
            sizes: [512],
            padding: 0,
            resizeOptions: { background: '#0F62FE' },
        },
        apple: {
            sizes: [180],
            padding: 0.3,
            resizeOptions: { background: '#0F62FE' },
        },
    },
    images: ['resources/icons/source-icon.svg'],
    output: {
        folder: 'public',
    },
});
