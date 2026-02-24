-- ============================================================
-- ElderShield Database Schema
-- MySQL (MAMP compatible)
-- ============================================================

CREATE DATABASE IF NOT EXISTS eldershield CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE eldershield;

-- ============================================================
-- USERS
-- ============================================================
CREATE TABLE users (
    user_id     INT AUTO_INCREMENT PRIMARY KEY,
    full_name   VARCHAR(150)        NOT NULL,
    email       VARCHAR(255)        NOT NULL UNIQUE,
    password_hash VARCHAR(255)      NOT NULL,
    role        ENUM('elder','caregiver','admin') NOT NULL DEFAULT 'elder',
    is_active   TINYINT(1)         NOT NULL DEFAULT 1,
    created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- INCIDENTS
-- ============================================================
CREATE TABLE incidents (
    incident_id     INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT             NOT NULL,
    content         TEXT            NOT NULL,
    image_path      VARCHAR(500)    DEFAULT NULL,
    status          ENUM('pending','analyzed','reviewed','dismissed') NOT NULL DEFAULT 'pending',
    submitted_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- ANALYSIS
-- ============================================================
CREATE TABLE analysis (
    analysis_id         INT AUTO_INCREMENT PRIMARY KEY,
    incident_id         INT             NOT NULL,
    scam_probability    DECIMAL(5,2)    NOT NULL DEFAULT 0.00,  -- 0.00 to 100.00
    scam_category       VARCHAR(100)    DEFAULT NULL,
    manipulation_tactics JSON           DEFAULT NULL,
    explanation_simple  TEXT            DEFAULT NULL,
    recommended_action  TEXT            DEFAULT NULL,
    ai_raw_response     JSON            DEFAULT NULL,
    admin_override      TINYINT(1)     NOT NULL DEFAULT 0,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (incident_id) REFERENCES incidents(incident_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- NOTIFICATIONS
-- ============================================================
CREATE TABLE notifications (
    notification_id     INT AUTO_INCREMENT PRIMARY KEY,
    incident_id         INT             NOT NULL,
    recipient_user_id   INT             NOT NULL,
    message_text        TEXT            NOT NULL,
    notification_type   ENUM('high_risk','medium_risk','info','admin_action') NOT NULL DEFAULT 'info',
    is_read             TINYINT(1)     NOT NULL DEFAULT 0,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (incident_id) REFERENCES incidents(incident_id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- ACCOUNT_LINKS (caregiver <-> elder relationships)
-- ============================================================
CREATE TABLE account_links (
    link_id             INT AUTO_INCREMENT PRIMARY KEY,
    elder_user_id       INT             NOT NULL,
    caregiver_user_id   INT             NOT NULL,
    relationship_type   VARCHAR(100)    DEFAULT 'caregiver',
    status              ENUM('pending','active','revoked') NOT NULL DEFAULT 'pending',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_link (elder_user_id, caregiver_user_id),
    FOREIGN KEY (elder_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (caregiver_user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- SEED DATA (demo admin + elder + caregiver)
-- Passwords are bcrypt of "password123"
-- ============================================================
INSERT INTO users (full_name, email, password_hash, role) VALUES
('Admin User',     'admin@eldershield.com',     '$2y$12$YourHashHereReplace', 'admin'),
('Dorothy Johnson','dorothy@example.com',        '$2y$12$YourHashHereReplace', 'elder'),
('Sarah Johnson',  'sarah@example.com',          '$2y$12$YourHashHereReplace', 'caregiver');

-- You must regenerate hashes using PHP:
-- echo password_hash('password123', PASSWORD_BCRYPT, ['cost'=>12]);
-- Then UPDATE users SET password_hash='...' WHERE email='...';
