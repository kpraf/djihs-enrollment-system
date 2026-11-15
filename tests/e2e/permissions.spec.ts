import { expect, test } from '@playwright/test';
import { login } from './helpers';

const menu = [
    'Dashboard',
    'My Section',
    'My Classes',
    'New Enrollment',
    'Review Enrollments',
    'Learners',
    'Sections',
    'Assign to Sections',
    'Strands',
    'Analytics',
    'Metrics',
    'School Years',
    'User Accounts',
    'Audit Log',
];
const headings = ['Enrollment', 'Sections', 'Reports', 'Administration'];

// The same pages each role had in the legacy system, now grouped.
const expected: Record<string, { items: string[]; groups: string[] }> = {
    admin: { items: menu.filter((m) => !['My Section', 'My Classes'].includes(m)), groups: headings },
    ict: {
        items: ['Dashboard', 'New Enrollment', 'Review Enrollments', 'Learners', 'School Years', 'User Accounts', 'Audit Log'],
        groups: ['Enrollment', 'Administration'],
    },
    registrar: {
        items: ['Dashboard', 'New Enrollment', 'Review Enrollments', 'Learners', 'Analytics', 'User Accounts', 'Audit Log'],
        groups: ['Enrollment', 'Reports', 'Administration'],
    },
    keyteacher: { items: ['Dashboard', 'Learners', 'Sections', 'Assign to Sections', 'Strands'], groups: ['Enrollment', 'Sections'] },
    adviser: { items: ['Dashboard', 'My Section', 'New Enrollment', 'Review Enrollments', 'Learners'], groups: ['Enrollment'] },
    subject: { items: ['Dashboard', 'My Classes'], groups: [] },
};

// Whole names only: "Sections" must not match "Assign to Sections", and the
// Review link also carries its pending count.
const linkName = (item: string) => new RegExp(`^${item}( \\d+\\+? waiting)?$`);

for (const [username, { items, groups }] of Object.entries(expected)) {
    test(`${username} sees only their menu items, grouped`, async ({ page }) => {
        await login(page, username);
        const nav = page.getByRole('navigation', { name: 'Main' }).first();
        await expect(nav).toBeVisible();
        for (const item of menu) {
            if (items.includes(item)) await expect(nav.getByRole('link', { name: linkName(item) })).toBeVisible();
            else await expect(nav.getByRole('link', { name: linkName(item) })).toHaveCount(0);
        }
        for (const heading of headings) {
            await expect(nav.getByRole('group', { name: heading, exact: true })).toHaveCount(groups.includes(heading) ? 1 : 0);
        }
    });
}

test('the menu shows how many enrollments are waiting for review', async ({ page }) => {
    await login(page, 'registrar');
    const review = page
        .getByRole('navigation', { name: 'Main' })
        .first()
        .getByRole('link', { name: /^Review Enrollments/ });
    await expect(review).toHaveAccessibleName(/^Review Enrollments \d+\+? waiting$/);
});

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
