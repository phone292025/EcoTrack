# EcoTrack

![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?style=flat-square&logo=php&logoColor=white)
![MariaDB](https://img.shields.io/badge/MariaDB-10.4-003545?style=flat-square&logo=mariadb&logoColor=white)
![CSS](https://img.shields.io/badge/Styling-Custom%20CSS-2d936c?style=flat-square)
![Status](https://img.shields.io/badge/Status-Academic%20Project-blue?style=flat-square)
[![CI](https://github.com/phone292025/EcoTrack/actions/workflows/ci.yml/badge.svg)](https://github.com/phone292025/EcoTrack/actions/workflows/ci.yml)

EcoTrack is a web-based sustainability tracking system that helps users record eco-friendly activities, earn points, join challenges, redeem rewards, and monitor their environmental impact. The system includes separate participant, moderator, and administrator modules with role-based access control.

## Table of Contents

- [Project Overview](#project-overview)
- [Key Features](#key-features)
- [Technologies Used](#technologies-used)
- [Installation](#installation)
- [Usage](#usage)
- [Default Login Accounts](#default-login-accounts)
- [Security Notes](#security-notes)
- [Running the Tests](#running-the-tests)
- [Project Structure](#project-structure)
- [Roadmap](#roadmap)
- [Contribution Guidelines](#contribution-guidelines)
- [Contact](#contact)
- [License](#license)

## Project Overview

EcoTrack is designed to encourage sustainable habits by turning eco actions into measurable progress. Participants can submit activity evidence, moderators can review submissions, and administrators can manage users, challenges, rewards, badges, announcements, and platform analytics.

The project solves the problem of tracking sustainability participation in a structured way by combining activity logging, moderation, gamified points, challenge participation, badge achievements, and reward redemption in one platform.

## Key Features

- User registration and login with role-based dashboards
- Participant activity logging with optional evidence upload
- Moderator review workflow for approving, rejecting, or flagging submissions
- Admin dashboard with user, reward, badge, challenge, announcement, and eco-tip management
- Challenge participation and completion tracking
- Points dashboard with transaction history
- Green Shop reward redemption with stock and point deduction, and an admin
  queue to mark redemptions handed over or cancel them with a refund
- Badge gallery and profile impact summary, with automatic badge rules and
  manual awards
- Responsive custom CSS layout for desktop and mobile
- Database schema and seed data included in `database/ecotrack.sql`

## Technologies Used

- PHP 8.2
- MariaDB / MySQL
- XAMPP
- HTML5
- Custom CSS
- JavaScript
- Chart.js

## Installation

### 1. Clone The Repository

```powershell
git clone https://github.com/phone292025/EcoTrack.git
cd EcoTrack
```

### 2. Move The Project Into XAMPP

If you want to run it using XAMPP, place the project folder inside:

```text
C:\xampp\htdocs\ecotrack
```

### 3. Start XAMPP

Start both services:

```text
Apache
MySQL
```

### 4. Import The Database

If MySQL root has no password:

```powershell
C:\xampp\mysql\bin\mysql -u root < .\database\ecotrack.sql
```

If MySQL root has a password:

```powershell
C:\xampp\mysql\bin\mysql -u root -p < .\database\ecotrack.sql
```

### 5. Configure Local Database Settings

The project includes an example local config file:

```powershell
Copy-Item .\database\db.local.example.php .\database\db.local.php
```

Then edit it if your database name, username, password, or host is different, or
to turn off the demo account panel on the login page:

```powershell
notepad .\database\db.local.php
```

By default, the project uses the database name:

```text
ecotrack
```

### 6. Run The Migration

This creates any missing table, brings an older database up to the current
schema, and reports each step. It is safe to run more than once. It also
works on an empty database, if you would rather skip step 4.

```powershell
php .\scripts\migrate.php
```

### 7. Check The Setup

```powershell
php .\scripts\check_setup.php
```

If everything is correct, the script will confirm that EcoTrack is ready. It
also checks every account's password hash, and exits with an error if any of
them could never be used to log in.

> **Note:** everything in `scripts/` is command line only. Each file refuses to
> run over HTTP, and `scripts/.htaccess` blocks the folder from the web as well.

## Usage

Open the project in your browser:

```text
http://localhost/ecotrack/
```

If you run the project with PHP's built-in server instead of XAMPP, start it
from the project folder with the router script:

```powershell
php -S localhost:8000 router.php
```

Then open:

```text
http://localhost:8000/
```

`router.php` gives the built-in server the same rules the `.htaccess` files give
Apache: the database, scripts, tests and other internal files are never served.

## Default Login Accounts

### Admin

```text
Username: admin
Email: admin@ecotrack.com
Password: EcoAdmin2026
```

### Moderator

```text
Username: moderator
Email: mod@ecotrack.com
Password: EcoMod2026
```

Participants can create an account using the registration page.

These details are shown on the login page only while `DEMO_MODE` is on. Set it to
`false` in `database/db.local.php` for any deployment that is not a marked demo:

```php
define('DEMO_MODE', false);
```

To confirm the demo accounts work, or to reset their passwords:

```powershell
php .\scripts\check_login_users.php
php .\scripts\apply_admin_mod_passwords.php
```

## Security Notes

- All queries use prepared statements with bound parameters.
- Every form, including logout, carries a CSRF token checked with `hash_equals()`.
- Pages send a Content Security Policy that only runs scripts from the site
  itself. No page uses inline `<script>` or `onclick` handlers.
- Pages cannot be framed by other sites (`frame-ancestors 'none'`,
  `X-Frame-Options: DENY`).
- Session cookies are `HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS, and
  strict mode refuses session ids the server never issued.
- Every request re-checks the signed-in user against the database. Deleting an
  account, changing its role, or changing its password takes effect on the
  user's next click. Other sessions are logged out after a password change, and
  any session idle for two hours ends.
- Five failed logins on one account lock it for 15 minutes, whether the user
  typed the username or the email. One IP address gets 20 failures across all
  accounts. A successful login only clears that account's failures.
- Uploads are checked with `finfo` and `getimagesize()`, capped at 5 MB, and
  stored under random names in folders that never run PHP. Evidence photos are
  served only through `evidence.php`, to the participant who sent them and to
  moderators and admins.
- Every POST redirects afterwards, so refreshing cannot repeat a submission.
- `points_transactions` is the authoritative ledger. `users.points` is written
  in the same transaction and always equals `SUM(delta)`. Each row records its
  `kind`, so bonuses and refunds are counted correctly.
- Unexpected errors show a plain error page and are written to the server log.
  Set `APP_DEBUG` to `true` in `database/db.local.php` to see details while
  developing.

## Running the Tests

The test suite needs no extra packages. It builds a throwaway database named
`ecotrack_test` from `database/ecotrack.sql`, empties it before every test, and
drives the real pages through PHP's built-in server:

```powershell
php .\tests\run.php
php .\tests\run.php Goal     # only tests whose name contains "Goal"
```

The database account needs permission to create and drop `ecotrack_test`. The
suite refuses to run against any database whose name does not end in `_test`.

Every push runs the same checks on GitHub Actions on PHP 8.1 to 8.4
(`.github/workflows/ci.yml`): PHP and JavaScript syntax, importing the schema,
the migration, both setup checks, and the test suite.

## Project Structure

```text
index.php, login.php, register.php, logout.php   public entry pages
participant/  moderator/  admin/                 pages for each role
ajax/checkin.php                                 daily check-in endpoint
evidence.php                                     access-checked evidence photos
includes/bootstrap.php                           the one include every page starts with
includes/rules.php                               points values, limits, demo accounts
includes/ledger.php                              points, streaks, check-in, goals
includes/badges.php  challenges.php  activities.php  rewards.php  reports.php
includes/auth.php  http.php                      sessions, CSRF, throttling, headers
layout/                                          shared header, footer, error pages
database/ecotrack.sql                            schema and seed data (single source of truth)
scripts/                                         command-line setup and maintenance
tests/                                           test runner and tests
```

## Roadmap

- Add more detailed analytics filters for administrators
- Add export options for user and activity reports
- Add email notification support for moderation results
- Add more badge automation rules

## Contribution Guidelines

This is an academic group project. If contributing:

1. Create a new branch for your changes.
2. Keep file names and folder structure consistent.
3. Run `php tests/run.php` and check the affected pages through XAMPP before committing.
4. Do not commit local database credentials or uploaded evidence files.
5. Submit changes with a clear commit message.

Third-party library:

- Chart.js for dashboard charts and visual data summaries

## Contact

For questions about this project, open an issue on the
[GitHub repository](https://github.com/phone292025/EcoTrack/issues).

## License

This project is licensed under the [MIT License](LICENSE).
