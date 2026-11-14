import { expect, test } from '@playwright/test';
import { login } from './helpers';

const menu = [
    'Dashboard',
    'New Enrollment',
    'Review Enrollments',
    'Student Management',
    'My Section',
    'My Students',
    'Manage Sections',
    'Assign Students',
    'Manage Strands',
    'Analytics',
    'Metrics',
    'Academic Years',
    'User Accounts',
    'Audit Log',
];

// The same menus each role had in the legacy system.
const expected: Record<string, string[]> = {
    admin: menu.filter((m) => !['My Section', 'My Students'].includes(m)),
    ict: ['Dashboard', 'New Enrollment', 'Review Enrollments', 'Student Management', 'Academic Years', 'User Accounts', 'Audit Log'],
    registrar: ['Dashboard', 'New Enrollment', 'Review Enrollments', 'Student Management', 'Analytics', 'User Accounts', 'Audit Log'],
    keyteacher: ['Dashboard', 'Student Management', 'Manage Sections', 'Assign Students', 'Manage Strands'],
    adviser: ['Dashboard', 'New Enrollment', 'Review Enrollments', 'Student Management', 'My Section'],
    subject: ['Dashboard', 'My Students'],
};

for (const [username, items] of Object.entries(expected)) {
    test(`${username} sees only their menu items`, async ({ page }) => {
        await login(page, username);
        const nav = page.getByRole('navigation', { name: 'Main' }).first();
        await expect(nav).toBeVisible();
        for (const item of menu) {
            if (items.includes(item)) await expect(nav.getByRole('link', { name: item })).toBeVisible();
            else await expect(nav.getByRole('link', { name: item })).toHaveCount(0);
        }
    });
}

test('typing a forbidden address is refused by the server', async ({ page }) => {
    await login(page, 'subject');
    for (const url of [
        '/users',
        '/enrollments',
        '/enrollments/create',
        '/reviews',
        '/sections',
        '/analytics',
        '/metrics',
        '/school-years',
        '/audit-log',
        '/imports',
        '/backup',
    ]) {
        const response = await page.goto(url);
        expect(response?.status(), url).toBe(403);
    }
});
