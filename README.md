# Ministry of Youth and Sports, Republic of Liberia — Interview Panel Management & Scoring System

A modular, responsive PHP 8.2+ & MySQL 8+ recruitment platform designed for the **Ministry of Youth and Sports, Republic of Liberia**, supporting **both Physical (In-Person) and Virtual (Online WebRTC)** recruitment interviews under one unified scoring, confidentiality, and reporting engine.

## Key Capabilities

- **Dual Interview Modes (First-Class Support for Both)**
  - **Physical (In-Person) Interviews:** Dedicated Physical Interview Dashboard, venue & room management, candidate arrival tracking, one-click *Mark Present & Begin Interview*, official Candidate & Panelist Attendance Registers (`Present`, `Absent`, `Late`, `Rescheduled`), and mobile/tablet/laptop panelist scoring with **zero** camera or video requirements.
  - **Virtual (Online) Interviews:** Dedicated Virtual Interview Dashboard and WebRTC Meeting Room powered by **Jitsi Meet External API** or **LiveKit** (with built-in server-side HS256 JWT token generation), waiting room admission controls, secure token-based Candidate Join Portal, live timer, device check, screen sharing, and **recording disabled by default** (requires explicit Administrator authorization + candidate consent).
- **Unified Administrator Dashboard:** Filter across Physical/Virtual modes, Ministry departments, job positions, interview dates, and statuses with real-time database statistics and Chart.js analytics.
- **Blind Scoring & Confidentiality:** Backend-enforced isolation ensures panelists see only their own evaluations until the Administrator officially releases session results.
- **Configurable Scoring Criteria:** Pre-loaded with the 100-mark Ministry standard (Educational Qualifications 15, Technical Knowledge 25, Professional Experience 20, Communication Skills 15, Problem-Solving Ability 15, Leadership & Teamwork 10) and fully customizable per position.
- **Unified Results & Official Reports:** Both interview modes share the exact same `ScoringEngine` and export all 7 official reports (Candidate Assessment, Individual Panelist Assessment, Final Candidate Ranking, Candidate Attendance Register, Panelist Attendance Register, Session Summary, Final Recruitment Report) in **PDF** (Dompdf) and **Excel** (PhpSpreadsheet) with Republic of Liberia / Ministry of Youth and Sports branding and authorized signature blocks.

## Directory Structure

```text
├── config/          # App, branding, WebRTC, and PDO database configuration
├── database/        # MySQL 8+ schema.sql, seed.sql, and SQLite test mirror
├── docs/            # XAMPP & Hostinger production deployment guide
├── public/          # Web root (admin/, physical/, virtual/, panelist/, results/, api/, assets/)
├── src/
│   ├── Core/        # Env, Database (PDO), Auth/RBAC/CSRF, Response, AuditLogger
│   └── Services/    # ScoringEngine, AttendanceService, VideoConferenceService, ReportExportService
├── templates/       # Shared Ministry Bootstrap 5 layout
└── tests/           # Automated end-to-end verification suite
```

## Quick Start

1. Copy `.env.example` to `.env` and set your MySQL credentials.
2. Import `database/schema.sql` and `database/seed.sql` into MySQL (`mys_interview_panel`).
3. Run `composer install` to install `phpoffice/phpspreadsheet` and `dompdf/dompdf`.
4. Open `public/login.php` and sign in with `admin@mys.gov.lr` / `Admin@2026!` (change password immediately).

For full **XAMPP** and **Hostinger** deployment instructions, see [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

## Running Automated Tests

```bash
php tests/run_tests.php
```
