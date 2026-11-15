import { expect, test } from '@playwright/test';
import { countInList, login } from './helpers';

const workbook = 'tests/e2e/.tmp/ENROLLMENT_ENCODING_TEST.xlsx';

test('check a workbook, review problems, then actually import it', async ({ page }) => {
    await login(page, 'registrar');
    await page.goto('/enrollments/create');
    await page.getByRole('link', { name: 'Bulk Import' }).click();
    await expect(page.getByRole('heading', { name: 'Bulk Import Students' })).toBeVisible();

    await page.locator('#file').setInputFiles(workbook);
    await page.getByRole('button', { name: 'Check File' }).click();

    await expect(page.getByRole('heading', { name: 'Review before importing' })).toBeVisible();
    await expect(page.getByText('Row 29: LRN has 11 digits instead of 12.')).toBeVisible();
    expect(await countInList(page, 'imported')).toBe(0);

    // This button used to send an empty token and silently do nothing.
    await page.goto('/imports');
    await page.getByRole('button', { name: 'Import 26 learners' }).click();
    await expect(page.getByRole('heading', { name: 'Import finished' })).toBeVisible();
    await expect(page.getByRole('status').filter({ hasText: 'Imported 26 rows.' })).toBeVisible();

    expect(await countInList(page, 'imported')).toBe(26);

    // Importing the same file again updates instead of duplicating.
    await page.goto('/imports');
    await page.locator('#file').setInputFiles(workbook);
    await page.getByRole('button', { name: 'Check File' }).click();
    await expect(page.getByText('New learners to add')).toBeVisible();
    await expect(page.locator('dt:text("New learners to add") + dd')).toHaveText('0');
    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByRole('button', { name: 'Check File' })).toBeVisible();
});

test('a file that is not a real workbook is rejected with a message', async ({ page }) => {
    await login(page, 'registrar');
    await page.goto('/imports');
    await page.locator('#file').setInputFiles({
        name: 'ENROLLMENT.xlsx',
        mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        buffer: Buffer.from('not a spreadsheet'),
    });
    await page.getByRole('button', { name: 'Check File' }).click();
    await expect(page.getByText(/could not be read|must be a file of type/)).toBeVisible();
});
