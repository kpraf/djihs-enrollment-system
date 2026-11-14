# DJIHS Enrollment System

Enrollment system for the Senior High School of **Don Jose Integrated High School**, Santa Rosa City, Laguna.

It replaces the school's `ENROLLMENT_ENCODING` Excel workbook. Staff encode learners in one place, and the monitoring reports the workbook built with COUNTIFS formulas are generated automatically.

This is a full rewrite of the original HTML/PHP course project from Mapúa Malayan Colleges Laguna. The old code is kept in [`legacy/`](legacy/) for reference only.

## Stack

| Layer | Choice |
| --- | --- |
| Backend | Laravel 12, PHP 8.3 |
| Frontend | React 19 + TypeScript through Inertia.js, Tailwind CSS 4, shadcn/ui |
| Database | PostgreSQL in production, SQLite for local development and tests |
| Spreadsheets | PhpSpreadsheet |
| Hosting | Render, Docker web service with managed Postgres |

## What works today

- **Sign-in with a username.** Staff accounts are created by an administrator. There is no public sign-up. New and reset accounts get a temporary password that must be changed at first sign-in.
- **Permissions enforced on the server.** Every page and action checks the user's role on the server, not just in the browser.
- **Dashboard.** Rebuilds the workbook's MONITORING and DAILY_MONITORING tabs: counts per grade and strand by sex, learner type, Indigenous Peoples and 4Ps, track subtotals, Grade 10 retention, and learners encoded per day.
- **Enrollment list.** Search by name or LRN, and filter by school year, grade, strand and status.
- **Spreadsheet import.** Upload the school's workbook, review problems row by row, then import. Re-importing updates learners instead of duplicating them.
- **User accounts.** Create, edit, deactivate and reset passwords.
- **Audit log.** Every change to users, learners, enrollments, sections, strands and school years is recorded with who made it. Passwords are never logged.

Next up: the encoding form, editing and cancelling enrollments, sections and sectioning, and SF1 export.

## Data model

Seven tables replace the legacy system's twelve.

| Table | Holds |
| --- | --- |
| `users` | Staff accounts and their role |
| `school_years` | For example 2024-2025, which one is active, and the Grade 10 completer count for retention |
| `strands` | ABM, HUMSS, ICT-CSS, HE-COOKERY, IA-EIM, each tagged Academic or TVL |
| `sections` | Sections per school year and grade, with an adviser |
| `students` | Permanent learner facts: LRN, name, birth date, sex, IP, 4Ps, address, legal guardian |
| `enrollments` | One row per learner per school year: grade, strand, section, learner type, status, weight and height for SF8 |
| `audit_logs` | Who changed what, written automatically |

These legacy tables were folded in:
- **Grade levels** became a fixed list in code.
- **Parent and guardian** columns moved onto `students`, because the school records one legal guardian.
- **Section assignments** became `enrollments.section_id`.
- **Revision requests** were replaced by direct edits plus the audit log.

## Roles

| Ability | Admin | ICT Coordinator | Registrar | Key Teacher | Adviser | Subject Teacher |
| --- | :-: | :-: | :-: | :-: | :-: | :-: |
| Manage user accounts | ✓ | ✓ | | | | |
| Manage school years, strands, sections | ✓ | ✓ | | ✓ | | |
| Encode enrollments | ✓ | ✓ | ✓ | | ✓ | |
| Import the spreadsheet | ✓ | ✓ | ✓ | | | |
| View enrollments | ✓ | ✓ | ✓ | ✓ | ✓ | |
| View reports | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| View audit log | ✓ | ✓ | ✓ | | | |

Only administrators can create or change administrator accounts. Change a role's abilities in [`app/Enums/Role.php`](app/Enums/Role.php).

## Local development

Requirements: PHP 8.3 with `pdo_sqlite`, `zip` and `gd`, Composer, and Node 22.

```bash
composer install
```

```bash
npm install
```

```bash
cp .env.example .env
```

```bash
php artisan key:generate
```

```bash
touch database/database.sqlite && php artisan migrate --seed
```

```bash
composer run dev
```

Open http://localhost:8000. Seeding prints a generated password for the `admin` account. Local seeding also creates fake learners and one demo account per role, such as `demo.registrar`, all with the password `password`.

Run the checks:

```bash
php artisan test
```

```bash
npx tsc --noEmit && npx eslint resources/js && vendor/bin/pint --test
```

## Importing the school's workbook

In the app, open **Import spreadsheet**, choose the `.xlsx` file and school year, and click **Check file**. Nothing is saved until you confirm.

From the command line:

```bash
php artisan enrollments:import path/to/ENROLLMENT_ENCODING_SY2024-2025.xlsx --school-year=2024-2025 --dry-run
```

The importer reads the `MAIN_ENCODING_SHEET` tab and finds columns by their header text. It cleans what Excel mangles:
- **Phone numbers** get their dropped leading zero back.
- **Town spellings** are unified, so "STA. ROSA" and "SANTAROSA" both become Santa Rosa.
- **Bad heights** in centimetres are converted, and impossible values are flagged.

It also never merges two different learners who share an LRN. The second learner is saved without an LRN and flagged for correction.

Never commit real workbooks. `*.xlsx` is git-ignored for that reason.

## Deploying to Render

1. In Render, choose **New > Blueprint** and pick this repository. [`render.yaml`](render.yaml) creates the web service and a Postgres database in the Singapore region.
2. Fill in the three secret values when asked:
   - **`APP_KEY`**: run `php artisan key:generate --show` locally and paste the output.
   - **`APP_URL`**: the site's address, for example `https://djihs-enrollment.onrender.com`.
   - **`ADMIN_PASSWORD`**: the first administrator's temporary password.
3. Deploy. Each deploy runs migrations and the idempotent seeders, then starts Apache. See [`docker/start.sh`](docker/start.sh).
4. Sign in as `admin` and change the password when prompted.

The Blueprint uses paid plans on purpose. Render's free Postgres is temporary and has no backups, and the free web service sleeps when idle. Neither is suitable for learner records. Check Render's current pricing before deploying.

## Data privacy

The system stores personal data of minors, including LRN, birth date, address, Indigenous Peoples membership and 4Ps status. It falls under the Data Privacy Act of 2012.
- Give each staff member their own account and deactivate accounts when people leave.
- Keep `APP_DEBUG=false` in production.
- Use the database's backups, and restrict who can download exports.

## Project layout

```text
app/
  Enums/            Roles, abilities, grade levels, learner types, statuses
  Http/Controllers/ One controller per feature, not per role
  Models/           Eloquent models; Concerns/Auditable writes the audit log
  Services/Import/  Encoding workbook importer
  Services/Reports/ Monitoring and daily reports
database/           Migrations, factories, seeders
resources/js/       React pages, components and layouts
tests/              Feature tests, plus a fake-workbook builder
docker/             Apache, PHP and start-up script for the Docker image
legacy/             The original HTML/PHP project, kept for reference
```
