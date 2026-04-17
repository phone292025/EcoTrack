# EcoTrack Quick Start

## 1. Open VS Code Terminal

Open this folder:

```text
C:\Users\phone\Desktop\ecotrack
```

Then open a terminal in that folder.

## 2. Import The Database

If XAMPP is installed in the default place and MySQL has no password:

```powershell
C:\xampp\mysql\bin\mysql -u root < .\ecotrack.sql
```

If root has a password:

```powershell
C:\xampp\mysql\bin\mysql -u root -p < .\ecotrack.sql
```

## 3. If Your MySQL Settings Are Different

Copy the local config example:

```powershell
Copy-Item .\includes\db.local.example.php .\includes\db.local.php
```

Then edit it:

```powershell
notepad .\includes\db.local.php
```

## 4. Check The Setup

Run:

```powershell
php .\scripts\check_setup.php
```

If everything is correct, it will say EcoTrack is ready.

## 5. Start The Website

Run:

```powershell
php -S localhost:8000
```

## 6. Open The Website

Open:

```text
http://localhost:8000/
```

Do not use:

```text
http://localhost:8000/index.php/
```

## 7. Login Accounts

Admin:

```text
Username: admin
Email: admin@ecotrack.com
Password: admin1234
```

Moderator:

```text
Username: moderator
Email: mod@ecotrack.com
Password: mod123
```

## 8. Useful Check

To recheck the default accounts:

```powershell
php .\scripts\check_login_users.php
```

## 9. Stop The Server

Press:

```text
Ctrl + C
```
cd C:\Users\phone\Desktop\ecotrack
php -S localhost:8000