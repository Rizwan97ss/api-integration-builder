import { fileURLToPath, URL } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';

const path = (relative: string): string =>
    fileURLToPath(new URL(relative, import.meta.url));

/**
 * Builds the self-contained builder UI into dist/, which is committed and
 * served by the package, so users need no npm install or build step.
 */
export default defineConfig({
    base: './',
    plugins: [vue(), tailwindcss()],
    resolve: {
        alias: [
            {
                find: '@inertiajs/vue3',
                replacement: path('./resources/js/standalone/inertia.ts'),
            },
            {
                find: '@/routes/integration-builder',
                replacement: path('./resources/js/standalone/routes.ts'),
            },
            { find: '@', replacement: path('./resources/js') },
        ],
    },
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        cssCodeSplit: false,
        rollupOptions: {
            input: path('./resources/js/standalone/main.ts'),
            output: {
                format: 'iife',
                entryFileNames: 'builder.js',
                assetFileNames: (asset) =>
                    (asset.names ?? []).some((name) => name.endsWith('.css'))
                        ? 'builder.css'
                        : '[name][extname]',
            },
        },
    },
});
