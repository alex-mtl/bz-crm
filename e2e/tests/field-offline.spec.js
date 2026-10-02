import { expect, test } from '@playwright/test';

/*
 * ТЗ §51 — the critical scenario "field/offline synchronization", on the demo world.
 *
 * Radu Ceban, a volunteer, stands at the entrance of str. Teilor 14 without a network: the application opens
 * from the service worker's cache, the visits he records wait on the phone and survive a reload. When the
 * network is back they are sent. The answer to the first sending is lost on purpose — the phone sends the same
 * operations again, and every flat still gets exactly one visit.
 */

const HOUSE = 'str. Teilor 14';
const FLATS = ['10', '11'];

async function signInAs(page, name) {
    await page.goto('/admin/login');
    await page.locator('details').evaluate((details) => {
        details.open = true;
    });
    await page.getByRole('button', { name }).click();
    await page.waitForURL('**/admin');
}

async function attempts(page) {
    return page.evaluate(async ({ house, flats }) => {
        const response = await fetch('/api/v1/field/snapshot', { headers: { Accept: 'application/json' } });
        const { data } = await response.json();
        const found = data.houses.find((h) => h.label === house);
        return Object.fromEntries(flats.map((number) => [number, found.apartments.find((a) => a.number === number).attempts]));
    }, { house: HOUSE, flats: FLATS });
}

async function record(page, flat, status, note) {
    await page.locator(`[data-test=apartment][data-number="${flat}"]`).click();
    await page.locator(`[data-test=status][data-status=${status}]`).click();
    if (note) {
        await page.locator('[data-test=note]').fill(note);
    }
    await page.locator('[data-test=save]').click();
    await expect(page.locator('#app')).toHaveAttribute('data-view', 'house');
}

test('visits recorded without a network are sent once the network is back — each exactly once', async ({ page, context }) => {
    await signInAs(page, 'Radu Ceban');

    // Online: the application loads the houses and the service worker keeps its shell.
    await page.goto('/field');
    await expect(page.locator('#app')).toHaveAttribute('data-ready', '1');
    await expect(page.locator('[data-test=house]')).toHaveCount(2);
    await page.evaluate(() => navigator.serviceWorker.ready);
    await page.reload();
    await expect(page.locator('#app')).toHaveAttribute('data-ready', '1');
    expect(await page.evaluate(() => navigator.serviceWorker.controller !== null)).toBe(true);
    const before = await attempts(page);

    // The network is gone. The application still opens — from the cache — with the houses kept on the phone.
    await context.setOffline(true);
    await page.reload();
    await expect(page.locator('#app')).toHaveAttribute('data-ready', '1');
    await expect(page.locator('[data-test=net]')).toHaveAttribute('data-online', '0');
    await expect(page.locator('[data-test=house]')).toHaveCount(2);

    await page.locator('[data-test=house]', { hasText: HOUSE }).click();
    await record(page, FLATS[0], 'supporter', 'E2E: fără rețea la scară');
    await record(page, FLATS[1], 'not_home');
    await expect(page.locator('[data-test=pending-count]')).toHaveAttribute('data-pending', '2');
    await expect(page.locator(`[data-test=apartment][data-number="${FLATS[0]}"]`)).toHaveAttribute('data-status', 'supporter');

    // A reload without a network loses nothing: the queue lives in IndexedDB.
    await page.reload();
    await expect(page.locator('#app')).toHaveAttribute('data-ready', '1');
    await expect(page.locator('[data-test=pending-count]')).toHaveAttribute('data-pending', '2');

    // The network is back, but the answer to the first sending never reaches the phone.
    let sent = 0;
    await page.route('**/api/v1/field/sync', async (route) => {
        sent += 1;
        if (sent === 1) {
            await route.fetch();          // the server gets and applies the operations…
            return route.abort('failed'); // …and the phone never learns it.
        }
        return route.continue();
    });
    await context.setOffline(false);
    await expect.poll(() => sent).toBeGreaterThanOrEqual(1);
    await expect(page.locator('#app')).toHaveAttribute('data-syncing', '0');
    await expect(page.locator('[data-test=pending-count]')).toHaveAttribute('data-pending', '2');

    // The phone sends the same two operations again.
    await page.locator('[data-test=net]').click();
    await page.locator('[data-test=sync]').click();
    await expect(page.locator('[data-test=pending-count]')).toHaveAttribute('data-pending', '0');
    await expect(page.locator('[data-test=queue-empty]')).toBeVisible();
    expect(sent).toBe(2);

    // Sent twice, applied once: every flat has exactly one visit more.
    const after = await attempts(page);
    for (const flat of FLATS) {
        expect(after[flat]).toBe(before[flat] + 1);
    }
});
