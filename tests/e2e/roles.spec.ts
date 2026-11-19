import { expect, test } from '@playwright/test';
import { postAs, staffBrowser } from './helpers';

// The Key Teacher's work moves to the Registrar, then everything is put back.
test('the administrator gives the registrar section work, and the registrar sees it', async ({ browser }) => {
    const admin = await staffBrowser(browser, 'admin');
    try {
        await admin.page.getByRole('link', { name: 'Roles and Permissions' }).click();
        await expect(admin.page.getByRole('heading', { name: 'Roles and Permissions' })).toBeVisible();

        // The Administrator can't lock everyone out.
        await expect(admin.page.getByRole('checkbox', { name: 'Administrator: Manage roles and permissions' })).toBeDisabled();

        const box = admin.page.getByRole('checkbox', { name: 'Registrar: Manage sections and strands, and place learners in sections' });
        await expect(box).not.toBeChecked();
        await box.check();
        await expect(admin.page.getByText('1 unsaved change')).toBeVisible();
        await admin.page.getByRole('button', { name: 'Save changes' }).click();

        const dialog = admin.page.getByRole('dialog');
        await expect(dialog.getByText(/Registrar can now: manage sections and strands/)).toBeVisible();
        await dialog.getByRole('button', { name: 'Save 1 change' }).click();
        await expect(admin.page.getByText('Saved 1 change.')).toBeVisible();
        await expect(box).toBeChecked();

        const registrar = await staffBrowser(browser, 'registrar');
        await expect(registrar.page.getByRole('link', { name: 'Sections', exact: true })).toBeVisible();
        await registrar.page.goto('/sections');
        await expect(registrar.page.getByRole('heading', { name: 'Sections', exact: true })).toBeVisible();
        await registrar.context.close();

        // The change is in the Audit Log, in plain words.
        await admin.page.goto('/audit-log');
        await expect(admin.page.getByText('Registrar: Manage sections and strands, and place learners in sections').first()).toBeVisible();
        await expect(admin.page.getByText('Permission given').first()).toBeVisible();
    } finally {
        // Other browser tests expect the default permissions.
        await admin.page.goto('/roles');
        const reset = await postAs(admin.context, admin.context.request, '/roles/reset', {});
        expect(reset.status()).toBeLessThan(400);
        await admin.context.close();
    }
});
