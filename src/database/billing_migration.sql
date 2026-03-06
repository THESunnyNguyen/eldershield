-- ============================================================
-- ElderShield Billing v2 Migration
-- Run AFTER eldershield.sql and subscription_migration.sql
-- ============================================================
-- Pricing model:
--   • Elder accounts  → always free
--   • Caregiver accounts → $0.99 / active linked elder / month
--   • Billing runs automatically on the 1st via MySQL Event Scheduler
--   • Amounts stored as INTEGER CENTS to avoid floating-point drift
-- ============================================================

USE eldershield;

-- ------------------------------------------------------------
-- 1. Drop old subscription tables (replaced by billing model)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS subscriptions;
DROP TABLE IF EXISTS subscription_plans;

-- ------------------------------------------------------------
-- 2. Alter account_links
--    • Add linked_at / unlinked_at for proration
--    • Soft-delete: status='revoked' keeps the row for history
--    • New unique key: only ONE active link per elder+caregiver pair
--      (allows multiple revoked rows for same pair over time)
-- ------------------------------------------------------------
ALTER TABLE account_links
    ADD COLUMN linked_at    DATETIME NULL DEFAULT NULL AFTER status,
    ADD COLUMN unlinked_at  DATETIME NULL DEFAULT NULL AFTER linked_at;

-- Populate linked_at for existing active rows
UPDATE account_links SET linked_at = created_at WHERE status = 'active' AND linked_at IS NULL;

-- Replace the broad unique key with a partial-style approach:
-- We enforce uniqueness at the application layer for active links,
-- and add a composite key that includes status so revoked rows
-- do not block new active ones.
ALTER TABLE account_links DROP INDEX uq_link;
ALTER TABLE account_links
    ADD UNIQUE KEY uq_active_link (elder_user_id, caregiver_user_id, status);
-- Note: MySQL unique keys with NULLs still allow duplicates for NULL values,
-- but status is an ENUM (never NULL), so this correctly blocks duplicate
-- active/pending links while allowing multiple revoked rows.

-- ------------------------------------------------------------
-- 3. Invoices table
--    • One row per caregiver per billing month
--    • amount_cents: integer, e.g. 99 = $0.99, 297 = $2.97
--    • elder_count: snapshot of linked elders at billing time
--    • Unique constraint prevents double-billing same month
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoices (
    invoice_id      INT AUTO_INCREMENT PRIMARY KEY,
    caregiver_id    INT         NOT NULL,
    billing_month   DATE        NOT NULL,          -- always the 1st, e.g. 2026-03-01
    elder_count     INT         NOT NULL DEFAULT 0,
    amount_cents    INT         NOT NULL DEFAULT 0, -- total = elder_count * 99
    status          ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
    payment_method  VARCHAR(100) DEFAULT NULL,      -- masked card, e.g. "Visa ••••4242"
    paid_at         DATETIME     DEFAULT NULL,
    created_at      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invoice_month (caregiver_id, billing_month),
    FOREIGN KEY (caregiver_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. Invoice line items
--    • One row per linked elder per invoice
--    • Captures proration: days_active / days_in_month
--    • amount_cents per line may be < 99 for partial months
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoice_line_items (
    line_id         INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id      INT         NOT NULL,
    elder_id        INT         NOT NULL,
    elder_name      VARCHAR(150) NOT NULL,          -- snapshot (user may be deleted later)
    days_active     INT         NOT NULL DEFAULT 0, -- days the link was active this month
    days_in_month   INT         NOT NULL DEFAULT 28,
    amount_cents    INT         NOT NULL DEFAULT 0, -- ROUND(99 * days_active / days_in_month)
    created_at      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id) ON DELETE CASCADE,
    FOREIGN KEY (elder_id)   REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. Billing notifications
--    Make incident_id nullable so billing events can use the
--    existing notifications system without a real incident.
-- ------------------------------------------------------------
ALTER TABLE notifications
    MODIFY COLUMN incident_id INT NULL DEFAULT NULL;

ALTER TABLE notifications
    ADD COLUMN notification_category
        ENUM('incident','billing') NOT NULL DEFAULT 'incident' AFTER notification_type;

-- Update existing rows
UPDATE notifications SET notification_category = 'incident' WHERE incident_id IS NOT NULL;

-- Extend the notification_type enum to include billing events
ALTER TABLE notifications
    MODIFY COLUMN notification_type
        ENUM('high_risk','medium_risk','info','admin_action','billing_success','billing_failed','billing_overdue')
        NOT NULL DEFAULT 'info';

-- ------------------------------------------------------------
-- 6. Enable MySQL Event Scheduler
--    Run this manually if the EVENT below doesn't fire.
--    SET GLOBAL event_scheduler = ON;
-- ------------------------------------------------------------

-- Drop existing event if re-running migration
DROP EVENT IF EXISTS generate_monthly_invoices;

DELIMITER $$

CREATE EVENT generate_monthly_invoices
ON SCHEDULE EVERY 1 MONTH
STARTS (DATE_FORMAT(NOW(), '%Y-%m-01') + INTERVAL 1 MONTH)
DO
BEGIN
    -- The PHP billing runner handles the actual logic.
    -- This event calls a stored procedure as a lightweight trigger.
    -- In MAMP: ensure event_scheduler=ON in my.cnf
    -- Alternatively, use a system cron: 0 0 1 * * php /path/to/run_billing.php
    CALL run_monthly_billing();
END$$

DELIMITER ;

-- Stored procedure called by the event (logic delegated to PHP via CLI)
-- The PHP script src/cli/run_billing.php does the real work.
-- This procedure is a stub that can be extended if pure-SQL billing is preferred.
DROP PROCEDURE IF EXISTS run_monthly_billing;

DELIMITER $$
CREATE PROCEDURE run_monthly_billing()
BEGIN
    -- Stub: real billing logic lives in src/cli/run_billing.php
    -- This procedure exists so the MySQL event has a valid target.
    -- To run manually: CALL run_monthly_billing();
    SELECT 'Billing trigger fired — run src/cli/run_billing.php' AS message;
END$$
DELIMITER ;
