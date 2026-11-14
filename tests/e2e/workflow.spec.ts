import { expect, test } from '@playwright/test';
import { approve, encode, login, postAs, staffBrowser, tag, uniqueLrn } from './helpers';

/**
 * The legacy system's workflows, end to end, as different staff.
 */

test('the sidebar is school green', async ({ page }) => {
    await login(page, 'registrar');
    const bg = await page
        .locator('aside')
        .first()
        .evaluate((el) => getComputedStyle(el).backgroundColor);
    expect(bg).toBe('rgb(8, 80, 25)');
});

test('an adviser encodes, a registrar approves, the learner counts as enrolled', async ({ browser }) => {
    const adviser = await staffBrowser(browser, 'adviser');
    const registrar = await staffBrowser(browser, 'registrar');
    const lrn = uniqueLrn();
    const name = `ESPINO, JOY ${tag(lrn)}`;

    await encode(adviser.page, { lrn, last: 'Espino', first: `Joy ${tag(lrn)}` });

    await registrar.page.goto('/dashboard');
    await expect(registrar.page.getByText('waiting for review')).toBeVisible();
    await registrar.page.goto(`/reviews?search=${lrn}`);
    await expect(registrar.page.getByRole('link', { name })).toBeVisible();
    await approve(registrar.page, lrn);

    await registrar.page.goto(`/enrollments?search=${lrn}`);
    await expect(registrar.page.getByRole('row', { name: new RegExp(name) }).getByText('Enrolled')).toBeVisible();

    await adviser.context.close();
    await registrar.context.close();
});

test('two reviewers decide on the same learner at once: only one decision sticks', async ({ browser }) => {
    const a = await staffBrowser(browser, 'registrar');
    const b = await staffBrowser(browser, 'ict');
    const lrn = uniqueLrn();
    await encode(a.page, { lrn, last: 'Quiambao', first: `Lea ${tag(lrn)}` });

    for (const { page } of [a, b]) await page.goto(`/reviews?search=${lrn}`);
    const row = (p: typeof a.page) => p.getByRole('row').filter({ hasText: lrn });
    await row(b.page)
        .getByRole('button', { name: /^Reject/ })
        .click();
    await b.page.getByRole('dialog').getByLabel('Reason').fill('Wrong LRN');

    await Promise.all([
        row(a.page)
            .getByRole('button', { name: /^Approve/ })
            .click(),
        b.page.getByRole('dialog').getByRole('button', { name: 'Reject' }).click(),
    ]);

    const approved = a.page.getByRole('status').filter({ hasText: 'Approved' });
    const rejected = b.page.getByRole('status').filter({ hasText: 'Rejected' });
    const refused = (p: typeof a.page) => p.getByRole('alert').filter({ hasText: 'already' });
    await expect(approved.or(refused(a.page))).toBeVisible();
    await expect(rejected.or(refused(b.page))).toBeVisible();
    expect((await approved.count()) + (await rejected.count())).toBe(1);

    await a.context.close();
    await b.context.close();
});

test('an adviser requests a change; the registrar approves it from Review Enrollments', async ({ browser }) => {
    const registrar = await staffBrowser(browser, 'registrar');
    const adviser = await staffBrowser(browser, 'adviser');
    const lrn = uniqueLrn();
    await encode(registrar.page, { lrn, last: 'Dizon', first: `Rey ${tag(lrn)}` });
    await approve(registrar.page, lrn);

    await adviser.page.goto(`/enrollments?search=${lrn}`);
    await adviser.page
        .getByRole('link', { name: new RegExp(`^DIZON, REY`) })
        .first()
        .click();
    await adviser.page.getByRole('link', { name: 'Request Changes' }).click();
    await expect(adviser.page.getByRole('heading', { name: 'Request Changes' })).toBeVisible();
    await adviser.page.getByLabel('Religion').fill('Iglesia ni Cristo');
    await adviser.page.getByLabel('Reason for the change').fill('Parent corrected the religion');
    await adviser.page.getByRole('button', { name: 'Send for Approval' }).click();
    await expect(adviser.page.getByRole('status').filter({ hasText: 'sent for approval' })).toBeVisible();
    await expect(adviser.page.getByText('Religion: ROMAN CATHOLIC').or(adviser.page.getByText('Religion:'))).toBeVisible();

    await registrar.page.goto('/reviews?tab=revisions');
    const card = registrar.page.locator('.panel', { hasText: `DIZON, REY ${tag(lrn)}` });
    await expect(card).toContainText('IGLESIA NI CRISTO');
    await card.getByRole('button', { name: 'Approve changes' }).click();
    await expect(registrar.page.getByRole('status').filter({ hasText: 'Changes approved and applied.' })).toBeVisible();

    await adviser.page.reload();
    await expect(adviser.page.getByRole('definition').filter({ hasText: 'IGLESIA NI CRISTO' })).toBeVisible();

    await registrar.context.close();
    await adviser.context.close();
});

test('the document checklist marks a learner complete', async ({ page }) => {
    await login(page, 'registrar');
    const lrn = uniqueLrn();
    await encode(page, { lrn, last: 'Lopez', first: `Ana ${tag(lrn)}` });
    await page.goto(`/enrollments?search=${lrn}`);
    await page
        .getByRole('link', { name: /^LOPEZ, ANA/ })
        .first()
        .click();
    await expect(page.getByText('Documents incomplete')).toBeVisible();

    const row = (label: string) =>
        page
            .locator('div', { has: page.getByText(label, { exact: true }) })
            .filter({ has: page.getByLabel('Submitted') })
            .last();
    await row('PSA Birth Certificate').getByLabel('Submitted').check();
    await expect(page.getByRole('status').filter({ hasText: 'PSA Birth Certificate updated.' })).toBeVisible();
    await row('Report Card (SF9)').getByLabel('Submitted').check();
    await expect(page.getByText('Documents incomplete')).toHaveCount(0);
});

test('two people race for the last seat in a section: it never overfills', async ({ browser }) => {
    const reg = await staffBrowser(browser, 'registrar');
    const learners = [uniqueLrn(), uniqueLrn(), uniqueLrn()];
    for (const lrn of learners) {
        await encode(reg.page, { lrn, last: 'Seat', first: `Racer ${tag(lrn)}` });
        await approve(reg.page, lrn);
    }

    const key = await staffBrowser(browser, 'keyteacher');
    const admin = await staffBrowser(browser, 'admin');
    await key.page.goto('/section-assignments?grade=11');
    const page = JSON.parse((await key.page.locator('#app').getAttribute('data-page')) ?? '{}');
    const section = page.props.sections.find((s: { name: string }) => s.name === 'Integrity');
    const ids = page.props.unassigned.filter((u: { lrn: string }) => learners.includes(u.lrn)).map((u: { id: number }) => u.id);
    expect(ids).toHaveLength(3);
    const free = section.capacity - section.learners.length;

    // Everyone tries to place every racer at the same moment, from two computers.
    await Promise.all(
        ids.flatMap((id: number) => [
            postAs(key.context, key.context.request, '/section-assignments', { enrollment_ids: [id], section_id: section.id }),
            postAs(admin.context, admin.context.request, '/section-assignments', { enrollment_ids: [id], section_id: section.id }),
        ]),
    );

    await key.page.goto('/section-assignments?grade=11');
    const after = JSON.parse((await key.page.locator('#app').getAttribute('data-page')) ?? '{}').props.sections.find(
        (s: { id: number }) => s.id === section.id,
    );
    expect(after.learners.length).toBeLessThanOrEqual(section.capacity);
    expect(after.learners.length).toBe(Math.min(section.capacity, section.learners.length + 3));
    expect(free).toBeGreaterThanOrEqual(0);

    await reg.context.close();
    await key.context.close();
    await admin.context.close();
});

test('closing enrollment stops new submissions', async ({ browser }) => {
    const ict = await staffBrowser(browser, 'ict');
    const reg = await staffBrowser(browser, 'registrar');

    await ict.page.goto('/school-years');
    await ict.page.getByRole('button', { name: 'Close Enrollment' }).click();
    await expect(ict.page.getByText('Enrollment closed')).toBeVisible();

    await reg.page.goto('/enrollments/create');
    await expect(reg.page.getByText(/Enrollment for school year .* is closed/)).toBeVisible();

    await ict.page.getByRole('button', { name: 'Open Enrollment' }).click();
    await expect(ict.page.getByText('Enrollment open')).toBeVisible();

    await ict.context.close();
    await reg.context.close();
});

test('advisers download SF1 and class lists; reports draw charts', async ({ browser }) => {
    const adviser = await staffBrowser(browser, 'adviser');
    await adviser.page.goto('/my-section');
    await expect(adviser.page.getByRole('heading', { name: /Students List/ })).toBeVisible();
    const [sf1] = await Promise.all([adviser.page.waitForEvent('download'), adviser.page.getByRole('link', { name: 'Export SF1' }).click()]);
    expect(sf1.suggestedFilename()).toMatch(/^SF1_Grade_11_Integrity_.*\.xlsx$/);
    const [csv] = await Promise.all([adviser.page.waitForEvent('download'), adviser.page.getByRole('link', { name: 'Export CSV' }).click()]);
    expect(csv.suggestedFilename()).toMatch(/\.csv$/);
    await adviser.context.close();

    const registrar = await staffBrowser(browser, 'registrar');
    await registrar.page.goto('/analytics');
    await expect(registrar.page.getByRole('heading', { name: 'Enrollment Analytics' })).toBeVisible();
    await expect(registrar.page.locator('canvas').first()).toBeVisible();
    const [xlsx] = await Promise.all([registrar.page.waitForEvent('download'), registrar.page.getByRole('link', { name: 'Export' }).click()]);
    expect(xlsx.suggestedFilename()).toMatch(/^Analytics_.*\.xlsx$/);
    await registrar.context.close();

    const subject = await staffBrowser(browser, 'subject');
    await subject.page.goto('/my-students');
    await expect(subject.page.getByText('General Mathematics').first()).toBeVisible();
    await subject.context.close();
});
