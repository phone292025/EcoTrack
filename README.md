# EcoTrack

![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?style=flat-square&logo=php&logoColor=white)
![MariaDB](https://img.shields.io/badge/MariaDB-10.4-003545?style=flat-square&logo=mariadb&logoColor=white)
![CSS](https://img.shields.io/badge/Styling-Custom%20CSS-2d936c?style=flat-square)
![Status](https://img.shields.io/badge/Status-Academic%20Project-blue?style=flat-square)

EcoTrack is a web-based sustainability tracking system that helps users record eco-friendly activities, earn points, join challenges, redeem rewards, and monitor their environmental impact. The system includes separate participant, moderator, and administrator modules with role-based access control.

## Table of Contents

- [Project Overview](#project-overview)
- [Key Features](#key-features)
- [Technologies Used](#technologies-used)
- [Installation](#installation)
- [Usage](#usage)
- [Default Login Accounts](#default-login-accounts)
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
- Green Shop reward redemption with stock and point deduction
- Badge gallery and profile impact summary
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

This creates anything missing and brings an older database up to the current
schema. It is safe to run more than once.

```powershell
php .\scripts\migrate.php
```

### 7. Check The Setup

```powershell
php .\scripts\check_setup.php
```

If everything is correct, the script will confirm that EcoTrack is ready.

> **Note:** everything in `scripts/` is command line only. Each file refuses to
> run over HTTP, and `scripts/.htaccess` blocks the folder from the web as well.

## Usage

Open the project in your browser:

```text
http://localhost/ecotrack/
```

If you run the project with PHP's built-in server instead of XAMPP:

```powershell
php -S localhost:8000
```

Then open:

```text
http://localhost:8000/
```

Do not use:

```text
http://localhost:8000/index.php/
```

## Default Login Accounts

### Admin

```text
Username: admin
Email: admin@ecotrack.com
Password: admin1234
```

### Moderator

```text
Username: moderator
Email: mod@ecotrack.com
Password: mod123
```

Participants can create an account using the registration page.

These details are shown on the login page only while `DEMO_MODE` is on. Set it to
`false` in `database/db.local.php` for any deployment that is not a marked demo:

```php
define('DEMO_MODE', false);
```

## Security Notes

- All queries use prepared statements with bound parameters.
- Every form carries a CSRF token, validated with `hash_equals()`.
- Session cookies are `HttpOnly` and `SameSite=Lax`, and `Secure` over HTTPS.
- Five failed logins from one account or IP trigger a 15 minute cool-off.
- Uploads are checked with `finfo`, capped at 5 MB, stored under random
  filenames, and the upload folders block PHP execution.
- Every POST redirects afterwards, so refreshing cannot repeat a submission.
- `points_transactions` is the authoritative ledger; `users.points` is written
  in the same transaction and always equals `SUM(delta)`.

## Roadmap

- Add more detailed analytics filters for administrators
- Add export options for user and activity reports
- Add email notification support for moderation results
- Improve reward inventory tracking
- Add more badge automation rules

## Contribution Guidelines

This is an academic group project. If contributing:

1. Create a new branch for your changes.
2. Keep file names and folder structure consistent.
3. Test affected pages through XAMPP before committing.
4. Do not commit local database credentials or uploaded evidence files.
5. Submit changes with a clear commit message.

Third-party library:

- Chart.js for dashboard charts and visual data summaries

## Contact

For questions about this project, contact:

## License

This project is licensed under the [MIT License](LICENSE).
