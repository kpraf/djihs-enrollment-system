import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { serverEnv } from '../../playwright.config';

/**
 * Fresh database with known accounts, and a fake workbook to import.
 */
export default function globalSetup() {
    const env = { ...process.env, ...serverEnv };
    execFileSync('php', ['artisan', 'migrate:fresh', '--force', '--seeder=Database\\Seeders\\E2ESeeder', '--seed'], { env, stdio: 'inherit' });
    execFileSync('php', ['artisan', 'cache:clear'], { env, stdio: 'ignore' });

    mkdirSync('tests/e2e/.tmp', { recursive: true });
    execFileSync('php', ['tests/e2e/fixtures/make-workbook.php', 'tests/e2e/.tmp/ENROLLMENT_ENCODING_TEST.xlsx'], { stdio: 'inherit' });
}
