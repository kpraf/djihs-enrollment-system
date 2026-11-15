import { expect, test } from '@playwright/test';
import { countInList, fillEnrollmentForm, login, tag, uniqueLrn } from './helpers';

test.beforeEach(async ({ page }) => {
    await login(page, 'registrar');
});

test('encode a learner, then find, view, edit, cancel and restore them', async ({ page }) => {
    const lrn = uniqueLrn();

    await page.getByRole('link', { name: 'New Enrollment' }).first().click();
    await expect(page.getByRole('heading', { name: 'Student Enrollment' })).toBeVisible();

    await fillEnrollmentForm(page, { lrn, last: 'Bautista', first: `Liza ${tag(lrn)}` });
    await expect(page.getByText('New learner. No record with this LRN yet.')).toBeVisible();
    await page.getByRole('button', { name: 'Submit Enrollment' }).click();

    await expect(page.getByRole('status').filter({ hasText: `Submitted BAUTISTA, LIZA ${tag(lrn)} for Grade 11.` })).toBeVisible();
    // The form is cleared for the next learner.
    await expect(page.getByLabel('Learner Reference No. (LRN)')).toHaveValue('');

    // Find them in Learners.
    await page.getByRole('link', { name: 'Learners', exact: true }).first().click();
    await page.getByLabel('Search by name or LRN').fill(`liza bautista ${tag(lrn)}`);
    await expect(page.getByRole('link', { name: `BAUTISTA, LIZA ${tag(lrn)}`, exact: true })).toBeVisible();
    await page.getByRole('link', { name: `BAUTISTA, LIZA ${tag(lrn)}`, exact: true }).click();

    await expect(page.getByRole('heading', { name: `BAUTISTA, LIZA ${tag(lrn)}` })).toBeVisible();
    await expect(page.getByText(`LRN ${lrn}`)).toBeVisible();
    await expect(page.getByText('Rita Registrar created the learner')).toBeVisible();

    // Edit the strand.
    await page.getByRole('link', { name: 'Edit' }).click();
    await page.getByRole('radio', { name: /^ABM/ }).check();
    await page.getByRole('button', { name: 'Save Changes' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Changes saved.' })).toBeVisible();
    await expect(page.getByText('Strand: HUMSS → ABM')).toBeVisible();

    // Change status to cancelled, with a reason, then restore.
    await page.getByRole('button', { name: 'Change Status' }).click();
    await page.getByRole('dialog').getByLabel('New status').selectOption('cancelled');
    await page.getByRole('dialog').getByLabel('Reason').fill('Moved to another school');
    await page.getByRole('dialog').getByRole('button', { name: 'Change Status' }).click();
    await expect(page.getByText(/Cancelled by Rita Registrar .*Moved to another school/)).toBeVisible();

    await page.getByRole('button', { name: 'Restore' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Restore' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Enrollment restored.' })).toBeVisible();

    // A second change on the same page must use the new version, not a stale one.
    await page.getByRole('button', { name: 'Change Status' }).click();
    await page.getByRole('dialog').getByLabel('New status').selectOption('dropped');
    await page.getByRole('dialog').getByLabel('Reason').fill('Stopped attending');
    await page.getByRole('dialog').getByRole('button', { name: 'Change Status' }).click();
    await expect(page.getByText(/Dropped by .*Stopped attending/)).toBeVisible();
});

test('typing an LRN that is already enrolled warns right away', async ({ page }) => {
    const lrn = uniqueLrn();
    await page.goto('/enrollments/create');
    await fillEnrollmentForm(page, { lrn, last: 'Ramos', first: `Paolo ${tag(lrn)}`, sex: 'male' });
    await page.getByRole('button', { name: 'Submit Enrollment' }).click();
    await expect(page.getByRole('status').filter({ hasText: `Submitted RAMOS, PAOLO ${tag(lrn)}` })).toBeVisible();

    await page.getByLabel('Learner Reference No. (LRN)').fill(lrn);
    await expect(page.getByRole('alert').filter({ hasText: `RAMOS, PAOLO ${tag(lrn)} is already encoded this school year` })).toBeVisible();
    await expect(page.getByRole('link', { name: 'View record' })).toBeVisible();

    // Submitting anyway is refused by the server too.
    await fillEnrollmentForm(page, { lrn, last: 'Ramos', first: `Paolo ${tag(lrn)}`, sex: 'male' });
    await page.getByRole('button', { name: 'Submit Enrollment' }).click();
    await expect(page.getByText(/already encoded for school year/)).toBeVisible();
    expect(await countInList(page, lrn)).toBe(1);
});

test('a lookalike with a different LRN must be confirmed before saving', async ({ page }) => {
    const first = uniqueLrn();
    const mika = `Mika ${tag(first)}`;
    await page.goto('/enrollments/create');
    await fillEnrollmentForm(page, { lrn: first, last: 'Torres', first: mika, birthdate: '2009-01-02' });
    await page.getByRole('button', { name: 'Submit Enrollment' }).click();
    await expect(page.getByRole('status').filter({ hasText: `Submitted TORRES, ${mika.toUpperCase()}` })).toBeVisible();

    const second = uniqueLrn();
    await fillEnrollmentForm(page, { lrn: second, last: 'Torres', first: mika, birthdate: '2009-01-02' });
    await page.getByRole('button', { name: 'Submit Enrollment' }).click();
    await expect(page.getByText('A learner with the same name and birth date is already recorded')).toBeVisible();

    await page.getByLabel('This is a different learner. Save anyway.').check();
    await page.getByRole('button', { name: 'Submit Enrollment' }).click();
    // A saved enrollment clears the form. (The first save's toast may still be showing.)
    await expect(page.getByLabel('Learner Reference No. (LRN)')).toHaveValue('');
    await expect(page.getByText('A learner with the same name and birth date is already recorded')).toHaveCount(0);
    expect(await countInList(page, `torres ${mika}`)).toBe(2);
});

test('missing and wrong fields are pointed out', async ({ page }) => {
    await page.goto('/enrollments/create');
    await page.getByLabel('Learner Reference No. (LRN)').fill('123');
    await page.getByLabel('Contact Number').fill('12345');
    await page.getByRole('button', { name: 'Submit Enrollment' }).click();

    await expect(page.getByText('The LRN must be exactly 12 digits.')).toBeVisible();
    await expect(page.getByText('Enter an 11-digit mobile number starting with 09.')).toBeVisible();
    await expect(page.getByText("Enter at least one parent's or guardian's name.")).toBeVisible();
    await expect(page.getByLabel('Learner Reference No. (LRN)')).toHaveAttribute('aria-invalid', 'true');
});
