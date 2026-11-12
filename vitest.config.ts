import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    test: { environment: 'jsdom', setupFiles: ['resources/js/test-setup.ts'], restoreMocks: true, unstubEnvs: true },
});
