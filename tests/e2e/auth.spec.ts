import { expect, test } from '@playwright/test';
import { login, PASSWORD } from './helpers';

test('a wrong password shows a message and stays on the login page', async ({ page }) => {
    await login(page, 'registrar', 'wrong-password');
    await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
    await expect(page).toHaveURL(/\/login/);
});

test('staff sign in and see their role in the sidebar', async ({ page }) => {
    await login(page, 'registrar');
    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.getByRole('heading', { name: 'Welcome, Rita!' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'DJIHS Registrar' })).toBeVisible();
});

test('a deactivated account cannot sign in', async ({ page }) => {
    await login(page, 'retired');
    await expect(page.getByText('This account has been deactivated')).toBeVisible();
});

test('a temporary password must be changed before anything else', async ({ page }) => {
    await login(page, 'newhire');
    await expect(page).toHaveURL(/\/settings\/password/);
    await expect(page.getByText('You are using a temporary password')).toBeVisible();

    // Trying to leave is not allowed.
    await page.goto('/enrollments');
    await expect(page).toHaveURL(/\/settings\/password/);

    await page.getByLabel('Temporary Password').fill(PASSWORD);
    await page.getByLabel('New Password', { exact: false }).first().fill('Brand-new-pass1');
    await page.getByLabel('Confirm New Password').fill('Brand-new-pass1');
    await page.getByRole('button', { name: 'Save Password' }).click();
    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.getByText('Password updated. Welcome!')).toBeVisible();
});

test('logout asks for confirmation, then signs out', async ({ page }) => {
    await login(page, 'adviser');
    await page.getByRole('button', { name: 'Logout' }).click();
    await expect(page.getByRole('dialog')).toContainText('Are you sure you want to logout?');
    await page.getByRole('dialog').getByRole('button', { name: 'Logout' }).click();
    await expect(page).toHaveURL(/\/login/);
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login/);
});
