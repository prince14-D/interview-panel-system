# Deployment & Setup Guide — XAMPP (Local) & Hostinger (Production)

**Ministry of Youth and Sports, Republic of Liberia**
**Interview Panel Management and Scoring System**

---

## Part 1: Local Setup on XAMPP (Windows / macOS / Linux)

### Prerequisites
- **XAMPP** with **PHP 8.2+** and **MySQL 8+ / MariaDB 10.6+**
- **Composer** (optional for development, recommended for direct `Dompdf` PDF and `PhpSpreadsheet` `.xlsx` generation)

### Step 1: Copy Project into XAMPP `htdocs`
1. Copy the `Interview-Panel` folder into `C:\xampp\htdocs\Interview-Panel` (or configure an Apache VirtualHost pointing to `Interview-Panel/public`).
2. Start **Apache** and **MySQL** from the **XAMPP Control Panel**.

### Step 2: Create the Database and Import Schema
1. Open phpMyAdmin at `http://localhost/phpmyadmin`.
2. Create a new database named **`mys_interview_panel`** with collation `utf8mb4_unicode_ci`.
3. Import `database/schema.sql` into `mys_interview_panel`.
4. Import `database/seed.sql` into `mys_interview_panel` (seeds Ministry departments, physical venues, and the initial Administrator account).

Or via XAMPP CLI:
```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS mys_interview_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
C:\xampp\mysql\bin\mysql.exe -u root mys_interview_panel < database\schema.sql
C:\xampp\mysql\bin\mysql.exe -u root mys_interview_panel < database\seed.sql
```

### Step 3: Configure Environment (`.env`)
1. Copy `.env.example` to `.env` in the project root.
2. Update the database credentials and app URL:
```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost/Interview-Panel/public
APP_TIMEZONE=Africa/Monrovia

DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mys_interview_panel
DB_USERNAME=root
DB_PASSWORD=
```

### Step 4: Install PHP Libraries (Dompdf & PhpSpreadsheet)
Run in the project root:
```powershell
composer install --no-dev --optimize-autoloader
```
> Note: If Composer is not yet installed, the application still runs cleanly using its built-in PSR-4 autoloader, print-PDF official report fallback, and Excel SpreadsheetML fallback. Running `composer install` upgrades exports to native binary `.pdf` (Dompdf) and `.xlsx` (PhpSpreadsheet).

### Step 5: Open the Application
- Navigate to: `http://localhost/Interview-Panel/public/login.php`
- **Default Administrator Account** (from `database/seed.sql`):
  - **Email:** `admin@mys.gov.lr`
  - **Password:** `Admin@2026!`
- **Immediately create your own administrator/panelist accounts and change the default password before live recruitment.**

---

## Part 2: Production Deployment on Hostinger (hPanel)

### Step 1: Verify PHP Version & Extensions in Hostinger hPanel
1. Log in to **Hostinger hPanel** -> **Advanced** -> **PHP Configuration**.
2. Select **PHP 8.2** or **PHP 8.3**.
3. Ensure these extensions are enabled: `pdo_mysql`, `mbstring`, `gd`, `zip`, `xml`, `openssl`, `curl`.

### Step 2: Create MySQL Database in hPanel
1. Go to **Databases** -> **Management**.
2. Create a new MySQL database, user, and strong password (e.g., `u123456_myspanel`).
3. Open **phpMyAdmin** from hPanel and import:
   - `database/schema.sql`
   - `database/seed.sql`

### Step 3: Deploy Application Files
- Upload the repository to your domain directory (e.g., `domains/interviews.mys.gov.lr/public_html/` or via **Git Deployment** in hPanel).
- Ensure `.htaccess` is uploaded in both the project root and `public/` so `config/`, `src/`, `database/`, `vendor/`, and `.env` are strictly blocked from direct HTTP access.
- If using SSH on Hostinger, run:
```bash
cd ~/domains/interviews.mys.gov.lr/public_html
composer install --no-dev --optimize-autoloader
```

### Step 4: Configure Production `.env`
Create `.env` in the project root with production settings:
```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://interviews.mys.gov.lr/public
APP_TIMEZONE=Africa/Monrovia
APP_SECRET=REPLACE_WITH_64_CHAR_RANDOM_HEX_SECRET

DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=u123456_myspanel
DB_USERNAME=u123456_mysuser
DB_PASSWORD=STRONG_DATABASE_PASSWORD

# Virtual Interviews (Jitsi or LiveKit)
VIDEO_DEFAULT_PROVIDER=jitsi
JITSI_DOMAIN=meet.jit.si
# For LiveKit Cloud or Self-Hosted LiveKit:
# VIDEO_DEFAULT_PROVIDER=livekit
# LIVEKIT_WS_URL=wss://your-project.livekit.cloud
# LIVEKIT_API_KEY=your_api_key
# LIVEKIT_API_SECRET=your_api_secret
```

### Step 5: Enable SSL (HTTPS) — Required for Virtual Interviews
1. In Hostinger hPanel -> **Security** -> **SSL**, install and force **HTTPS** on your domain.
2. Modern browsers strictly require **HTTPS** for WebRTC camera, microphone, and screen-sharing access during virtual interviews.
3. Physical interviews work seamlessly over HTTPS on laptops, tablets, and smartphones with zero camera/mic requirements.

### Step 6: Production Security Checklist
- [ ] Set `APP_DEBUG=false` in `.env`
- [ ] Replace `APP_SECRET` with a freshly generated 64-character secret
- [ ] Change the seeded `admin@mys.gov.lr` password immediately
- [ ] Verify `https://your-domain/.env` returns **403 Forbidden**
- [ ] Schedule automated daily MySQL backups in Hostinger hPanel
