import { defineConfig, devices } from '@playwright/test';

const port = Number(process.env.PLAYWRIGHT_PORT ?? 8020);
const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? `http://127.0.0.1:${port}`;
const appKey = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
const usesExternalServer = Boolean(process.env.PLAYWRIGHT_BASE_URL);

export default defineConfig({
    testDir: './tests/e2e',
    timeout: 30_000,
    expect: {
        timeout: 10_000,
    },
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? 'github' : 'list',
    use: {
        baseURL,
        trace: 'on-first-retry',
    },
    webServer: usesExternalServer ? undefined : {
        command: [
            'touch database/playwright.sqlite',
            'APP_ENV=testing APP_KEY='.concat(appKey, ' DB_CONNECTION=sqlite DB_DATABASE=database/playwright.sqlite SESSION_DRIVER=array CACHE_STORE=array QUEUE_CONNECTION=sync VITE_FLIGHT_DATA_SOURCE=backend php artisan migrate:fresh --force --seed --seeder=Database\\\\Seeders\\\\PlaywrightTripDataSeeder'),
            'VITE_FLIGHT_DATA_SOURCE=backend npm run build',
            'rm -f public/hot',
            'APP_ENV=testing APP_KEY='.concat(appKey, ` DB_CONNECTION=sqlite DB_DATABASE=database/playwright.sqlite SESSION_DRIVER=array CACHE_STORE=array QUEUE_CONNECTION=sync php artisan serve --host=127.0.0.1 --port=${port}`),
        ].join(' && '),
        url: baseURL,
        reuseExistingServer: false,
        timeout: 120_000,
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
