import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/filament/admin/theme.css',
            ],
            refresh: true,
        }),
        VitePWA({
            registerType: 'prompt',
            injectRegister: 'auto',
            srcDir: 'resources/js',
            filename: 'sw.js',
            strategies: 'generateSW',
            manifest: false,
            workbox: {
                globPatterns: ['**/*.{js,css,html,svg,png,ico,woff2}'],
                navigateFallback: '/tecnico',
                navigateFallbackAllowlist: [/^\/tecnico/],
                runtimeCaching: [
                    {
                        urlPattern: /^https:\/\/fonts\.bunny\.net\/.*$/,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'bunny-fonts',
                            expiration: { maxEntries: 30, maxAgeSeconds: 60 * 60 * 24 * 365 },
                        },
                    },
                    {
                        urlPattern: /^https:\/\/www\.winfin\.es\/images\/piv\/.*$/,
                        handler: 'StaleWhileRevalidate',
                        options: {
                            cacheName: 'piv-photos-legacy',
                            expiration: { maxEntries: 200, maxAgeSeconds: 60 * 60 * 24 * 30 },
                        },
                    },
                    {
                        urlPattern: /\/storage\/piv-images\/correctivo\/.*$/,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'piv-photos-cierre',
                            expiration: { maxEntries: 100, maxAgeSeconds: 60 * 60 * 24 * 90 },
                        },
                    },
                    {
                        urlPattern: ({ request, url }) => request.mode === 'navigate' && url.pathname.startsWith('/tecnico'),
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'tecnico-html',
                            networkTimeoutSeconds: 3,
                            expiration: { maxEntries: 30, maxAgeSeconds: 60 * 60 * 24 * 7 },
                        },
                    },
                    {
                        urlPattern: /\/livewire\/.*$/,
                        handler: 'NetworkOnly',
                    },
                ],
            },
            devOptions: {
                enabled: false,
            },
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
