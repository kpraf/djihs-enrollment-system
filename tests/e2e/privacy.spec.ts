import { expect, test } from '@playwright/test';
import { encode, fillEnrollmentForm, login, tag, uniqueLrn } from './helpers';

/**
 * Data Privacy Act (RA 10173): the privacy notice, the staff confidentiality
 * agreement, logging who saw a record, and a learner's own data record.
 */

test('the privacy notice is open to anyone and linked from the sign-in page', async ({ page }) => {
    await page.goto('/login');
    await page.getByRole('link', { name: 'Privacy Notice' }).click();
    await expect(page.getByRole('heading', { name: 'Privacy Notice for Learners and Parents' })).toBeVisible();
    await expect(page.getByText('Data Privacy Act of 2012 (Republic Act No. 10173)')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Your rights' })).toBeVisible();
});

test('a new staff member accepts the confidentiality agreement before seeing any learner', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Username').fill('firstday');
    await page.getByLabel('Password', { exact: true }).fill('Password123');
    await page.getByRole('button', { name: 'Sign In' }).click();

    await expect(page.getByRole('heading', { name: 'Confidentiality Agreement' })).toBeVisible();
    await page.goto('/enrollments');
    await expect(page.getByRole('heading', { name: 'Confidentiality Agreement' })).toBeVisible();

    await page.getByRole('button', { name: 'Accept and Continue' }).click();
    await expect(page.getByText('Tick the box to accept the agreement.')).toBeVisible();

    await page.getByLabel("I have read and agree to keep learners' information confidential.").check();
    await page.getByRole('button', { name: 'Accept and Continue' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto('/enrollments');
    await expect(page.getByRole('heading', { name: 'Learners' })).toBeVisible();
});

test('the form refuses to submit until the privacy notice is confirmed', async ({ page }) => {
    await login(page, 'registrar');
    const lrn = uniqueLrn();
    await page.goto('/enrollments/create');
    await fillEnrollmentForm(page, { lrn, last: 'Santos', first: `Rina ${tag(lrn)}` });
    await page.getByLabel('The parent, guardian or adult learner was given the privacy notice.').uncheck();
    await page.getByRole('button', { name: 'Submit Enrollment' }).click();
    await expect(page.getByText('Confirm that the parent, guardian or adult learner was given the privacy notice.')).toBeVisible();

    await page.getByLabel('The parent, guardian or adult learner was given the privacy notice.').check();
    await page.getByRole('button', { name: 'Submit Enrollment' }).click();
    await expect(page.getByRole('status').filter({ hasText: `Submitted SANTOS, RINA ${tag(lrn)}` })).toBeVisible();
});

test("a learner's data record lists their details and who viewed them; the audit log shows the view", async ({ page, context }) => {
    await login(page, 'registrar');
    const lrn = uniqueLrn();
    await encode(page, { lrn, last: 'Garcia', first: `Lito ${tag(lrn)}`, sex: 'male' });
    await page.goto(`/enrollments?search=${lrn}`);
    await page
        .getByRole('link', { name: new RegExp(`^GARCIA, LITO`) })
        .first()
        .click();
    await expect(page.getByText(/Privacy notice.*recorded by Rita Registrar/)).toBeVisible();

    const [record] = await Promise.all([context.waitForEvent('page'), page.getByRole('link', { name: 'Learner Data Record' }).click()]);
    await expect(record.getByRole('heading', { name: 'Learner Data Record' })).toBeVisible();
    await expect(record.getByText(lrn).first()).toBeVisible();
    await expect(record.getByText(/Rita Registrar viewed: Learner record/)).toBeVisible();
    await record.close();

    await page.goto('/audit-log?activity=access');
    await expect(
        page
            .getByRole('row')
            .filter({ hasText: `GARCIA, LITO ${tag(lrn)}` })
            .filter({ hasText: 'Viewed' })
            .first(),
    ).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: 'Learner data record' }).first()).toBeVisible();
});
