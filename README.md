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

The interface keeps the look of the original DJIHS pages: the green sidebar, the sign-in card, the enrollment form layout and the tables.

- **Sign-in with a username.** Staff accounts are created by an administrator. There is no public sign-up. New and reset accounts get a temporary password, shown once, that must be changed at first sign-in.
- **Permissions enforced on the server.** Every page and action checks the user's role on the server, not just in the browser.
- **New Enrollment.** The legacy enrollment form. Typing a 12-digit LRN immediately says whether the learner is new, already in the system, or already enrolled this year.
- **Safe with many encoders at once.** The same learner cannot be enrolled twice, even when two staff press Submit at the same moment. A learner with the same name and birth date under another LRN must be confirmed as a different person. Two people editing one record cannot overwrite each other: the later save is refused and shows the latest version.
- **Student Management.** Search by any part of the name or LRN, filter, view a learner with their full change history, edit, cancel and restore.
- **Larger text.** Three text sizes for staff who find small text hard to read, available on every page and the sign-in page, and saved to each account.
- **Dashboard.** Rebuilds the workbook's MONITORING and DAILY_MONITORING tabs: counts per grade and strand by sex, learner type, Indigenous Peoples and 4Ps, track subtotals, Grade 10 retention, and learners encoded per day.
- **Spreadsheet import.** Upload the school's workbook (.xlsx) or a CSV, review problems row by row, then import. Re-importing updates learners instead of duplicating them. Blank Excel and CSV templates are provided. An LRN that Excel shortened to a form like 1.60645E+11 is flagged, not saved wrong.
- **Review Enrollments.** Every submission from the form waits as Pending until a registrar, ICT coordinator or adviser approves or rejects it, one at a time or all at once. If two reviewers decide on the same learner at the same moment, only the first decision counts and the other is told who decided.
- **Revision requests.** Advisers don't edit an approved record directly. They send the change with a reason, and the registrar approves it from Review Enrollments. A request is refused if the record changed after it was sent.
- **Document checklist.** PSA or local birth certificate, report card, Form 137, good moral and transfer certificate, per learner. Student Management can list learners with missing documents.
- **Dropped and transferred out.** Change Status marks a learner Cancelled, Dropped or Transferred Out, with a reason, and frees their seat. Restore brings them back.
- **Strands, sections and sectioning.** Key teachers manage strands and sections, set capacity and advisers, assign subject teachers, and place learners by hand or automatically (first come, first served). A full section cannot be overfilled, even by two people at once.
- **School years.** Add, activate and archive school years, and open or close enrollment.
- **My Section and My Students.** Advisers see their class list with SF1, CSV and Excel downloads and a printable list. Subject teachers see the learners in the classes they teach.
- **Reports.** One page that reads top to bottom: this year at a glance with the change from another year, learners by grade and strand (the workbook's MONITORING tab), enrollment trends overall and per strand, learner types and inclusion, and what needs attention, each linking to the list behind it. Administrators also see the DepEd rates: transition, promotion, retention and dropout. CSV and Excel downloads. The old `/analytics` and `/metrics` addresses lead here.
- **Role dashboards.** Each role sees its own summary: pending reviews, its sections, section fill, classes taught, or system status.
- **Data privacy.** A privacy notice for parents, a staff confidentiality agreement, logging of who viewed or downloaded personal data, and a printable record of everything held about a learner. See [Data privacy](#data-privacy).
- **User accounts, audit log and backup.** Create, edit, deactivate and reset accounts, and download the list. Every change is recorded with who made it and shown in readable form. Download the audit log, a blank encoding template, or a full backup as a ZIP of CSV files. Passwords are never logged or backed up.

## Data model

Ten tables replace the legacy system's twelve.

| Table | Holds |
| --- | --- |
| `users` | Staff accounts and their role |
| `school_years` | For example 2024-2025, which one is active, and the Grade 10 completer count for retention |
| `strands` | ABM, HUMSS, ICT-CSS, HE-COOKERY, IA-EIM, each tagged Academic or TVL |
| `sections` | Sections per school year and grade, with an adviser |
| `students` | Permanent learner facts: LRN, name, birth date, sex, religion, IP, disability, 4Ps, address, parents and guardian |
| `enrollments` | One row per learner per school year: grade, strand, section, learner type, status, weight and height for SF8 |
| `document_submissions` | Which documents each enrollment has handed in |
| `revision_requests` | Adviser change requests waiting for approval, with the old and new values |
| `teacher_assignments` | Which teacher teaches which subject in which section |
| `audit_logs` | Who changed what, written automatically |

These legacy tables were folded in:
- **Grade levels** became a fixed list in code.
- **Parent and guardian** columns moved onto `students`, because the school records one legal guardian.
- **Section assignments** became `enrollments.section_id`.
- **Enrollment status history** lives on `enrollments` (who reviewed it, who changed the status, and why) plus the audit log.

## Roles

The Administrator sets what each role can do on **Administration → Roles and Permissions**: a grid of checkboxes, saved with a confirmation that lists every change, and recorded in the Audit Log. A role the school no longer uses (for example Key Teacher) can be **retired**: no new account can be given it, and the accounts that have it move to a role the Administrator picks. **Reset to defaults** puts back the table below.

Two safeguards: the Administrator always keeps *Manage user accounts* and *Manage roles and permissions*, and the Administrator role can't be retired, so nobody can be locked out.

Default permissions:

| Ability | Admin | ICT Coordinator | Registrar | Key Teacher | Adviser | Subject Teacher |
| --- | :-: | :-: | :-: | :-: | :-: | :-: |
| Manage user accounts | ✓ | ✓ | ✓ | | | |
| Manage school years | ✓ | ✓ | | | | |
| Manage strands, sections and sectioning | ✓ | | | ✓ | | |
| Encode enrollments | ✓ | ✓ | ✓ | | ✓ | |
| Review (approve or reject) enrollments | ✓ | ✓ | ✓ | | ✓ | |
| Edit enrollments directly | ✓ | ✓ | ✓ | | | |
| Request changes for approval | ✓ | | | | ✓ | |
| Document checklist | ✓ | ✓ | ✓ | | ✓ | |
| Import the spreadsheet | ✓ | ✓ | ✓ | | | |
| View enrollments | ✓ | ✓ | ✓ | ✓ | ✓ | |
| My Section | | | | | ✓ | |
| My Students | | | | | | ✓ |
| Dashboard reports | ✓ | ✓ | ✓ | ✓ | ✓ | |
| Reports | ✓ | | ✓ | | | |
| DepEd rates on Reports | ✓ | | | | | |
| Audit log | ✓ | ✓ | ✓ | | | |
| Backup | ✓ | ✓ | | | | |
| Roles and permissions | ✓ | | | | | |

Only administrators can create or change administrator accounts. The defaults live in `defaultAbilities()` in [`app/Enums/Role.php`](app/Enums/Role.php); what is saved on the page is in the `role_permissions` and `role_settings` tables.

## Run it on a school computer

The app can run on one Windows, Mac or Linux computer with no hosting and no database server. Everything is stored in a single file, `database/database.sqlite`.

**1. Install the tools (once).**
- On Windows or Mac, install [Laravel Herd](https://herd.laravel.com), which is free and includes PHP and Composer. On Linux, install PHP 8.3 with the `sqlite3`, `pdo_sqlite`, `gd`, `zip`, `mbstring`, `xml` and `fileinfo` extensions, plus Composer.
- Install Node 22 from [nodejs.org](https://nodejs.org) if `node -v` doesn't show version 22 or later.
- Check with `php -v` that PHP is 8.3 or newer. On Windows, an older XAMPP install often comes first in the PATH with PHP 8.2, and setup then fails with "requires php ^8.3". Run `where.exe php` to see every PHP found, and move Herd's `bin` folder above `C:\xampp\php` in **Edit the system environment variables → Environment Variables → Path**. For one terminal only, run `$env:Path = "$env:USERPROFILE\.config\herd\bin;" + $env:Path` instead.

**2. Set it up (once).** In a terminal, inside the project folder, run:

```bash
composer run setup
```

This installs everything, creates the database and builds the screens. It prints a password for the `admin` account; write it down, because you'll be asked to change it at first sign-in. It also creates fake demo learners and one demo account per role, such as `demo.registrar`, all with the password `password`. Running setup again is safe: it keeps your data.

**On Windows, one command does steps 2 and 3:** from the project folder, run `powershell -ExecutionPolicy Bypass -File scripts\windows\start.ps1` (add `-Network` for other computers). It uses Herd's PHP even when XAMPP's is installed, sets up on the first run and starts the app. After a `git pull`, run it again: it installs new packages, rebuilds the screens and updates the database only when something changed, then starts as usual.

[`docs/demo-accounts.pdf`](docs/demo-accounts.pdf) lists the demo accounts and things to try.

**3. Start it.** To use it on this computer only:

```bash
composer run start
```

Then open http://localhost:8000.

To let other computers on the same school network use it:

```bash
composer run start:network
```

Then, on the other computers, open `http://<this computer's IP address>:8000`. On Windows, find the address with `ipconfig` (IPv4 Address). If Windows asks whether to allow PHP through the firewall, allow it on **private** networks only. The app stops when the terminal is closed.

**Before real use:**
- Start from an empty database without demo data. Stop the app, set `APP_ENV=production` and `APP_DEBUG=false` in `.env`, delete `database/database.sqlite`, and run `composer run setup` again. It prints a new `admin` password, and no demo accounts or fake learners are created. Errors no longer show internal details.
- Back up by copying `database/database.sqlite` somewhere safe while the app is stopped, or with **Backup Now** on the Audit Log page.
- This simple server is fine for a few staff on a school network. For the whole school, or access from outside the school, use the Render setup below.

### Sign-in photo

Put a photo of the school at `public/images/login-background.jpg` (or `.webp` / `.png`) and it fills the sign-in page behind the card, softened and tinted green. A photo of the campus or gate works best. Avoid photos where learners' faces can be recognised, and use one the school owns or has permission to use. Without one, the plain green gradient is shown.

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

## Tests

- **Feature tests** (145) cover permissions, validation, duplicate prevention, edit conflicts, the review workflow, revision requests, documents, sections, school years, teacher pages, exports, reports, the importer and the audit log. CI runs them on both SQLite and PostgreSQL.
- **Browser tests** (42) in [`tests/e2e`](tests/e2e) drive Chromium against a real server and PostgreSQL. They include staff submitting the same learner at the same moment, ten simultaneous submissions, two people editing one record, two reviewers deciding on one learner at once, two people racing for the last seat in a section, an adviser's change approved by the registrar, SF1 and CSV downloads, the spreadsheet import, text size and phone layouts. See [`tests/e2e/README.md`](tests/e2e/README.md).

```bash
npm run test:e2e
```

## Diagrams

[`docs/diagrams`](docs/diagrams) has thirteen figures in standard notations: system architecture (three-tier), context diagram and level 1 data flow diagram (Gane & Sarson), UML activity and state machine diagrams, a crow's foot ERD, an old-versus-new comparison, a UML use case diagram, a UML deployment diagram, and UML sequence diagrams for encoding, review, import and sign-in. Each is a PNG and an SVG, and all are in one PDF. `docs/diagrams/generate.py` redraws them.

## Testing with real users

[`docs/usability-test.md`](docs/usability-test.md) is a kit for watching the registrar encode about 10 learners: what to ask, an observation sheet and follow-up questions. It also lists what a simulated encoding session already found and fixed.

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

Everything below can be done from a phone, in the Render website.

1. Sign in to [Render](https://render.com), choose **New > Blueprint**, and pick this repository, either from your connected GitHub or by pasting its public URL under **Public Git Repository**. With the public URL, pushes to `main` don't deploy by themselves: click **Manual Deploy**, or use the service's deploy hook. [`render.yaml`](render.yaml) creates the web service and a Postgres database in the Singapore region.
2. When asked for **`ADMIN_PASSWORD`**, type a temporary password for the first administrator, or leave it blank to have one generated and shown in the deploy logs. The app key and the site address are set automatically.
3. Deploy. The first build takes several minutes. Each deploy runs migrations and the seeders, which are safe to repeat, then starts Apache. See [`docker/start.sh`](docker/start.sh).
4. Open the address Render shows, such as `https://djihs-enrollment.onrender.com`. Sign in as `admin` and change the password when prompted.

The Blueprint uses Render's paid **Starter** web service and **Basic 256 MB** database, so the site stays awake and the database is kept and backed up. To try it at no cost instead, change both `plan:` values in `render.yaml` to `free`: the site then sleeps after about 15 minutes without visitors, and Render deletes a free database after 30 days. A workspace can have only one free database. Check Render's current pricing first.

### Demo data on the live site

`DEMO_DATA` is `"true"` in `render.yaml`, so the live site has the demo accounts from [`docs/demo-accounts.pdf`](docs/demo-accounts.pdf) (`demo.registrar`, `demo.adviser` and so on, all with the password `password`) and 120 fake learners. Anyone who knows those names can sign in, so before real learner data goes in:

1. Set `DEMO_DATA` to `false`, in `render.yaml` or under the web service's **Environment** in Render. The next deploy switches every demo account off.
2. Start real use on a fresh paid database, so the fake learners are not mixed in with real ones.

## Data privacy

The system stores personal data of minors, some of it sensitive, so it falls under the Data Privacy Act of 2012 (RA 10173). It includes:
- A privacy notice for parents at `/privacy`, which must be confirmed as given before each enrollment is submitted.
- A confidentiality agreement every staff member accepts at first sign-in.
- An audit log of every change, every view of a learner's record and every download of personal data.
- A printable **Learner Data Record** for requests under the right of access.
- Automatic sign-out after 30 idle minutes.

[`docs/data-privacy.md`](docs/data-privacy.md) explains what the system covers and what the school still has to do, starting with naming a Data Protection Officer and setting `PRIVACY_DPO_NAME`, `PRIVACY_DPO_EMAIL` and `PRIVACY_DPO_PHONE`.

## Project layout

```text
app/
  Enums/            Roles, abilities, grade levels, learner types, statuses
  Http/Controllers/ One controller per feature, not per role
  Models/           Eloquent models; Concerns/Auditable writes the audit log
  Services/Enrollment/  Enrolling learners safely and finding duplicates
  Services/Import/  Encoding workbook importer
  Services/Reports/ Monitoring and daily reports
database/           Migrations, factories, seeders
resources/js/       React pages, components and layouts
tests/              Feature tests, a fake-workbook builder, and browser tests in tests/e2e
docker/             Apache, PHP and start-up script for the Docker image
legacy/             The original HTML/PHP project, kept for reference
```
