import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import { watch } from 'less';

export default defineConfig({
    root: __dirname,
    plugins: [
        laravel({
            input: [
                'assets/css/app.css', 'assets/js/app.js'
            ],
            refresh: [
                './**',
                './**/**',
                '../classes/*.php',
            ],
            publicDirectory: './',
        }),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './assets/js')
        }
    }
});

