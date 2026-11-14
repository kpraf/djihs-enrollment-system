# End-to-end tests

Real browsers against a real server and a real PostgreSQL database. Several PHP workers run, so the concurrency tests send genuinely simultaneous requests.

Requirements: PostgreSQL with an empty `djihs_e2e` database, the built frontend, and Chromium for Playwright.

```bash
createdb djihs_e2e
```

```bash
npm run build && npx playwright install chromium
```

```bash
npx playwright test
```

Use another database with `E2E_DB_URL=postgresql://user:pass@host:5432/name`. The suite wipes it on every run.
