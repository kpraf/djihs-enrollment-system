import { expect, type APIRequestContext, type Browser, type BrowserContext, type Page } from '@playwright/test';

export const PASSWORD = 'Password123';

export async function login(page: Page, username: string, password = PASSWORD) {
    await page.goto('/login');
    await page.getByLabel('Username').fill(username);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Sign In' }).click();
    // Wait for the sign-in to finish, either into the app or with an error shown.
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 10_000 }).catch(() => {});
}

/** A signed-in browser of its own, like a second staff member's computer. */
export async function staffBrowser(browser: Browser, username: string): Promise<{ context: BrowserContext; page: Page }> {
    const context = await browser.newContext();
    const page = await context.newPage();
    await login(page, username);
    await expect(page).toHaveURL(/\/dashboard/);
    return { context, page };
}

let counter = 0;

/** A 12-digit LRN no other test uses. */
export function uniqueLrn(): string {
    counter += 1;
    const stamp = Date.now().toString().slice(-8);
    return `1${stamp}${String(counter).padStart(3, '0')}`.slice(0, 12);
}

/**
 * Letters derived from an LRN, to make a test learner's name unique. Without
 * it, a re-run would create a same-name, same-birthday learner and correctly
 * trip the duplicate guard.
 */
export function tag(lrn: string): string {
    return lrn
        .slice(-8)
        .split('')
        .map((d) => 'ABCDEFGHIJ'[Number(d)])
        .join('');
}

export interface Learner {
    lrn: string;
    last: string;
    first: string;
    birthdate?: string;
    sex?: 'male' | 'female';
    grade?: string;
    strand?: string;
}

/** Fills the New Enrollment form the way an encoder would. */
export async function fillEnrollmentForm(page: Page, l: Learner) {
    await page.getByLabel('Grade Level to Enroll').selectOption(l.grade ?? '11');
    await page.getByLabel('Learner Reference No. (LRN)').fill(l.lrn);
    await page.getByRole('group', { name: 'REGULAR', exact: true }).getByLabel('New Student').check();
    await page.getByRole('radio', { name: new RegExp(`^${l.strand ?? 'HUMSS'}`) }).check();
    // The learner's own name fields are required, so their labels end in " *".
    await page.getByLabel(/^Last Name \*/).fill(l.last);
    await page.getByLabel(/^Given Name \*/).fill(l.first);
    await page.getByLabel('Birthdate').fill(l.birthdate ?? '2009-05-20');
    await page.getByLabel('Sex').selectOption(l.sex ?? 'female');
    await page.getByLabel('Barangay').fill('Don Jose');
    await page.getByLabel('Municipality/City').fill('Santa Rosa');
    await page.getByLabel('Province').fill('Laguna');
    // Mother's maiden name. The parent rows are labelled for screen readers.
    await page.getByLabel("Mother's Last Name", { exact: true }).fill('Reyes');
    await page.getByLabel('Contact Number').fill('09171234567');
    await page.getByLabel('The parent, guardian or adult learner was given the privacy notice.').check();
}

/**
 * Sends a form POST the same way the app does, with the CSRF token taken
 * from the signed-in browser's cookie. Does not follow the redirect.
 */
export async function postAs(context: BrowserContext, request: APIRequestContext, url: string, data: Record<string, unknown>) {
    const xsrf = (await context.cookies()).find((c) => c.name === 'XSRF-TOKEN');
    return request.post(url, {
        data,
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf?.value ?? ''), Accept: 'text/html', Referer: '/enrollments/create' },
        maxRedirects: 0,
    });
}

/** Total learners the Student Management search finds. */
export async function countInList(page: Page, search: string): Promise<number> {
    await page.goto(`/enrollments?search=${encodeURIComponent(search)}`);
    const text = await page.locator('text=/Showing \\d+ to \\d+ of \\d+ results/').innerText();
    return Number(text.match(/of (\d+) results/)![1]);
}

/** Encodes a learner on the New Enrollment form and waits for it to save. */
export async function encode(page: Page, l: Learner) {
    await page.goto('/enrollments/create');
    await fillEnrollmentForm(page, l);
    await page.getByRole('button', { name: 'Submit Enrollment' }).click();
    await expect(page.getByLabel('Learner Reference No. (LRN)')).toHaveValue('');
}

/** Approves one pending learner from Review Enrollments. */
export async function approve(page: Page, lrn: string) {
    await page.goto(`/reviews?search=${lrn}`);
    // The button in this learner's row, not the page's "Approve All".
    await page
        .getByRole('row')
        .filter({ hasText: lrn })
        .getByRole('button', { name: /^Approve/ })
        .click();
    await expect(page.getByRole('status').filter({ hasText: 'Approved' })).toBeVisible();
}
