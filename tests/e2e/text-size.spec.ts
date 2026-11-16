import { expect, test } from '@playwright/test';
import { login } from './helpers';

const rootSize = (page: import('@playwright/test').Page) => page.evaluate(() => document.documentElement.style.fontSize);

test('larger text can be chosen on the login page and is kept after signing in', async ({ page }) => {
    await page.goto('/login');
    await page.getByRole('button', { name: 'Text size' }).click();
    await page.getByRole('button', { name: 'Extra large text' }).click();
    await expect.poll(() => rootSize(page)).toBe('130%');

    // The whole page really grows: inputs get taller.
    const height = await page.getByLabel('Username').boundingBox();
    expect(height!.height).toBeGreaterThan(55);

    await page.reload();
    await expect.poll(() => rootSize(page)).toBe('130%');

    await login(page, 'keyteacher');
    await expect(page).toHaveURL(/\/dashboard/);
    await expect.poll(() => rootSize(page)).toBe('130%');
});

test('the choice is saved to the account and follows the user to another computer', async ({ page, browser }) => {
    await login(page, 'adviser');
    const saved = page.waitForResponse((r) => r.url().includes('/settings/text-size') && r.request().method() === 'PUT');
    await page.getByRole('button', { name: 'Large text', exact: true }).click();
    await saved;
    await expect.poll(() => rootSize(page)).toBe('115%');
    await page.goto('/enrollments');
    await expect.poll(() => rootSize(page)).toBe('115%');

    const other = await browser.newContext();
    const p2 = await other.newPage();
    await login(p2, 'adviser');
    await expect(p2).toHaveURL(/\/dashboard/);
    await expect.poll(() => rootSize(p2)).toBe('115%');
    await other.close();

    await page.goto('/settings/text-size');
    await page.getByRole('radio', { name: 'Normal' }).click();
    await expect.poll(() => rootSize(page)).toBe('100%');
});
