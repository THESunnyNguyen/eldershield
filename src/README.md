# ElderShield — Setup Guide

## Prerequisites
- MAMP (or XAMPP) running Apache + PHP 8.1+ + MySQL
- OpenAI API key (for AI analysis)

---

## 1. Place Files

Copy the `eldershield/` folder into your MAMP `htdocs`:
```
/Applications/MAMP/htdocs/eldershield/
```

---

## 2. Create the Database

1. Open **phpMyAdmin**: http://localhost:8888/phpMyAdmin
2. Create a new database named `eldershield` (utf8mb4, unicode_ci)
3. Import `database/eldershield.sql`

---

## 3. Configure the App

**`config/config.php`** — update these values:
```php
define('OPENAI_API_KEY', 'sk-YOUR-KEY-HERE');   // ← Your OpenAI key
define('APP_URL', 'http://localhost:8888/eldershield');
```

**`config/db.php`** — MAMP defaults are already set:
```php
define('DB_PORT', '8889');   // MAMP MySQL port (change to 3306 for XAMPP)
define('DB_USER', 'root');
define('DB_PASS', 'root');
```

---

## 4. Seed Demo Users

Visit in your browser:
```
http://localhost:8888/eldershield/database/seed.php
```

This creates 3 demo accounts (all with password `password123`):
| Email | Role |
|---|---|
| admin@eldershield.com | Admin |
| dorothy@example.com | Elder |
| sarah@example.com | Caregiver |

**Delete `database/seed.php` after running it!**

---

## 5. Set Uploads Permissions

```bash
chmod 755 /Applications/MAMP/htdocs/eldershield/uploads/
```

---

## 6. Test It

1. http://localhost:8888/eldershield/pages/login.php
2. Log in as `dorothy@example.com` / `password123`
3. Submit a suspicious message
4. Log in as `sarah@example.com` to see the caregiver dashboard

---

## File Hierarchy

```
eldershield/
├── index.php                    ← Entry point (redirects to login/dashboard)
├── .htaccess                    ← Security headers, no directory listing
│
├── config/
│   ├── config.php               ← ⭐ API keys, app settings (edit this first)
│   └── db.php                   ← PDO database connection
│
├── includes/
│   ├── auth.php                 ← Login, register, session, CSRF helpers
│   ├── helpers.php              ← CRUD functions (incidents, notifications, links)
│   ├── ai_service.php           ← OpenAI GPT-4o-mini integration
│   ├── header.php               ← Shared HTML header + navbar
│   └── footer.php               ← Shared HTML footer
│
├── pages/
│   ├── login.php                ← Login form
│   ├── register.php             ← Registration form
│   ├── logout.php               ← Destroys session
│   ├── dashboard.php            ← Role-aware dashboard (elder/caregiver/admin)
│   ├── submit.php               ← Elder: submit suspicious content + image upload
│   ├── incident_detail.php      ← Full AI analysis result view
│   ├── my_incidents.php         ← Elder: view own report history
│   ├── incidents.php            ← Admin/Caregiver: all incidents with filters
│   ├── notifications.php        ← Notification inbox
│   ├── admin_users.php          ← User management + caregiver–elder linking
│   └── profile.php              ← Profile & password settings
│
├── api/
│   ├── delete_incident.php      ← Delete incident (GET with CSRF token)
│   └── reanalyze.php            ← Admin: re-run AI on existing incident (POST)
│
├── database/
│   ├── eldershield.sql          ← Full MySQL schema
│   └── seed.php                 ← Demo user seeder (delete after use!)
│
├── uploads/                     ← User-uploaded screenshots (auto-created)
│   └── .htaccess                ← Blocks PHP execution in uploads
│
└── assets/
    ├── css/
    │   └── style.css            ← Base stylesheet (team: replace/extend this)
    └── js/
        └── app.js               ← Minimal JS utilities
```

---

## AI Integration Notes

- Model: `gpt-4o-mini` (supports vision for screenshots)
- Each analysis costs ~$0.001–0.003 (text) or ~$0.003–0.008 (with image)
- The AI returns structured JSON: probability, category, tactics, explanation, actions
- Caregivers are auto-notified when scam probability ≥ 40%
- Admins can re-run analysis from the incident detail page

## Security Notes

- All DB queries use PDO prepared statements (no SQL injection)
- Passwords stored as bcrypt (cost 12)
- CSRF tokens on all forms
- Session regeneration on login
- Uploads validated (MIME type + `getimagesize()` + extension whitelist)
- PHP execution blocked in uploads folder
- User input escaped with `htmlspecialchars()` everywhere
