# ElderShield — Setup Guide

ElderShield is a PHP web application that helps protect elderly users from scams. Elders submit suspicious messages or screenshots, a local AI model (Ollama) analyzes them, and caregivers are automatically notified of high-risk reports.

---

## Prerequisites

- **MAMP** (or XAMPP) running Apache + PHP 8.1+ + MySQL
- **Ollama** running locally with a vision-capable model (default: `qwen2.5vl:7b`)
- No external API keys required — AI runs fully offline via Ollama

---

## 1. Place Files

Copy the `src/` folder into your MAMP `htdocs` under an `eldershield/` directory:

```
/Applications/MAMP/htdocs/eldershield/src/
```

---

## 2. Create the Database

1. Open **phpMyAdmin**: `http://localhost:8888/phpMyAdmin`
2. Create a new database named `eldershield` (utf8mb4, unicode_ci)
3. Import `database/eldershield.sql`

---

## 3. Configure the App

**`config/config.php`** — update these values before running:

```php
define('APP_URL',      'http://localhost:8888/eldershield/src');
define('APP_TIMEZONE', 'America/Denver');   // ← your local timezone
define('OLLAMA_URL',   'http://localhost:11434');
define('OLLAMA_MODEL', 'qwen2.5vl:7b');    // ← any Ollama vision model
```

Full timezone list: https://www.php.net/manual/en/timezones.php

**`config/db.php`** — defaults match MAMP:

```php
define('DB_PORT', '8889');   // MAMP MySQL port — change to 3306 for XAMPP
define('DB_USER', 'root');
define('DB_PASS', 'root');
```

---

## 4. Install Ollama & Pull a Model

1. Download and install Ollama from https://ollama.com
2. Pull the default model:
   ```bash
   ollama pull qwen2.5vl:7b
   ```
3. Ollama runs automatically in the background on port `11434`. Verify it is running:
   ```bash
   ollama list
   ```

Any vision-capable Ollama model works (e.g. `llava`, `llava-phi3`, `moondream`). Update `OLLAMA_MODEL` in `config/config.php` to match.

---

## 5. Seed Demo Data

Visit in your browser:

```
http://localhost:8888/eldershield/src/database/seed.php
```

This creates **19 demo accounts** and **27 sample incidents** spread across the past 30 days so the analytics dashboards are populated immediately.

| Email | Role | Plan | Password |
|---|---|---|---|
| admin@eldershield.com | Admin | Premium | password123 |
| bsmith@eldershield.com | Admin | Premium | mysecret |
| dorothy@example.com | Elder | Free | password123 |
| pjones@example.com | Elder | Free | acrobat |
| sarah@example.com | Caregiver | Free | password123 |
| mjohnson@example.com | Caregiver | Premium | password123 |
| arivera@example.com | Caregiver | Premium | password123 |
| tbradley@example.com | Caregiver | Free | password123 |
| lpark@example.com | Caregiver | Free | password123 |
| josei@example.com | Caregiver | Free | password123 |
| hturner@example.com | Elder | Free | password123 |
| emartinez@example.com | Elder | Free | password123 |
| wnguyen@example.com | Elder | Free | password123 |
| bkowalski@example.com | Elder | Free | password123 |
| rchen@example.com | Elder | Free | password123 |
| gokafor@example.com | Elder | Free | password123 |
| fpatel@example.com | Elder | Free | password123 |
| mhaynes@example.com | Elder | Free | password123 |
| cbloom@example.com | Elder | Free | password123 |

**⚠️ Delete `database/seed.php` after running it.**

---

## 6. Set Upload Permissions

```bash
chmod 755 /Applications/MAMP/htdocs/eldershield/src/uploads/
```

---

## 7. Test It

1. `http://localhost:8888/eldershield/src/pages/login.php`
2. Log in as `dorothy@example.com` / `password123`
3. Submit a suspicious message — Ollama will analyze it in the background
4. Log in as `sarah@example.com` to see the caregiver dashboard and 7-day analytics
5. Log in as `admin@eldershield.com` to see the full 30-day admin analytics

---

## File Hierarchy

```
src/
├── index.php                       ← Entry point (redirects to login or dashboard)
├── .htaccess                       ← Security headers, directory listing disabled
│
├── config/
│   ├── config.php                  ← ⭐ App settings, timezone, Ollama config (edit first)
│   └── db.php                      ← PDO database connection singleton
│
├── includes/
│   ├── auth.php                    ← Login, register, logout, session, CSRF helpers
│   ├── helpers.php                 ← CRUD helpers: incidents, notifications, links, timeAgo
│   ├── ai_service.php              ← Ollama integration + async background dispatch
│   ├── billing_helper.php          ← Invoice generation, payment simulation, billing queries
│   ├── subscription_helper.php     ← Plan management: free/premium, link limits, upgrades
│   ├── header.php                  ← Shared HTML header + role-aware navbar
│   └── footer.php                  ← Shared HTML footer
│
├── pages/
│   ├── login.php                   ← Login form
│   ├── register.php                ← Registration (min 7-character password)
│   ├── logout.php                  ← Session destroy + redirect
│   ├── dashboard.php               ← Role-aware dashboard with visual analytics
│   │                                    Elder: recent reports + stats
│   │                                    Caregiver: 7-day bar chart + scam category breakdown
│   │                                    Admin: 30-day volume chart + donut + categories + MoM
│   ├── submit.php                  ← Elder: submit suspicious content + optional screenshot
│   ├── incident_detail.php         ← Full AI result; elder can edit+reanalyze; admin controls
│   ├── my_incidents.php            ← Elder: personal incident history
│   ├── incidents.php               ← Admin/Caregiver: all incidents with filters
│   ├── notifications.php           ← Notification inbox; admin can broadcast or target 1 user
│   ├── admin_users.php             ← User management, role changes, caregiver–elder linking
│   ├── admin_subscriptions.php     ← Admin: upgrade/downgrade/pause caregiver plans
│   ├── billing.php                 ← Caregiver: view invoices, retry failed payments
│   ├── invoice_history.php         ← Caregiver: full invoice history
│   ├── subscription.php            ← Caregiver: self-service plan upgrade/downgrade
│   ├── profile.php                 ← Profile info + password change (min 7 characters)
│   ├── about.php                   ← Public about/info page
│   ├── mark_notification.php       ← AJAX endpoint: mark notification read/unread
│   └── logout.php                  ← Destroys session
│
├── api/
│   ├── run_analysis.php            ← CLI worker: runs Ollama analysis + saves result
│   ├── delete_incident.php         ← Delete incident (GET + CSRF token)
│   └── reanalyze.php               ← Admin: re-run AI on existing incident (POST)
│
├── database/
│   ├── eldershield.sql             ← Complete MySQL schema (run once)
│   └── seed.php                    ← Demo data seeder — 19 users + 27 incidents (delete after use!)
│
├── uploads/                        ← User screenshot uploads (writable, PHP execution blocked)
│   └── .htaccess                   ← Denies PHP execution in this directory
│
└── assets/
    ├── css/style.css               ← App stylesheet
    └── js/app.js                   ← Minimal JS utilities
```

---

## User Roles

| Role | What they can do |
|---|---|
| **Elder** | Submit suspicious content, view own reports, edit pending submissions, delete own reports |
| **Caregiver** | Monitor linked elders' incidents, receive high/medium risk notifications, manage elder links, view 7-day analytics |
| **Admin** | Everything above + manage all users, change roles, broadcast/target notifications, edit analysis results, manage subscriptions, view 30-day analytics |

Admins are created by other admins only — the registration form does not offer the admin role.

---

## Subscription / Billing Model

- **Elders** — always free, no limits
- **Caregiver Free** — up to **2 linked elders** (`FREE_LINK_LIMIT` in `subscription_helper.php`)
- **Caregiver Premium** — unlimited linked elders, **$9.99/month** flat rate
  - Invoices generated via `cli/run_billing.php` (run monthly via cron)
  - Payments simulated at 95% success rate for demo purposes
  - Admins can upgrade, downgrade, or pause plans from `admin_subscriptions.php`
  - Failed payments generate a notification and can be retried from `billing.php`

---

## AI / Ollama Integration

- **Model:** `qwen2.5vl:7b` by default (vision-capable; set in `config/config.php`)
- **Transport:** HTTP POST to `http://localhost:11434/api/chat`
- **Mode:** Asynchronous — analysis runs as a background CLI process via `api/run_analysis.php` so the elder's page loads immediately
- **Input:** Text description + optional base64-encoded screenshot
- **Output:** Structured JSON parsed into five fields:

| Field | Description |
|---|---|
| `scam_probability` | Integer 0–100 |
| `scam_category` | `phishing`, `impersonation`, `romance_scam`, `tech_support`, `lottery_prize`, `grandparent_scam`, `investment_fraud`, `other`, or `not_a_scam` |
| `manipulation_tactics` | Array of detected tactics (e.g. `urgency`, `fear_based_language`) |
| `explanation_simple` | 2–3 plain sentences written for a senior audience |
| `recommended_action` | 2–3 concrete steps the user should take |

- Caregivers and admins are **auto-notified** when `scam_probability ≥ 40%` (medium) or `≥ 70%` (high)
- Admins can manually edit analysis results or re-run the AI from `incident_detail.php`

---

## Notification System

- **Auto-notifications** — generated when an incident is analyzed at medium or high risk
- **Broadcast** — admin sends a message to all active users at once
- **Targeted** — admin sends a private message to one specific user
- **Edit propagation** — editing a broadcast notification updates all recipient copies simultaneously
- Mark read/unread via AJAX (`mark_notification.php`)

---

## Dashboard Analytics

### Caregiver Dashboard
- **Stats:** linked elder count, total incidents, high-risk count this week
- **7-day stacked bar chart** — daily incident volume broken down by risk level
- **Scam category horizontal bars** — top scam types among linked elders this week

### Admin Dashboard
- **Stats:** total users, total incidents, all-time high-risk count, this-month count with % change vs last month
- **30-day volume chart** — daily stacked bars (high / medium / low) across all users
- **Risk distribution donut** — percentage breakdown of all analyzed incidents
- **Top scam categories** — horizontal bar chart for the past 30 days

---

## Security

- All DB queries use **PDO prepared statements** (no SQL injection)
- Passwords hashed with **bcrypt** (cost 12); minimum 7 characters
- **CSRF tokens** on every form
- **Session ID regeneration** on login (prevents session fixation)
- Uploads validated by MIME type, `getimagesize()`, and extension whitelist
- PHP execution **blocked** in the `uploads/` directory via `.htaccess`
- All user output escaped with `htmlspecialchars()` (`e()` helper)
- Security headers set on every response: `Content-Security-Policy`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`

---

## Timestamps

All timestamps are stored as `DATETIME` in MySQL and displayed relative to the timezone set in `config/config.php`:

```php
define('APP_TIMEZONE', 'America/Denver');
```

Change this to match your server's local timezone so "2h ago" and similar labels display correctly.