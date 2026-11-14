import { expect, test } from '@playwright/test';
import { login } from './helpers';

test('create an account, hand over the temporary password, and the new user signs in', async ({ page, browser }) => {
    const username = `teacher${Date.now().toString().slice(-6)}`;

    await login(page, 'ict');
    await page.getByRole('link', { name: 'User Accounts' }).first().click();
    await page.getByRole('button', { name: 'Create New Account' }).click();

    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Username').fill(username.toUpperCase());
    await dialog.getByLabel('First Name').fill('Lorna');
    await dialog.getByLabel('Last Name').fill('Cruz');
    await dialog.getByLabel('Role').selectOption('adviser');
    await expect(dialog.getByLabel('Role').locator('option[value="admin"]')).toHaveCount(0);
    await dialog.getByRole('button', { name: 'Create Account' }).click();

    // The one-time password dialog.
    const credential = page.getByRole('dialog', { name: 'Account created' });
    await expect(credential).toContainText(username);
    const temporary = (await credential.getByTestId('temporary-password').innerText()).trim();
    expect(temporary).toMatch(/^[A-Za-z0-9]{12}$/);
    await credential.getByRole('button', { name: 'Done' }).click();
    await expect(page.getByRole('cell', { name: 'Lorna Cruz', exact: true })).toBeVisible();

    // The new teacher signs in on their own computer.
    const theirs = await browser.newContext();
    const p2 = await theirs.newPage();
    await login(p2, username, temporary);
    await expect(p2).toHaveURL(/\/settings\/password/);
    await theirs.close();

    // Deactivate them; they can no longer sign in.
    await page.getByRole('button', { name: 'Deactivate Lorna Cruz' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Deactivate' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Account deactivated.' })).toBeVisible();

    const again = await browser.newContext();
    const p3 = await again.newPage();
    await login(p3, username, temporary);
    await expect(p3.getByText('This account has been deactivated')).toBeVisible();
    await again.close();
});

test('the ICT Coordinator cannot touch the administrator account', async ({ page }) => {
    await login(page, 'ict');
    await page.goto('/users');
    const adminRow = page.getByRole('row', { name: /Ana Admin/ });
    await expect(adminRow.getByRole('button')).toHaveCount(0);
});
