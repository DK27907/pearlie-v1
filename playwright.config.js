import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    testMatch: '**/*.spec.js',
    timeout: 30000,
    expect: {
        timeout: 10000,
    },
    use: {
        baseURL: 'http://127.0.0.1:4173',
        channel: 'chrome',
        headless: true,
    },
    webServer: {
        command: 'node node_modules/vite/bin/vite.js --host 127.0.0.1 --port 4173 --strictPort',
        url: 'http://127.0.0.1:4173/tests/Browser/fixtures/payment-polling.html',
        reuseExistingServer: !process.env.CI,
        env: {
            LARAVEL_BYPASS_ENV_CHECK: '1',
        },
        timeout: 30000,
    },
});
