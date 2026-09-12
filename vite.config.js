import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// One entry: the public form page. Same-origin assets only (CSP script-src/style-src 'self', I9).
export default defineConfig({
    plugins: [laravel({ input: ['resources/js/form/main.js', 'resources/js/dashboard/main.js'] })],
});
