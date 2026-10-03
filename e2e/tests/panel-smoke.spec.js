import { expect, test } from '@playwright/test';

/*
 * The panel in a real browser: the messenger holds a live WebSocket (ADR-012), the map draws the houses
 * (ADR-013). Mostly a check of an environment — locally or on a stand.
 */

test('the messenger is connected over WebSocket and the map draws the field', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await page.goto('/admin/login');
    await page.locator('details').evaluate((details) => {
        details.open = true;
    });
    await page.getByRole('button', { name: 'Ana Lungu' }).click();
    await page.waitForURL('**/admin');

    await page.goto('/admin/messenger');
    await expect.poll(
        () => page.evaluate(() => window.Echo?.connector?.pusher?.connection?.state ?? 'none'),
        { timeout: 30_000 },
    ).toBe('connected');

    await page.goto('/admin/field-map');
    await expect(page.locator('[data-bz-map] .leaflet-overlay-pane path').first()).toBeVisible();
    expect(await page.locator('[data-bz-map]').getAttribute('data-houses')).toBe('5');

    expect(errors).toEqual([]);
});
