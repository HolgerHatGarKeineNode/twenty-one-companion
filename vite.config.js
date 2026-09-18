import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from "@tailwindcss/vite";
import { nativephpMobile, nativephpHotFile } from './vendor/nativephp/mobile/resources/js/vite-plugin.js';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                /*
                 * Das Chat-Theme bleibt ein eigenes CSS-Entry: es bringt die Tokens des
                 * Packages mit (`--color-muted`, die Brand-Rampe, die Utilities), und zwei
                 * Tailwind-Bündel in einem Dokument wären zwei Preflights und zwei
                 * Token-Sätze. Das JS ist seit P4 EINES — die Befehlspalette (D6) muss auf
                 * beiden Layouts existieren, und `resources/js/group.js` war genau die
                 * Trennung, die sie auf der Hälfte der Seiten unmöglich machte.
                 */
                'resources/css/group.css',
            ],
            refresh: true,
            hotFile: nativephpHotFile(),
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
        nativephpMobile(),
    ],
    server: {
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    build: {
        rollupOptions: {
            output: {
                // welshman + nostr-tools (~700 KB, selten geändert) in einen
                // cache-stabilen Vendor-Chunk trennen (nur im Chat-Tab geladen).
                manualChunks(id) {
                    if (id.includes('/node_modules/@welshman/') || id.includes('/node_modules/nostr-tools/')) {
                        return 'welshman';
                    }
                },
            },
        },
    },
});
