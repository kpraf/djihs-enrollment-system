# DJIHS Enrollment System

## Short Description
Role-based school enrollment web system for Don Jose Integrated High School. It helps school staff handle enrollment, student records, section assignment, document tracking, and reporting from one internal portal.

## What It Is
This project is a browser-based enrollment management system with separate dashboards for six school roles: Admin, Adviser, Key Teacher, ICT Coordinator, Registrar, and Subject Teacher. Based on the repo structure and login flow, it is designed for internal staff use rather than public student self-registration.

## Who It's For
- Primary users: school staff responsible for enrollment processing, student record management, sectioning, academic-year setup, and account administration
- Main persona: registrar or ICT coordinator who needs to review applications, manage student data, and coordinate school-year operations across multiple staff roles

## Visuals
- UI screenshots or animated GIFs: **Not found in repo**
- Existing branding assets in repo:
  - `assets/images/DJIHS-Logo.png`
  - `assets/images/djihs-logo-small.png`

## Tech Stack
- PHP
- MySQL via PDO
- HTML
- Vanilla JavaScript
- Tailwind CSS via CDN
- PhpSpreadsheet (`phpoffice/phpspreadsheet`) for Excel import and export
- XAMPP-style local environment (`localhost`, MySQL `root`, empty password in database config)

## Key Features
- Role-based login with automatic dashboard routing based on the user account role
- Enrollment submission, pending review, approval, and rejection workflows
- Student management with search, detail views, status tracking, and dashboard statistics
- Academic year, section, and strand management for school organization
- User account management with activation, deactivation, reset, and password change support
- Bulk student import from CSV or Excel using provided templates
- Document checklist tracking, audit logging, analytics, metrics export, database backup, and SF1 export

## Roles Found In Repo
- Admin
- Adviser
- Key Teacher
- ICT Coordinator
- Registrar
- Subject Teacher

## Repo Structure
```text
admin/              Admin pages
adviser/            Adviser pages
ict-coordinator/    ICT Coordinator pages
key-teacher/        Key Teacher pages
registrar/          Registrar pages
subject-teacher/    Subject Teacher pages
js/                 Frontend page handlers and auth utilities
backend/api/        PHP API endpoints
backend/config/     Database connection config
backend/helpers/    Shared backend helpers such as audit logging
backend/templates/  Excel templates for import/export
partials/           Shared UI fragments
assets/             Logos and favicons
```

## How It Works
- Frontend pages are split by role into folders such as `admin/`, `adviser/`, `registrar/`, and `ict-coordinator/`.
- The login page posts credentials to `backend/api/login.php`.
- The backend checks the `user` table, verifies the password hash, reads the stored role, and returns the authenticated user record.
- Frontend scripts save login state in `localStorage`, and shared auth logic in `js/auth.js` redirects users to the correct dashboard.
- Page-specific JavaScript files call PHP endpoints in `backend/api/` using `fetch(...)`.
- Backend APIs connect to the MySQL database `djihs_enrollment_v2` through `backend/config/database.php`.
- The APIs read and write school data across tables referenced directly in the repo, including `user`, `student`, `enrollment`, `parentguardian`, `documentsubmission`, `sectionassignment`, `academicyear`, and `auditlog`.
- Excel import/export flows use templates in `backend/templates/` and the PhpSpreadsheet library.

## Evidence-Based Architecture Overview
- Client layer:
  - Static HTML pages for landing, login, and role dashboards
  - JavaScript handlers in `js/` for auth, enrollment forms, review flows, bulk import, document submission, metrics, and user management
- Application layer:
  - PHP API endpoints in `backend/api/`
  - Authentication logic in `backend/includes/auth.php`
  - Audit logging helper in `backend/helpers/audit_logger.php`
- Data layer:
  - MySQL database configured in `backend/config/database.php`
  - Database name in repo: `djihs_enrollment_v2`
- Data flow:
  - User logs in -> frontend sends JSON to PHP API -> backend verifies credentials against MySQL -> role-specific dashboard loads -> dashboard JS calls more APIs -> APIs read/write enrollment and student records -> selected flows generate exports or audit entries

## Installation & Setup
### Prerequisites
- XAMPP or an equivalent PHP + MySQL local stack
- PHP with Composer available
- MySQL running locally

### Minimal Setup
1. Put the project inside your XAMPP web root.
   ```powershell
   cd C:\xampp\htdocs
   ```

2. Make sure the project folder is available at:
   ```text
   C:\xampp\htdocs\djihs-enrollment-system
   ```

3. Start Apache and MySQL from XAMPP.

4. Create the database used by the project:
   ```sql
   CREATE DATABASE djihs_enrollment_v2;
   ```

5. Check the database connection settings in:
   [database.php](/C:/xampp/htdocs/djihs-enrollment-system/backend/config/database.php)

6. Install Composer dependencies if needed:
   ```powershell
   composer install --working-dir backend
   ```

7. Open the app in your browser:
   - Landing page: [http://localhost/djihs-enrollment-system/index.html](http://localhost/djihs-enrollment-system/index.html)
   - Login page: [http://localhost/djihs-enrollment-system/login.html](http://localhost/djihs-enrollment-system/login.html)

## Usage
### Main Entry Points
- `index.html` for the portal landing page
- `login.html` for staff login

### Typical Flow
1. Open the login page.
2. Sign in with a staff account.
3. The system routes the user to the correct dashboard based on the stored role.
4. From the dashboard, users can manage enrollments, students, documents, sections, reports, or accounts depending on permissions.

### Credentials
- Default usernames/passwords: **Not found in repo**
- Seed users: **Not found in repo**

### Database Schema
- SQL schema file or migration files: **Not found in repo**
- Seed/import SQL dump for first-time setup: **Not found in repo**

## Feature Notes
### Enrollment Management
- Submit new enrollments
- View pending enrollments
- Approve or reject applications
- Prevent duplicate enrollment by LRN and academic year
- Support multiple enrollment types such as regular, transferee, repeater, ALS, and balik-aral

### Student Management
- List and search students
- View student details and latest enrollment info
- Track section assignments
- Show dashboard statistics and counts by grade level

### Operations and Reporting
- Track required documents per enrollment
- Create audit log entries for key changes
- Export SF1 using an Excel template
- Export analytics and metrics
- Create MySQL backups from the backend

## Reflection / Challenges
- This project was built for a Software Engineer course in Mapua Malayan Colleges Laguna.
- Motivation: create one system that centralizes enrollment-related tasks for school staff.
- Challenge: the project experienced scope creep, with many additional features being added over time.
- Lesson learned: define scope earlier, protect core requirements, and plan role-based features with tighter boundaries so the team can deliver a more stable system.

## Known Gaps In Documentation
- Screenshots or demo GIFs: **Not found in repo**
- Public deployment URL: **Not found in repo**
- Default user credentials: **Not found in repo**
- SQL schema/setup dump: **Not found in repo**
- Automated tests or test instructions: **Not found in repo**
