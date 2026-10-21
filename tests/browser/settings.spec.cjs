const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

async function login(page) {
    await page.goto('/wp-login.php?redirect_to=' + encodeURIComponent('http://127.0.0.1:8080/wp-admin/admin.php?page=storewide-sale'));
    await page.locator('#user_login').fill('admin');
    await page.locator('#user_pass').fill('disposable-test-password');
    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/admin.php?page=storewide-sale', { timeout: 30000 });
    await expect(page.getByRole('heading', { name: 'Storewide Sale', exact: true })).toBeVisible({ timeout: 15000 });
}
async function select(page, kind, query, result) {
    const container = page.locator(`#sws-${kind} + .select2`);
    await container.locator('.select2-selection').click();
    const input = container.locator('.select2-search__field');
    await input.fill('');
    await input.pressSequentially(query);
    await page.locator('.select2-results__option').filter({ hasText: result }).click({ timeout: 15000 });
    await input.press('Escape');
    await page.getByRole('heading', { name: 'Storewide Sale', exact: true }).click();
}

test('searchable chips, campaign overview and full start/stop flow', async ({ page }) => {
    // Two asynchronous batch phases and their page loads need their own time budget.
    test.setTimeout(120000);
    await login(page);
    await expect(page.locator('#sws-categories + .select2')).toBeVisible();
    await expect(page.locator('#sws-products + .select2')).toBeVisible();
    await select(page, 'categories', 'UI Porc', 'UI Porcelain');
    await select(page, 'products', 'UI-VASE-1', /UI Vas/);
    await expect(page.locator('#sws-categories + .select2 .select2-selection__choice')).toContainText('UI Porcelain');
    await expect(page.locator('#sws-products + .select2 .select2-selection__choice')).toContainText('UI Vas');
    await page.locator('#sws-percent').fill('15');
    await page.locator('#sws-cutoff').fill('2024-12-31');
    await expect(page.locator('#sws-summary-percent')).toHaveText('15');
    await expect(page.locator('#sws-summary-categories')).toHaveText('1');
    await expect(page.locator('#sws-summary-products')).toHaveText('1');
    const left = await page.locator('.sws-main').boundingBox();
    const right = await page.locator('.sws-sidebar').boundingBox();
    expect(right.x).toBeGreaterThanOrEqual(left.x + left.width);
    fs.mkdirSync('test-results/screenshots', { recursive: true });
    await page.screenshot({ path: path.join('test-results/screenshots', 'settings-desktop.png'), fullPage: true });
    // Removing a chip updates both the submitted value and summary.
    await page.locator('#sws-products + .select2 .select2-selection__choice__remove').click();
    await expect(page.locator('#sws-summary-products')).toHaveText('0');
    await page.locator('#sws-products + .select2 .select2-search__field').press('Escape');
    await select(page, 'products', 'UI-VASE-1', /UI Vas/);
    await page.getByRole('switch', { name: 'Aktivera kampanj' }).click();
    await expect(page.locator('.sws-status-active')).toBeVisible({ timeout: 45000 });
    await page.waitForLoadState('load', { timeout: 30000 });
    await expect(page.locator('.sws-chips')).toContainText(['UI Porcelain', 'UI Vas']);
    await page.getByRole('switch', { name: 'Avsluta kampanj' }).click();
    await expect(page.locator('.sws-status-stopped')).toBeVisible({ timeout: 45000 });
    await expect(page.locator('#sws-categories + .select2 .select2-selection__choice')).toContainText('UI Porcelain');
});

test('narrow screens stack the overview without horizontal overflow', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    const main = await page.locator('.sws-main').boundingBox();
    const summary = await page.locator('.sws-sidebar').boundingBox();
    expect(summary.y).toBeGreaterThanOrEqual(main.y + main.height);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.screenshot({ path: 'test-results/screenshots/settings-mobile.png', fullPage: true });
});
