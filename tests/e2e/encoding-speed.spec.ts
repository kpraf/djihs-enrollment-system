import { expect, test } from '@playwright/test';
import { fillEnrollmentForm, login, tag, uniqueLrn } from './helpers';

/**
 * Found by a simulated registrar encoding ten learners: the things that
 * slowed encoding down or invited mistakes.
 */

test.beforeEach(async ({ page }) => {
    await login(page, 'registrar');
    await page.goto('/enrollments/create');
});

test('the birth date can be typed the way it appears on a certificate', async ({ page }) => {
    const box = page.getByLabel('Birthdate');
    for (const [typed, shown] of [
        ['04182010', '04/18/2010'],
        ['4/18/2010', '4/18/2010'],
        ['2010-04-18', '2010-04-18'],
        ['April 18, 2010', 'April 18, 2010'],
    ]) {
        await box.click();
        await box.press('ControlOrMeta+a');
        await page.keyboard.type(typed);
        await expect(box).toHaveValue(shown);
        await expect(page.getByText(/April 18, 2010 · \d+ years old/)).toBeVisible();
    }

    await box.fill('02/30/2010');
    await expect(page.getByText('Not a date yet. Type it as MM/DD/YYYY, for example 04/18/2010.')).toBeVisible();
});

test('after a save the cursor is in the LRN box and the grade and strand are kept', async ({ page }) => {
    const lrn = uniqueLrn();
    await fillEnrollmentForm(page, { lrn, last: 'Magbanua', first: `Angel ${tag(lrn)}`, strand: 'ABM' });
    await page.getByRole('button', { name: 'Submit Enrollment' }).click();
    await expect(page.getByRole('status').filter({ hasText: `Submitted MAGBANUA, ANGEL ${tag(lrn)}` })).toBeVisible();

    await expect(page.getByLabel('Learner Reference No. (LRN)')).toBeFocused();
    await expect(page.getByLabel('Grade Level to Enroll')).toHaveValue('11');
    await expect(page.getByText('Kept from the last learner')).toBeVisible();
    await expect(page.getByRole('radio', { name: /^ABM/ })).toBeChecked();
    await expect(page.getByLabel(/^Last Name \*/)).toHaveValue('');
});

test('a short LRN is counted while typing, and a failed save puts the cursor on the problem', async ({ page }) => {
    await page.getByLabel('Learner Reference No. (LRN)').fill('12345678901');
    await expect(page.getByText('11 of 12 digits')).toBeVisible();

    const lrn = uniqueLrn();
    await fillEnrollmentForm(page, { lrn, last: 'Tolentino', first: `James ${tag(lrn)}`, sex: 'male' });
    await page.getByLabel('Sex').selectOption('');
    await page.getByRole('button', { name: 'Submit Enrollment' }).click();
    await expect(page.getByText('The sex field is required.')).toBeVisible();
    await expect(page.getByLabel('Sex')).toBeFocused();
});
