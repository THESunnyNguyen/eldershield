# ElderShield 🛡️

> AI-Powered Scam Detection & Awareness Platform

![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?logo=mysql&logoColor=white)
![Ollama](https://img.shields.io/badge/AI-Ollama%20Local-black)
![License](https://img.shields.io/badge/License-Academic%20Use-blue)
![Status](https://img.shields.io/badge/Status-Prototype-orange)

ElderShield is a cybersecurity-focused web application designed to protect elderly users from scams such as phishing, impersonation fraud, tech support scams, romance scams, grandparent scams, and investment fraud. Seniors submit screenshots or descriptions of suspicious messages, calls, or emails. A local AI model (Ollama) analyzes the content and returns a plain-language risk assessment. Caregivers and admins are automatically notified of high-risk reports and can monitor, review, and intervene through a dedicated dashboard.

---

## Table of Contents

- [Demo](#demo)
- [Project Overview](#project-overview)
- [Goals](#goals)
- [Prerequisites](#prerequisites)
- [Local Setup](#local-setup)
- [AI Component](#ai-component-key-differentiator)
- [Database Schema](#database-schema)
- [CRUD Functionality](#crud-functionality)
- [Subscription & Billing](#subscription--billing)
- [Dashboard Analytics](#dashboard-analytics)
- [Notification System](#notification-system)
- [Cybersecurity & Privacy](#cybersecurity--privacy)
- [Technology Stack](#technology-stack)
- [Team](#team)
- [License](#license)

---

## Demo

[![▶ Watch the Demo](https://img.shields.io/badge/YouTube-Watch%20Demo-red?logo=youtube)](https://youtu.be/zMp5k4DBVxo)

---

## Project Overview

ElderShield uses a three-role system with a role-aware interface for each user type.

### Elder Interface
A simplified, accessibility-first interface designed for seniors.

Key capabilities:
- Submit suspicious messages, calls, or emails for AI analysis (text + optional screenshot)
- Receive a scam likelihood score (0–100%) with a plain-language explanation
- View detected scam type and manipulation tactics
- Get clear "What to do next" guidance written for a non-technical audience
- Review, edit, and delete previously submitted reports

### Caregiver Dashboard
A monitoring interface for family members and professional caregivers.

Key capabilities:
- Monitor incidents submitted by linked elders
- Receive automatic notifications for medium (≥40%) and high-risk (≥70%) reports
- View 7-day incident analytics: daily volume chart + top scam categories this week
- Manage caregiver–elder relationships (link requests, approvals, revocations)
- Free plan: up to 2 linked elders — Premium plan: unlimited

### Admin Dashboard
A full management console for platform administration.

Key capabilities:
- Manage all users, roles, and account status
- Broadcast notifications to all users or send targeted messages to one user
- Manually edit or re-run AI analysis on any incident
- Manage caregiver subscription plans (upgrade, downgrade, pause)
- View 30-day incident analytics: volume chart, risk distribution donut, scam category breakdown, month-over-month comparison

---

## Goals
- Reduce scam victimization among elderly populations
- Provide easy-to-understand scam explanations written for seniors
- Enable caregivers to intervene before financial or emotional harm occurs
- Maintain strong privacy and cybersecurity practices through secure system design
- Run fully offline — no cloud AI dependency, no user data sent to third parties

---

## Prerequisites

Before setting up ElderShield, make sure you have the following installed:

| Requirement | Version | Notes |
|---|---|---|
| [MAMP](https://www.mamp.info/) or [XAMPP](https://www.apachefriends.org/) | Latest | Provides Apache + PHP + MySQL |
| PHP | 8.1+ | Included with MAMP/XAMPP |
| MySQL | 5.7+ | Included with MAMP/XAMPP |
| [Ollama](https://ollama.com/) | Latest | Runs the AI model locally |
| Git | Any | For cloning the repo |

---

## Local Setup

### 1. Clone the repository

```bash
git clone https://github.com/THESunnyNguyen/eldershield.git
```

Place the `eldershield/src` folder inside your web server's document root and rename it to `eldershield`:
- **MAMP (Mac):** `/Applications/MAMP/htdocs/eldershield`
- **XAMPP (Windows):** `C:\xampp\htdocs\eldershield`

### 2. Start your local server

Launch MAMP or XAMPP and start the **Apache** and **MySQL** services.

### 3. Import the database schema

1. Open **phpMyAdmin** at `http://localhost:8888/phpMyAdmin` (MAMP) or `http://localhost/phpmyadmin` (XAMPP)
2. Create a new database named `eldershield`
3. Select the database and click **Import**
4. Upload `src/database/eldershield.sql` from the repo

### 4. Configure the database connection

Open `src/config/db.php` and confirm the credentials match your local setup:

```php
$host = 'localhost';
$db   = 'eldershield';
$user = 'root';
$pass = 'root'; // MAMP default — adjust if different
```

### 5. Seed demo data

In your browser, navigate to:

```
http://localhost:8888/eldershield/database/seed.php
```

This creates all demo users, incidents, and linked accounts. **Delete `seed.php` after running it.**

### 6. Install and configure Ollama

1. Download and install [Ollama](https://ollama.com/)
2. Pull the vision model:
```bash
ollama pull qwen2.5vl:7b
```
3. Start the Ollama server:
```bash
ollama serve
```
Ollama must be running for AI analysis to work. Analysis runs asynchronously — the page loads immediately and refreshes when results are ready.

### 7. Open the app

Navigate to: `http://localhost:8888/eldershield` (MAMP) or `http://localhost/eldershield` (XAMPP)

### 8. Demo accounts

**Admins**

| Email | Password |
|---|---|
| `admin@eldershield.com` | `password123` |
| `bsmith@eldershield.com` | `mysecret` |

**Caregivers**

| Email | Password | Plan | Linked Elders |
|---|---|---|---|
| `sarah@example.com` | `password123` | Free | 2 |
| `mjohnson@example.com` | `password123` | Premium | 4 |
| `arivera@example.com` | `password123` | Premium | 3 |

**Elders**

| Email | Password | Incidents |
|---|---|---|
| `dorothy@example.com` | `password123` | 5 |
| `hturner@example.com` | `password123` | 3 |
| `emartinez@example.com` | `password123` | 3 |
| `pjones@example.com` | `acrobat` | 2 |

---

## AI Component (Key Differentiator)

ElderShield uses **Ollama** running locally to analyze scam reports — no external API keys or internet connection required. The default model is `qwen2.5vl:7b`, a vision-capable model that can analyze both text descriptions and uploaded screenshots.

Analysis runs **asynchronously** in a background CLI process so the elder's page loads immediately while the AI works. Results are saved to the database and the page auto-refreshes when ready.

The AI detects patterns including:
- Urgency and time pressure
- Fear-based language
- Authority impersonation
- Gift card, wire transfer, and prepaid card requests
- Social engineering tactics common in grandparent, romance, and tech support scams

Each incident generates structured output with five fields:

| Field | Description |
|---|---|
| `scam_probability` | Integer 0–100 |
| `scam_category` | `phishing`, `impersonation`, `romance_scam`, `tech_support`, `lottery_prize`, `grandparent_scam`, `investment_fraud`, `other`, or `not_a_scam` |
| `manipulation_tactics` | Array of detected tactic labels (e.g. `urgency`, `fear_based_language`) |
| `explanation_simple` | 2–3 plain sentences written for a senior audience |
| `recommended_action` | 2–3 concrete steps the user should take |

Admins can manually override any field or trigger a fresh AI re-run from the incident detail page.

---

## Database Schema

Built on **MySQL** with 5 tables in a fully relational design.

![ERD Diagram](docs/Latest_ERD.png)

| Table | Purpose |
|---|---|
| `users` | All accounts — elders, caregivers, admins. Stores role, plan (free/premium), plan expiry, and active status |
| `incidents` | Scam reports submitted by elders. Links to user, stores content, optional image path, and status |
| `analysis` | One-to-one with incidents. Stores AI output: probability, category, tactics, explanation, recommended action |
| `account_links` | Caregiver–elder relationships. Status: `pending`, `active`, or `revoked` |
| `notifications` | In-app notifications. Supports auto-alerts, admin broadcasts, and targeted single-user messages |

---

## CRUD Functionality

### Users
- **Create** — register as elder or caregiver; admin creates admin accounts
- **Read** — role-aware dashboard, profile page, admin user list
- **Update** — profile info, password (min 7 characters), role changes (admin only), plan changes
- **Delete/Deactivate** — admin can deactivate or permanently delete accounts

### Incidents
- **Create** — elder submits text + optional screenshot
- **Read** — elder sees own history; caregiver sees linked elders'; admin sees all
- **Update** — elder can edit and re-submit; admin can edit analysis fields or reprompt AI
- **Delete** — elder can delete own reports; admin can delete any

### Analysis
- **Create** — generated automatically by Ollama after incident submission
- **Read** — displayed on incident detail page with risk gauge, tactics, explanation
- **Update** — admin manual edit or AI re-run (reprompt); both use upsert
- **Delete** — cleared when incident is deleted (cascade) or admin clears for elder re-submission

### Notifications
- **Create** — auto-generated on medium/high risk; admin broadcast; admin targeted message; billing events
- **Read** — notification inbox (user sees own; admin sees all)
- **Update** — mark read/unread via AJAX; admin edits propagate to all broadcast copies
- **Delete** — user deletes own; admin can delete any or clear all

### Account Links
- **Create** — caregiver sends link request by elder email
- **Read** — admin sees all relationships; caregiver sees own; elder sees linked caregivers on profile
- **Update** — approve (pending → active), revoke (delete), reactivate
- **Delete** — hard delete on revoke (allows re-linking)

---

## Subscription & Billing

| Plan | Price | Elder Link Limit |
|---|---|---|
| Caregiver Free | $0 | 2 |
| Caregiver Premium | $9.99/month | Unlimited |
| Elder | Always free | N/A |

- Invoices generated monthly via `cli/run_billing.php` (designed for a cron job)
- Payment processing simulated at 95% success rate for demo purposes
- Failed payments generate a caregiver notification and can be retried from the billing page
- Admins can upgrade, downgrade, or pause any caregiver's plan from `admin_subscriptions.php`

---

## Dashboard Analytics

### Caregiver (7-Day View)
- High-risk count this week
- Stacked bar chart — daily incidents broken down by risk level (high / medium / low)
- Horizontal bar chart — top scam categories among linked elders this week

### Admin (30-Day View)
- Total users, total incidents, all-time high-risk count
- This-month incident count with % change vs last month
- 30-day stacked bar chart — daily volume across all users
- Risk distribution donut chart — percentage breakdown of all analyzed incidents
- Top scam categories horizontal bar chart for the past 30 days

---

## Notification System

- **Auto-alerts** — triggered when AI analysis returns ≥40% (medium) or ≥70% (high); sent to linked caregivers and all admins
- **Broadcast** — admin sends a message to every active user at once
- **Targeted** — admin sends a private message to one specific user selected from a dropdown
- **Edit propagation** — editing a broadcast notification updates all recipient copies simultaneously
- **Billing notifications** — sent automatically on invoice success or failure
- Mark read/unread via AJAX without page reload

---

## Cybersecurity & Privacy

- All database queries use **PDO prepared statements** — no SQL injection possible
- Passwords hashed with **bcrypt** (cost factor 12); minimum 7 characters enforced at both form and server level
- **CSRF tokens** on every state-changing form
- **Session ID regeneration** on login (prevents session fixation attacks)
- Uploaded images validated by MIME type, `getimagesize()`, and extension whitelist (JPG, PNG, GIF, WEBP)
- PHP execution **blocked** in the `uploads/` directory via `.htaccess`
- All user-generated output escaped with `htmlspecialchars()` via the `e()` helper
- Security headers on every response: `Content-Security-Policy`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`
- AI runs **fully locally via Ollama** — no user data is transmitted to any external service

---

## Technology Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.1+ |
| Database | MySQL (PDO) |
| Frontend | HTML5 + CSS3 (custom, no framework) |
| AI / NLP | Ollama (local) — default model: `qwen2.5vl:7b` |
| Web Server | Apache via MAMP or XAMPP |
| Security | bcrypt · CSRF tokens · PDO · session hardening · CSP headers |

---

## Team
- [Sunny Nguyen](https://github.com/THESunnyNguyen)
- [Joe Milner](https://github.com/syrm4)
- Cameron Hubbard

---

## License
This project is an academic system prototype for IS 6465.  
All rights reserved unless otherwise stated.
