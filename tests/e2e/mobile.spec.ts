import { expect, test } from '@playwright/test';
import { login } from './helpers';

test('every main page fits a phone screen and the menu opens as a drawer', async ({ page }) => {
    await login(page, 'admin');
    await expect(page).toHaveURL(/\/dashboard/);

    for (const url of [
        '/privacy',
        '/dashboard',
        '/enrollments',
        '/enrollments/create',
        '/reviews',
        '/sections',
        '/section-assignments',
        '/strands',
        '/reports',
        '/school-years',
        '/users',
        '/audit-log',
        '/imports',
        '/settings/profile',
    ]) {
        await page.goto(url);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow, `${url} scrolls sideways`).toBeLessThanOrEqual(1);
    }

    // Sections are cards on a phone: the name shows, and the export menu fits on screen.
    await page.goto('/sections');
    const card = page.getByRole('list', { name: 'Sections' }).getByRole('listitem').first();
    await expect(card.getByRole('heading')).toBeVisible();
    await card.getByRole('button', { name: /^Export for / }).click();
    const box = await page.getByRole('menu').boundingBox();
    const width = page.viewportSize()!.width;
    expect(box!.x).toBeGreaterThanOrEqual(0);
    expect(box!.x + box!.width).toBeLessThanOrEqual(width);
    await page.keyboard.press('Escape');

    await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: 'Learners', exact: true }).click();
    await expect(page).toHaveURL(/\/enrollments$/);
    await expect(page.getByRole('button', { name: 'Open menu' })).toBeVisible();
});
