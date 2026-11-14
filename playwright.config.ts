import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end tests: real browsers against a real server and a real
 * PostgreSQL database. See tests/e2e/README.md.
 */
const port = Number(process.env.E2E_PORT ?? 8765);
const dbUrl = process.env.E2E_DB_URL ?? 'postgresql://djihs:djihs@127.0.0.1:5432/djihs_e2e';

export const serverEnv = {
    APP_ENV: 'testing',
    APP_DEBUG: 'false',
    APP_URL: `http://127.0.0.1:${port}`,
    DB_CONNECTION: 'pgsql',
    DB_URL: dbUrl,
    SESSION_DRIVER: 'database',
    CACHE_STORE: 'database',
    QUEUE_CONNECTION: 'sync',
    LOG_CHANNEL: 'stderr',
    // Several PHP workers, so simultaneous requests really run in parallel.
    PHP_CLI_SERVER_WORKERS: '8',
};

export default defineConfig({
    testDir: './tests/e2e',
    timeout: 60_000,
    expect: { timeout: 10_000 },
    // Tests share one database, so run them one after another.
    workers: 1,
    fullyParallel: false,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    globalSetup: './tests/e2e/global-setup.ts',
    use: {
        baseURL: `http://127.0.0.1:${port}`,
        trace: 'off',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1366, height: 900 } }, testIgnore: /mobile\.spec/ },
        { name: 'phone', use: { ...devices['Pixel 7'] }, testMatch: /mobile\.spec/ },
    ],
    webServer: {
        command: `php artisan serve --host=127.0.0.1 --port=${port} --no-reload`,
        url: `http://127.0.0.1:${port}/up`,
        reuseExistingServer: false,
        env: serverEnv,
        stdout: 'ignore',
        stderr: process.env.E2E_SERVER_LOG ? 'pipe' : 'ignore',
        timeout: 60_000,
    },
});
