import { expect, test } from '@playwright/test';
import { login } from './helpers';

const menu = ['Dashboard', 'New Enrollment', 'Student Management', 'User Accounts', 'Audit Log'];

const expected: Record<string, string[]> = {
    admin: menu,
    ict: menu,
    registrar: ['Dashboard', 'New Enrollment', 'Student Management', 'Audit Log'],
    keyteacher: ['Dashboard', 'Student Management'],
    adviser: ['Dashboard', 'New Enrollment', 'Student Management'],
    subject: ['Dashboard'],
};

for (const [username, items] of Object.entries(expected)) {
    test(`${username} sees only their menu items`, async ({ page }) => {
        await login(page, username);
        const nav = page.getByRole('navigation', { name: 'Main' }).first();
        for (const item of menu) {
            if (items.includes(item)) await expect(nav.getByRole('link', { name: item })).toBeVisible();
            else await expect(nav.getByRole('link', { name: item })).toHaveCount(0);
        }
    });
}

test('typing a forbidden address is refused by the server', async ({ page }) => {
    await login(page, 'subject');
    for (const url of ['/users', '/enrollments', '/enrollments/create', '/audit-log', '/imports']) {
        const response = await page.goto(url);
        expect(response?.status(), url).toBe(403);
    }
});
