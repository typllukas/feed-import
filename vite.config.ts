import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/css/app.css', 'resources/js/app.ts'] }),
        vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } }),
    ],
});
