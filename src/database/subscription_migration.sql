-- ============================================================
-- Subscription Migration
-- Run this against your existing eldershield database
-- ============================================================

USE eldershield;

-- Plans catalogue (static reference data)
CREATE TABLE IF NOT EXISTS subscription_plans (
    plan_id      INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(50)      NOT NULL UNIQUE,   -- 'free' | 'premium'
    price        DECIMAL(6,2)     NOT NULL DEFAULT 0.00,
    max_incidents_per_month INT   NOT NULL DEFAULT 5, -- -1 = unlimited
    notifications_enabled   TINYINT(1) NOT NULL DEFAULT 0,
    created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Seed plans
INSERT IGNORE INTO subscription_plans (name, price, max_incidents_per_month, notifications_enabled)
VALUES
    ('free',    0.00,  5,  0),
    ('premium', 9.99, -1,  1);

-- User subscriptions (one active row per user)
CREATE TABLE IF NOT EXISTS subscriptions (
    subscription_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT          NOT NULL UNIQUE,          -- one subscription per user
    plan_id         INT          NOT NULL,
    status          ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    started_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME     DEFAULT NULL,              -- NULL = no expiry (free tier)
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)  REFERENCES users(user_id)  ON DELETE CASCADE,
    FOREIGN KEY (plan_id)  REFERENCES subscription_plans(plan_id)
) ENGINE=InnoDB;
