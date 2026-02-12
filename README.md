# ElderShield 🛡️  
AI-Powered Scam Detection & Awareness Platform

ElderShield is a cybersecurity-focused web application designed to protect elderly users from scams such as phishing, impersonation fraud, tech support scams, romance scams, and malicious links. The platform allows seniors to submit suspicious messages/calls/URLs, receive an AI-generated risk assessment in plain language, and enables caregivers/admins to monitor threats and intervene early.

---

## 📌 Project Overview

ElderShield is built with a dual-interface system:

### 👴 Elder User Interface
A simplified, accessibility-first interface designed for seniors.

Key capabilities:
- Submit suspicious content for analysis
- Receive scam likelihood scoring (0–100%)
- View scam type and manipulation tactics
- Get simple explanations and “What to do next” guidance
- Review previously submitted reports

### 🧑‍⚕️ Caregiver/Admin Dashboard
A management console for monitoring and intervention.

Key capabilities:
- Monitor all incident submissions
- View high-risk scams flagged by AI
- Manage user accounts and caregiver relationships
- Validate scam incidents and take action
- Export data for reporting/escalation

---

## 🎯 Goals
- Reduce scam victimization among elderly populations
- Provide easy-to-understand scam explanations
- Enable caregivers to intervene before financial or emotional harm occurs
- Maintain strong privacy and cybersecurity practices through secure system design

---

## 🧠 AI Component (Key Differentiator)

ElderShield uses an NLP-based AI engine (OpenAI API) to analyze scam reports and detect patterns such as:
- urgency/time pressure
- fear-based language
- authority impersonation
- manipulation tactics common in social engineering

Each incident generates structured AI output:
- scam probability score
- scam category
- simplified explanation
- recommended actions

---

## 🗃️ Database / ERD (Final Schema)

This project is built around a relational database design using **MySQL**.

### Entities (5 Total)

#### 1) Users
| Field | Type | Notes |
|------|------|------|
| user_id | PK | unique user ID |
| full_name | text | |
| email | text | unique |
| password_hash | text | securely stored |
| role | enum | elder / caregiver / admin |
| created_at | timestamp | |

#### 2) Incidents
| Field | Type | Notes |
|------|------|------|
| incident_id | PK | |
| user_id | FK | → users.user_id |
| content | text | suspicious message/call/URL |
| status | text | pending/analyzed/resolved |
| submitted_at | timestamp | |

#### 3) Analysis
| Field | Type | Notes |
|------|------|------|
| analysis_id | PK | |
| incident_id | FK | → incidents.incident_id |
| scam_probability | int | 0–100 |
| scam_category | text | |
| explanation_simple | text | plain language |
| recommended_action | text | |
| created_at | timestamp | |

#### 4) Notifications
| Field | Type | Notes |
|------|------|------|
| notification_id | PK | |
| incident_id | FK | → incidents.incident_id |
| recipient_user_id | FK | → users.user_id |
| message_text | text | |
| notification_type | text | |
| created_at | timestamp | |

#### 5) Account_links
| Field | Type | Notes |
|------|------|------|
| link_id | PK | |
| elder_user_id | FK | → users.user_id |
| caregiver_user_id | FK | → users.user_id |
| relationship_type | text | child/spouse/etc |
| status | text | active/inactive |
| created_at | timestamp | |

---

## 🔁 CRUD Functionality (Core Features)

### Users (User Management)
- Create user accounts (elder, caregiver, admin)
- Read user profile and dashboard access
- Update user settings and account details
- Delete/deactivate accounts

### Incidents
- Create scam report submissions
- Read incident history + AI results
- Update incidents with additional details
- Delete incidents for privacy

### Analysis
- Create AI-generated analysis results per incident
- Read scam probability + explanation
- Update analysis (re-run AI or admin override)
- Delete analysis if incident removed

### Notifications
- Create alerts for caregivers/admins
- Read notifications in dashboard feed
- Update notifications (mark read/unread)
- Delete notifications (cleanup/auto-expire)

### Account_links
- Create caregiver-to-elder relationship
- Read all linked accounts
- Update relationship status/type
- Delete caregiver access

---

## 🔐 Cybersecurity & Privacy

Security considerations built into the design:
- Password hashing
- Session-based authentication
- Input validation and secure database queries (PDO)
- Audit-friendly structure
- Encryption in transit and at rest (deployment requirement)
- Privacy-by-design (no monetization of user data)

---

## 🧰 Technology Stack

| Layer | Technology |
|------|------------|
| Backend | PHP (PDO, MVC optional) |
| Database | MySQL |
| Frontend | HTML5 + Bootstrap |
| AI/NLP | OpenAI API |
| Security | Hashing + Sessions |
| Hosting | Cloud (AWS/GCP/Azure) |

---

## 👥 Team
- Sunny Nguyen  
- Joe Milner  
- Cameron Hubbard  

---

## 📄 License
This project is an academic system proposal and prototype for IS 6465.  
All rights reserved unless otherwise stated.
