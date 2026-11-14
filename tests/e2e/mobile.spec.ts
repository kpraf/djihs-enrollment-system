import { expect, test } from '@playwright/test';
import { login } from './helpers';

test('every main page fits a phone screen and the menu opens as a drawer', async ({ page }) => {
    await login(page, 'admin');
    await expect(page).toHaveURL(/\/dashboard/);

    for (const url of ['/dashboard', '/enrollments', '/enrollments/create', '/users', '/audit-log', '/imports', '/settings/profile']) {
        await page.goto(url);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow, `${url} scrolls sideways`).toBeLessThanOrEqual(1);
    }

    await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: 'Student Management' }).click();
    await expect(page).toHaveURL(/\/enrollments$/);
    await expect(page.getByRole('button', { name: 'Open menu' })).toBeVisible();
});
