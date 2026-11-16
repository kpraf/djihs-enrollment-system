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
    await row(a.page)
        .getByRole('button', { name: /^Approve/ })
        .click();
    await expect(a.page.getByRole('dialog')).toContainText(lrn);

    await Promise.all([
        a.page.getByRole('dialog').getByRole('button', { name: 'Yes, approve' }).click(),
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
    await expect(registrar.page.getByRole('dialog')).toContainText('Religion');
    await registrar.page.getByRole('dialog').getByRole('button', { name: 'Yes, approve' }).click();
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

test('placing and removing a learner both ask first', async ({ browser }) => {
    const reg = await staffBrowser(browser, 'registrar');
    const lrn = uniqueLrn();
    await encode(reg.page, { lrn, last: 'Placido', first: `Ana ${tag(lrn)}` });
    await approve(reg.page, lrn);

    const key = await staffBrowser(browser, 'keyteacher');
    await key.page.goto(`/section-assignments?grade=11&search=${lrn}`);
    await key.page.getByRole('listitem').filter({ hasText: lrn }).getByRole('checkbox').check();
    const picker = key.page.getByLabel('Section to place selected learners in');
    const integrity = await picker.locator('option', { hasText: 'Integrity' }).first().getAttribute('value');
    await picker.selectOption(integrity!);
    await key.page.getByRole('button', { name: 'Place', exact: true }).click();
    const dialog = key.page.getByRole('dialog');
    await expect(dialog).toContainText('Place 1 learner in Integrity?');
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(key.page.getByRole('listitem').filter({ hasText: lrn })).toBeVisible();

    await key.page.getByRole('button', { name: 'Place', exact: true }).click();
    await dialog.getByRole('button', { name: 'Yes, place them' }).click();
    await expect(key.page.getByRole('status').filter({ hasText: 'Placed 1 learner in Integrity.' })).toBeVisible();

    await key.page.locator('details', { hasText: 'Integrity' }).locator('summary').click();
    await key.page.getByRole('button', { name: new RegExp(`^Remove PLACIDO, ANA ${tag(lrn)}`) }).click();
    await expect(dialog).toContainText('stays enrolled');
    await dialog.getByRole('button', { name: 'Yes, remove' }).click();
    await expect(key.page.getByRole('listitem').filter({ hasText: lrn })).toBeVisible();

    await reg.context.close();
    await key.context.close();
});

test('changing the strand of a placed learner warns that they leave the section', async ({ browser }) => {
    const reg = await staffBrowser(browser, 'registrar');
    const lrn = uniqueLrn();
    await encode(reg.page, { lrn, last: 'Lipat', first: `Rosa ${tag(lrn)}` });
    await approve(reg.page, lrn);

    const key = await staffBrowser(browser, 'keyteacher');
    await key.page.goto(`/section-assignments?grade=11&search=${lrn}`);
    const data = JSON.parse((await key.page.locator('#app').getAttribute('data-page')) ?? '{}').props;
    const integrity = data.sections.find((s: { name: string }) => s.name === 'Integrity');
    const id = data.unassigned.find((u: { lrn: string }) => u.lrn === lrn).id;
    await postAs(key.context, key.context.request, '/section-assignments', { enrollment_ids: [id], section_id: integrity.id });

    await reg.page.goto(`/enrollments/${id}/edit`);
    await reg.page.getByRole('radio', { name: /^ABM/ }).check();
    await reg.page.getByRole('button', { name: 'Save Changes' }).click();
    const dialog = reg.page.getByRole('dialog');
    await expect(dialog).toContainText('Take the learner out of Integrity?');
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(reg.page).toHaveURL(new RegExp(`/enrollments/${id}/edit`));

    await reg.page.getByRole('button', { name: 'Save Changes' }).click();
    await dialog.getByRole('button', { name: 'Yes, save changes' }).click();
    await expect(reg.page.getByRole('status').filter({ hasText: 'Changes saved.' })).toBeVisible();
    await expect(reg.page.getByText('Strand: HUMSS → ABM')).toBeVisible();

    await reg.context.close();
    await key.context.close();
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
    await expect(ict.page.getByRole('dialog')).toContainText('Staff will no longer be able to encode');
    await ict.page.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click();
    await expect(ict.page.getByText('Enrollment open')).toBeVisible();

    await ict.page.getByRole('button', { name: 'Close Enrollment' }).click();
    await ict.page.getByRole('dialog').getByRole('button', { name: 'Yes, close enrollment' }).click();
    await expect(ict.page.getByText('Enrollment closed')).toBeVisible();

    await reg.page.goto('/enrollments/create');
    await expect(reg.page.getByText(/Enrollment for school year .* is closed/)).toBeVisible();

    await ict.page.getByRole('button', { name: 'Open Enrollment' }).click();
    await ict.page.getByRole('dialog').getByRole('button', { name: 'Yes, open enrollment' }).click();
    await expect(ict.page.getByText('Enrollment open')).toBeVisible();

    await ict.context.close();
    await reg.context.close();
});

test('a strand is switched off only after confirming, and back on with one tap', async ({ browser }) => {
    const key = await staffBrowser(browser, 'keyteacher');
    await key.page.goto('/strands');
    const toggle = key.page.getByRole('switch', { name: 'IA-EIM offered' });
    await expect(toggle).toHaveAttribute('aria-checked', 'true');

    await toggle.click();
    await expect(key.page.getByRole('dialog')).toContainText('Stop offering IA-EIM?');
    await key.page.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click();
    await expect(toggle).toHaveAttribute('aria-checked', 'true');

    await toggle.click();
    await key.page.getByRole('dialog').getByRole('button', { name: 'Yes, make inactive' }).click();
    await expect(toggle).toHaveAttribute('aria-checked', 'false');
    await expect(toggle).toContainText('Inactive');

    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-checked', 'true');

    await key.context.close();
});

test('advisers download SF1 and class lists; reports draw charts', async ({ browser }) => {
    const adviser = await staffBrowser(browser, 'adviser');
    await adviser.page.goto('/my-section');
    await expect(adviser.page.getByRole('heading', { name: /Students List/ })).toBeVisible();
    const [sf1] = await Promise.all([adviser.page.waitForEvent('download'), adviser.page.getByRole('link', { name: 'Export SF1' }).click()]);
    expect(sf1.suggestedFilename()).toMatch(/^DJIHS_SF1_Grade_11_.*Integrity_SY\d{4}-\d{4}_\d{4}-\d{2}-\d{2}\.xlsx$/);
    const [csv] = await Promise.all([adviser.page.waitForEvent('download'), adviser.page.getByRole('link', { name: 'Export CSV' }).click()]);
    expect(csv.suggestedFilename()).toMatch(/\.csv$/);
    await adviser.context.close();

    const registrar = await staffBrowser(browser, 'registrar');
    await registrar.page.goto('/reports');
    await expect(registrar.page.getByRole('heading', { name: 'Reports', exact: true })).toBeVisible();
    for (const heading of ['This year at a glance', 'Where learners are', 'How enrollment is changing', 'Who the learners are', 'Needs attention']) {
        await expect(registrar.page.getByRole('heading', { name: heading })).toBeVisible();
    }
    // The numbers are drawn as charts, each with its exact figures one click away.
    await expect(registrar.page.getByRole('img', { name: /^Male and female: Male \d+/ })).toBeVisible();
    await expect(registrar.page.getByRole('img', { name: /^Learners by grade level: / })).toBeVisible();
    await expect(registrar.page.getByRole('img', { name: /^Grade 11 learners by strand: / })).toBeVisible();
    await expect(registrar.page.getByRole('img', { name: /^Type of learner: / })).toBeVisible();
    await expect(registrar.page.getByRole('cell', { name: 'All grades' })).toHaveCount(0);
    await registrar.page.getByText("Show the full table, like the workbook's MONITORING tab").click();
    await expect(registrar.page.getByRole('cell', { name: 'All grades' })).toBeVisible();
    // The DepEd rates are for administrators only.
    await expect(registrar.page.getByRole('heading', { name: 'DepEd indicators' })).toHaveCount(0);

    // The dashboard leads with charts too.
    await registrar.page.goto('/dashboard');
    await expect(registrar.page.getByRole('img', { name: /^Enrolled learners by grade level: / })).toBeVisible();
    await expect(registrar.page.getByRole('img', { name: /^Senior high learners by strand: / })).toBeVisible();
    await registrar.page.goto('/reports');
    for (const [label, pattern] of [
        ['Export Excel', /^DJIHS_Enrollment_Report_SY\d{4}-\d{4}_\d{4}-\d{2}-\d{2}\.xlsx$/],
        ['Export CSV', /^DJIHS_Enrollment_Report_SY\d{4}-\d{4}_\d{4}-\d{2}-\d{2}\.csv$/],
    ] as const) {
        const [file] = await Promise.all([registrar.page.waitForEvent('download'), registrar.page.getByRole('link', { name: label }).click()]);
        expect(file.suggestedFilename()).toMatch(pattern);
    }
    await registrar.page.goto('/imports');
    const [template] = await Promise.all([
        registrar.page.waitForEvent('download'),
        registrar.page.getByRole('link', { name: 'CSV Template' }).click(),
    ]);
    expect(template.suggestedFilename()).toBe('ENROLLMENT_ENCODING_TEMPLATE.csv');
    await registrar.context.close();

    const subject = await staffBrowser(browser, 'subject');
    await subject.page.goto('/my-students');
    await expect(subject.page.getByText('General Mathematics').first()).toBeVisible();
    await subject.context.close();
});
