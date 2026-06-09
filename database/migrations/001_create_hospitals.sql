-- Migration 001 — Table hospitals
CREATE TABLE IF NOT EXISTS hospitals (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom        VARCHAR(150) NOT NULL,
    adresse    VARCHAR(255) DEFAULT NULL,
    ville      VARCHAR(100) NOT NULL,
    pays       VARCHAR(80)  DEFAULT 'France',
    telephone  VARCHAR(25)  DEFAULT NULL,
    email      VARCHAR(150) DEFAULT NULL,
    site_web   VARCHAR(200) DEFAULT NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME     DEFAULT NULL,
    INDEX idx_ville  (ville),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
