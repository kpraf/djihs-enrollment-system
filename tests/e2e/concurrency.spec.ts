import { expect, test } from '@playwright/test';
import { countInList, fillEnrollmentForm, postAs, staffBrowser, tag, uniqueLrn } from './helpers';

/**
 * Several staff using the system at the same time, against PostgreSQL
 * with several PHP workers, so requests genuinely overlap.
 */

test('two encoders submit the same new learner at the same moment: one record, one clear message', async ({ browser }) => {
    const a = await staffBrowser(browser, 'registrar');
    const b = await staffBrowser(browser, 'registrar2');
    const lrn = uniqueLrn();

    for (const { page } of [a, b]) {
        await page.goto('/enrollments/create');
        await fillEnrollmentForm(page, { lrn, last: 'Villanueva', first: `Carlo ${tag(lrn)}`, sex: 'male' });
    }

    // Both press Submit together.
    await Promise.all([
        a.page.getByRole('button', { name: 'Submit Enrollment' }).click(),
        b.page.getByRole('button', { name: 'Submit Enrollment' }).click(),
    ]);

    const outcome = async (page: typeof a.page) => {
        const saved = page.getByRole('status').filter({ hasText: `Submitted VILLANUEVA, CARLO ${tag(lrn)}` });
        const refused = page.getByText(/(Someone else just enrolled this learner|already encoded for school year)/);
        await expect(saved.or(refused)).toBeVisible();
        return (await saved.count()) > 0 ? 'saved' : 'refused';
    };

    const results = [await outcome(a.page), await outcome(b.page)].sort();
    expect(results).toEqual(['refused', 'saved']);
    expect(await countInList(a.page, lrn)).toBe(1);

    await a.context.close();
    await b.context.close();
});

test('ten identical submissions at once (double clicks, slow network) still save exactly one learner', async ({ browser }) => {
    const { context, page } = await staffBrowser(browser, 'registrar');
    const lrn = uniqueLrn();
    await page.goto('/enrollments/create');

    // The strand id, from the page data the server embedded on load.
    const pageData = JSON.parse((await page.locator('#app').getAttribute('data-page')) ?? '{}');
    const humss = pageData.props.options.strands.find((s: { code: string }) => s.code === 'HUMSS')?.id;
    expect(humss).toBeTruthy();

    const body = {
        grade_level: 11,
        strand_id: humss,
        learner_type: 'new_student',
        is_irregular: false,
        lrn,
        last_name: 'Burst',
        first_name: `Test ${tag(lrn)}`,
        birthdate: '2009-06-01',
        sex: 'male',
        barangay: 'Don Jose',
        municipality: 'Santa Rosa',
        province: 'Laguna',
        mother_last_name: 'Reyes',
        contact_number: '09171234567',
    };

    const responses = await Promise.all(Array.from({ length: 10 }, () => postAs(context, context.request, '/enrollments', body)));
    const statuses = responses.map((r) => r.status());

    expect(
        statuses.filter((s) => s >= 500),
        `server errors: ${statuses.join(',')}`,
    ).toHaveLength(0);
    expect(statuses.every((s) => s === 302)).toBe(true);
    expect(await countInList(page, lrn)).toBe(1);

    await context.close();
});

test('two staff edit the same learner: the second save is refused instead of overwriting', async ({ browser }) => {
    const a = await staffBrowser(browser, 'registrar');
    const b = await staffBrowser(browser, 'ict');
    const lrn = uniqueLrn();

    await a.page.goto('/enrollments/create');
    await fillEnrollmentForm(a.page, { lrn, last: 'Mendoza', first: `Grace ${tag(lrn)}` });
    await a.page.getByRole('button', { name: 'Submit Enrollment' }).click();
    await expect(a.page.getByRole('status').filter({ hasText: `Submitted MENDOZA, GRACE ${tag(lrn)}` })).toBeVisible();

    await a.page.goto(`/enrollments?search=${lrn}`);
    await a.page.getByRole('link', { name: `MENDOZA, GRACE ${tag(lrn)}`, exact: true }).click();
    await a.page.waitForURL(/\/enrollments\/\d+$/);
    const showUrl = a.page.url();

    // Both open the edit form.
    await a.page.goto(`${showUrl}/edit`);
    await b.page.goto(`${showUrl}/edit`);

    await a.page.getByLabel('Religion').fill('Roman Catholic');
    await a.page.getByRole('button', { name: 'Save Changes' }).click();
    await expect(a.page.getByRole('status').filter({ hasText: 'Changes saved.' })).toBeVisible();

    // B still has the old version open.
    await b.page.getByLabel('Religion').fill('Born Again');
    await b.page.getByRole('button', { name: 'Save Changes' }).click();
    await expect(b.page.getByRole('alert').filter({ hasText: 'Someone else changed this record while you were editing it' })).toBeVisible();
    // B now sees A's saved value, ready to re-check.
    await expect(b.page.getByLabel('Religion')).toHaveValue('ROMAN CATHOLIC');

    await a.page.goto(showUrl);
    await expect(a.page.getByRole('definition').filter({ hasText: 'ROMAN CATHOLIC' })).toBeVisible();
    await expect(a.page.getByText('BORN AGAIN')).toHaveCount(0);

    await a.context.close();
    await b.context.close();
});

test('two staff re-enroll the same old student at once: enrolled once', async ({ browser }) => {
    const a = await staffBrowser(browser, 'registrar');
    const b = await staffBrowser(browser, 'adviser');
    const lrn = uniqueLrn();

    // An existing learner from earlier, enrolled once.
    await a.page.goto('/enrollments/create');
    await fillEnrollmentForm(a.page, { lrn, last: 'Aquino', first: `Benjie ${tag(lrn)}`, sex: 'male' });
    await a.page.getByRole('button', { name: 'Submit Enrollment' }).click();
    await expect(a.page.getByRole('status').filter({ hasText: `Submitted AQUINO, BENJIE ${tag(lrn)}` })).toBeVisible();

    // Both try again at the same time, for example from two enrollment desks.
    for (const { page } of [a, b]) {
        await page.goto('/enrollments/create');
        await fillEnrollmentForm(page, { lrn, last: 'Aquino', first: `Benjie ${tag(lrn)}`, sex: 'male', grade: '12' });
    }
    await Promise.all([
        a.page.getByRole('button', { name: 'Submit Enrollment' }).click(),
        b.page.getByRole('button', { name: 'Submit Enrollment' }).click(),
    ]);

    for (const { page } of [a, b]) {
        await expect(page.getByText(/already encoded for school year|Someone else just enrolled/)).toBeVisible();
    }
    expect(await countInList(a.page, lrn)).toBe(1);

    await a.context.close();
    await b.context.close();
});
