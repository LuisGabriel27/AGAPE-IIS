# Academy Information System â€” Setup Checklist

## Prerequisites
- **XAMPP** installed with Apache + MySQL running
- **PHP 7.4+** (PHP 8.x recommended)
- **Composer** installed globally â€” [https://getcomposer.org](https://getcomposer.org)

---

## Step 1: Place Project Files
Copy the entire `softeng` folder into your XAMPP `htdocs` directory:
```
C:\xampp\htdocs\softeng\
```
Or create a symlink/alias so `http://localhost/softeng` resolves to your project path.

---

## Step 2: Install Composer Dependencies
Open a terminal in the project root directory and run:
```bash
cd C:\xampp\htdocs\softeng
composer install
```
This installs `google/apiclient` and creates the `vendor/` directory with the autoloader.

> **Verify**: `vendor/autoload.php` should exist after this step.

---

## Step 3: Import Database
1. Open **phpMyAdmin** at `http://localhost/phpmyadmin`
2. Click **Import** tab
3. Select the file: `database/schema.sql`
4. Click **Go** to execute

This creates the `academy_db` database with all 16 tables and a default admin account:
- **Email**: `admin@academy.edu`
- **Password**: `Admin@1234`

> âš ï¸ **Change the admin password on first login!**

---

## Step 4: Configure Application Settings
Edit `config/config.php` and update:

```php
define('APP_URL', 'http://localhost/softeng');   // adjust to your setup
define('DB_HOST', 'localhost');
define('DB_NAME', 'academy_db');
define('DB_USER', 'root');
define('DB_PASS', '');    // default XAMPP password
```

---

## Step 5: Google Cloud Console Setup (OAuth 2.0)

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project (or select existing)
3. Navigate to **APIs & Services â†’ Credentials**
4. Click **Create Credentials â†’ OAuth client ID**
5. Configure the **consent screen**:
   - App name: `Academy Information System`
   - User support email: your email
   - Authorized domains: leave blank for localhost
6. Create **OAuth 2.0 Client ID**:
   - Application type: **Web application**
   - Name: `Academy AIS`
   - **Authorized redirect URIs**: `http://localhost/softeng/auth/oauth-callback.php`
7. Copy the **Client ID** and **Client Secret**
8. Update `config/config.php`:

```php
define('GOOGLE_CLIENT_ID',     'your-client-id-here.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'your-client-secret-here');
define('GOOGLE_REDIRECT_URI',  APP_URL . '/auth/oauth-callback.php');
```

---

## Step 6: PHP Configuration (php.ini)

Locate your XAMPP `php.ini` (usually `C:\xampp\php\php.ini`) and verify/set:

```ini
display_errors = Off
log_errors = On
error_log = "C:\xampp\htdocs\softeng\logs\error.log"

session.cookie_httponly = 1
session.cookie_samesite = "Strict"
session.use_strict_mode = 1

extension=pdo_mysql
```

Restart Apache after changes.

---

## Step 7: Create Logs Directory
Ensure the logs directory exists:
```bash
mkdir C:\xampp\htdocs\softeng\logs
```

---

## Step 8: Test the Application

1. **Navigate to**: `http://localhost/softeng/auth/select-role.php`
2. **Choose Administrator**, then log in as admin: `admin@academy.edu` / `Admin@1234`
3. **Verify admin dashboard** loads with KPI cards
4. **Create a test teacher** via Admin â†’ Teachers
5. **Logout and register** a guardian account via Sign Up
6. **Test enrollment** workflow through guardian requirement uploads, Enrollment Clerk review, Cashier payment, and Registrar submission
7. **Test Google Login** (if credentials configured):
   - Click "Sign in with Google" on login page
   - Complete Google consent screen
   - Verify redirect back to dashboard

---

## Folder Structure

```
AGAPE-IIS/
|-- admin/
|   |-- login.php
|   |-- admin-dashboard.php
|   |-- admin-students.php
|   |-- admin-teachers.php
|   |-- admin-users.php
|   |-- admin-enrollments.php
|   |-- admin-subjects.php
|   |-- admin-sections.php
|   |-- admin-grades.php
|   |-- admin-schedule.php
|   |-- admin-calendar.php
|   |-- admin-attendance.php
|   `-- admin-payments.php
|-- teacher/
|   |-- login.php
|   |-- teacher-dashboard.php
|   |-- teacher-grades.php
|   `-- teacher-schedule.php
|-- guardian/
|   |-- login.php
|   |-- complete-profile.php
|   |-- dashboard.php
|   |-- enrollment/
|   |   |-- index.php
|   |   |-- requirements.php
|   |   |-- payment.php
|   |   `-- certificate.php
|   |-- grades.php
|   |-- payments.php
|   |-- profile-view.php
|   |-- profile-edit.php
|   `-- schedule.php
|-- auth/
|   |-- select-role.php
|   |-- login.php
|   |-- logout.php
|   `-- oauth-callback.php
|-- includes/
|   |-- session-check.php
|   |-- helpers.php
|   |-- csrf.php
|   |-- db.php
|   |-- header.php
|   |-- footer.php
|   `-- calendar-widget.php
|-- assets/
|   |-- css/style.css
|   `-- js/face-api.min.js
|-- config/
|-- database/
|-- models/
|-- vendor/
|-- composer.json
|-- composer.lock
|-- index.php
`-- SETUP.md
```
---

## Default Accounts

| Role     | Email               | Password     |
|----------|---------------------|--------------|
| Admin    | admin@academy.edu   | Admin@1234   |

Teacher and guardian accounts are created through the admin panel or self-registration.

---

## Security Notes

- All forms use CSRF tokens
- Passwords are hashed with bcrypt
- SQL injection prevented via PDO prepared statements
- XSS prevented via `htmlspecialchars()` on all output
- Brute-force protection: 5 attempts â†’ 15-minute lockout
- Sessions use HttpOnly, SameSite=Strict cookies
- Sensitive config excluded from `.gitignore`

