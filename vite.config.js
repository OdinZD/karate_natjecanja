import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
            ],
            refresh: true,
            fonts: [
                bunny('Big Shoulders', {
                    weights: [700, 800, 900],
                    subsets: ['latin', 'latin-ext'],
                    preload: [{ weight: 900 }],
                }),
                bunny('Inter', {
                    weights: [400, 500, 600, 700],
                    subsets: ['latin', 'latin-ext'],
                    preload: [{ weight: 400 }, { weight: 700 }],
                }),
                bunny('JetBrains Mono', {
                    weights: [500, 600, 700],
                    subsets: ['latin', 'latin-ext'],
                    preload: false,
                }),
            ],
        }),
        tailwindcss(),
    ]),
    server: {
        cors: true,
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/storage/framework/views/**',
                '**/vendor/**',
            ],
        },
    },
});
