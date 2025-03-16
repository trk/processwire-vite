import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import path from 'path';

export default defineConfig({
    root: __dirname,
    plugins: [
        laravel({
            input: [
                'resources/css/app.css', 'resources/js/app.js'
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
            '@': path.resolve(__dirname, './resources/js')
        }
    }
});

