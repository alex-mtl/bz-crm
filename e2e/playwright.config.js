import { defineConfig } from '@playwright/test';

/*
 * Browser tests (ТЗ §51, ADR-013). They run in the `e2e` container against the demo world of the local
 * environment: `docker compose --profile e2e run --rm e2e`.
 *
 * The application is opened as http://localhost:8100 — the same address a person uses — because a service
 * worker needs a secure context, and "localhost" is one while "nginx" is not. global-setup.js forwards the
 * local ports to the containers.
 */
export default defineConfig({
    testDir: './tests',
    timeout: 120_000,
    expect: { timeout: 20_000 },
    workers: 1,
    retries: 0,
    reporter: [['list']],
    globalSetup: './global-setup.js',
    use: {
        baseURL: 'http://localhost:8100',
        locale: 'ro-RO',
        viewport: { width: 390, height: 844 },
        serviceWorkers: 'allow',
        trace: 'retain-on-failure',
    },
    outputDir: './test-results',
});
