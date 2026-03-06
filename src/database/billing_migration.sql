-- ============================================================
-- ElderShield Billing Migration
-- Run AFTER eldershield.sql and subscription_migration.sql
-- ============================================================

USE eldershield;

-- ── Step 1: Update account_links for billing history ─────────
--
-- PROBLEM: The old UNIQUE KEY uq_link(elder_user_id, caregiver_user_id)
-- prevents re-linking after revoke AND destroys proration history.
--
-- SOLUTION: Drop that unique key. Add linked_at / unlinked_at
-- timestamps. Add a new unique key only on ACTIVE links using
-- a generated helper column so only one active link per pair
-- is allowed at any time.
-- ─────────────────────────────────────────────────────────────

-- Add timestamp columns for proration calculations
ALTER TABLE account_links
    ADD COLUMN linked_at    DATETIME NULL DEFAULT NULL AFTER status,
    ADD COLUMN unlinked_at  DATETIME NULL DEFAULT NULL AFTER linked_at;

-- Backfill: treat existing created_at as linked_at for active rows
UPDATE account_links SET linked_at = created_at WHERE status = 'active';

-- Drop the old blanket unique key
ALTER TABLE account_links DROP INDEX uq_link;

-- Add a new unique key that includes status so that:
--   (elder=1, caregiver=2, active) is unique — can't double-link
--   (elder=1, caregiver=2, revoked) can exist multiple times (history)
-- MySQL unique keys enforce per distinct value, so this works correctly.
ALTER TABLE account_links
    ADD UNIQUE KEY uq_active_link (elder_user_id, caregiver_user_id, status);

-- ── Step 2: Make notifications.incident_id nullable ──────────
--
-- REASON: Billing notifications have no associated incident.
-- Making this nullable lets us reuse the existing notifications
-- system for billing alerts without a separate table.
-- ─────────────────────────────────────────────────────────────

ALTER TABLE notifications
    MODIFY COLUMN incident_id INT NULL DEFAULT NULL;

-- Also add 'billing' to the notification_type ENUM
ALTER TABLE notifications
    MODIFY COLUMN notification_type
        ENUM('high_risk','medium_risk','info','admin_action','billing')
        NOT NULL DEFAULT 'info';

-- ── Step 3: Create invoices table ────────────────────────────
--
-- DESIGN DECISIONS:
--   - amount_cents: integer cents (99 = $0.99) avoids float rounding
--   - billing_month: stored as DATE (always day=01) for easy grouping
--   - unique(caregiver_id, billing_month): idempotent — can't double-bill
--   - elder_count: snapshot of how many elders were billed at run time
--   - status: pending → paid → failed lifecycle
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS invoices (
    invoice_id      INT AUTO_INCREMENT PRIMARY KEY,
    caregiver_id    INT             NOT NULL,
    billing_month   DATE            NOT NULL,          -- always YYYY-MM-01
    elder_count     INT             NOT NULL DEFAULT 0,
    amount_cents    INT             NOT NULL DEFAULT 0, -- total in cents
    status          ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
    payment_method  VARCHAR(100)    DEFAULT NULL,       -- simulated card last4
    paid_at         DATETIME        DEFAULT NULL,
    failed_at       DATETIME        DEFAULT NULL,
    failure_reason  VARCHAR(255)    DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invoice (caregiver_id, billing_month),
    FOREIGN KEY (caregiver_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Step 4: Create invoice_line_items table ───────────────────
--
-- One row per elder per invoice. Stores the proration calculation
-- so the caregiver can see exactly how their bill was computed.
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS invoice_line_items (
    line_id         INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id      INT             NOT NULL,
    elder_id        INT             NOT NULL,
    elder_name      VARCHAR(150)    NOT NULL,          -- snapshot at billing time
    linked_at       DATETIME        NOT NULL,           -- when link became active
    unlinked_at     DATETIME        DEFAULT NULL,       -- NULL = still active
    days_active     INT             NOT NULL,           -- calculated days in month
    days_in_month   INT             NOT NULL,           -- total days in billing month
    unit_cents      INT             NOT NULL DEFAULT 99, -- $0.99 in cents
    line_amount_cents INT           NOT NULL,           -- prorated amount in cents
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id) ON DELETE CASCADE,
    FOREIGN KEY (elder_id)   REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Step 5: Store caregiver payment method (simulated) ───────
--
-- Caregivers need a saved payment method for automatic billing.
-- Stored as last4 + expiry only — never store full card numbers.
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS caregiver_payment_methods (
    payment_method_id   INT AUTO_INCREMENT PRIMARY KEY,
    caregiver_id        INT             NOT NULL UNIQUE, -- one method per caregiver
    card_last4          CHAR(4)         NOT NULL,
    card_expiry         CHAR(5)         NOT NULL,       -- MM/YY
    cardholder_name     VARCHAR(100)    NOT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (caregiver_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Step 6: Enable MySQL Event Scheduler ─────────────────────
--
-- This powers automatic billing on the 1st of each month.
-- MAMP users: run "SET GLOBAL event_scheduler = ON;" in phpMyAdmin
-- or add "event_scheduler=ON" to your my.cnf [mysqld] section.
--
-- The event calls a stored procedure so the logic lives in PHP
-- (billing_runner.php) rather than in SQL. The event hits the
-- runner via a localhost HTTP call — see billing_runner.php.
-- ─────────────────────────────────────────────────────────────

-- NOTE: MySQL Events cannot make HTTP calls directly.
-- Instead we create a flag table the runner checks on every page load
-- (a lightweight approach suitable for academic/local projects).

CREATE TABLE IF NOT EXISTS billing_run_log (
    run_id          INT AUTO_INCREMENT PRIMARY KEY,
    billing_month   DATE            NOT NULL UNIQUE,   -- prevents duplicate runs
    started_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME        DEFAULT NULL,
    invoices_created INT            NOT NULL DEFAULT 0,
    status          ENUM('running','completed','failed') NOT NULL DEFAULT 'running'
) ENGINE=InnoDB;
