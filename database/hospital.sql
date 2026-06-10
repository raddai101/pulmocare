-- ============================================================
--  PulmoCare IA — Schéma base de données MySQL
--  Plateforme de détection du cancer du poumon
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ─── Hôpitaux ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hospitals (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom        VARCHAR(150)  NOT NULL,
    adresse    VARCHAR(255)  DEFAULT NULL,
    ville      VARCHAR(100)  NOT NULL,
    pays       VARCHAR(80)   DEFAULT 'France',
    telephone  VARCHAR(25)   DEFAULT NULL,
    email      VARCHAR(150)  DEFAULT NULL,
    site_web   VARCHAR(200)  DEFAULT NULL,
    is_active  TINYINT(1)    NOT NULL DEFAULT 1,
    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME      DEFAULT NULL,
    INDEX idx_ville (ville),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Médecins / Utilisateurs ──────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hospital_id             INT UNSIGNED DEFAULT NULL,
    nom                     VARCHAR(80)   NOT NULL,
    prenom                  VARCHAR(80)   NOT NULL,
    email                   VARCHAR(150)  NOT NULL UNIQUE,
    password                VARCHAR(255)  NOT NULL,
    telephone               VARCHAR(25)   DEFAULT NULL,
    specialite              VARCHAR(100)  DEFAULT NULL,
    numero_ordre            VARCHAR(50)   DEFAULT NULL,
    role                    ENUM('medecin','admin') NOT NULL DEFAULT 'medecin',
    avatar                  VARCHAR(255)  DEFAULT NULL,
    is_active               TINYINT(1)   NOT NULL DEFAULT 0,
    email_verified_at       DATETIME     DEFAULT NULL,
    remember_token          VARCHAR(100) DEFAULT NULL,
    reset_token             VARCHAR(64)  DEFAULT NULL,
    reset_token_expires_at  DATETIME     DEFAULT NULL,
    last_login_at           DATETIME     DEFAULT NULL,
    last_login_ip           VARCHAR(45)  DEFAULT NULL,
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at              DATETIME     DEFAULT NULL,
    INDEX idx_email     (email),
    INDEX idx_role      (role),
    INDEX idx_active    (is_active),
    INDEX idx_hospital  (hospital_id),
    CONSTRAINT fk_user_hospital FOREIGN KEY (hospital_id) REFERENCES hospitals(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Détections / Analyses CT Scan ────────────────────────────
CREATE TABLE IF NOT EXISTS detections (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id              INT UNSIGNED NOT NULL,
    patient_nom          VARCHAR(80)  NOT NULL,
    patient_prenom       VARCHAR(80)  NOT NULL,
    patient_age          TINYINT UNSIGNED DEFAULT NULL,
    patient_sexe         ENUM('M','F','Autre') DEFAULT NULL,
    patient_code         VARCHAR(50)  DEFAULT NULL,
    image_path           VARCHAR(500) NOT NULL,
    image_original_name  VARCHAR(255) DEFAULT NULL,
    image_size           INT UNSIGNED DEFAULT NULL COMMENT 'Taille en octets',
    image_hash           VARCHAR(64)  DEFAULT NULL COMMENT 'SHA-256 pour déduplication',
    result_type          ENUM('normal','suspect','cancereux','inconnu') NOT NULL DEFAULT 'inconnu',
    confidence_score     DECIMAL(5,2) DEFAULT NULL COMMENT 'Confiance IA en %',
    stage                ENUM('I','II','III','IV') DEFAULT NULL,
    regions_json         JSON         DEFAULT NULL COMMENT 'Coordonnées zones détectées',
    model_version        VARCHAR(20)  DEFAULT '1.0',
    processing_time_ms   INT UNSIGNED DEFAULT NULL,
    notes_medecin        TEXT         DEFAULT NULL,
    is_reviewed          TINYINT(1)  NOT NULL DEFAULT 0,
    status               ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    created_at           DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at           DATETIME    DEFAULT NULL,
    INDEX idx_user_id     (user_id),
    INDEX idx_result_type (result_type),
    INDEX idx_created     (created_at),
    INDEX idx_image_hash  (image_hash),
    INDEX idx_status      (status),
    CONSTRAINT fk_detection_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Logs d'activité ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS activity_logs (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED DEFAULT NULL,
    action     VARCHAR(80)  NOT NULL,
    ip_address VARCHAR(45)  DEFAULT NULL,
    context    JSON         DEFAULT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id  (user_id),
    INDEX idx_action   (action),
    INDEX idx_created  (created_at),
    CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Données de démo ──────────────────────────────────────────
INSERT INTO hospitals (nom, ville, pays, telephone, email) VALUES
    ('CHU Pitié-Salpêtrière', 'Paris', 'France', '+33 1 42 16 00 00', 'contact@chu-pitie.fr'),
    ('Hôpital Cochin', 'Paris', 'France', '+33 1 58 41 41 41', 'contact@cochin.fr'),
    ('CHU de Lyon', 'Lyon', 'France', '+33 4 72 11 69 11', 'contact@chu-lyon.fr'),
    ('Hôpital Lariboisière', 'Paris', 'France', '+33 1 49 95 65 65', 'contact@lariboisiere.fr');

-- Admin par défaut (password: Admin@2024!)
-- Argon2id hash de "Admin@2024!"
INSERT INTO users (hospital_id, nom, prenom, email, password, specialite, numero_ordre, role, is_active, email_verified_at) VALUES
    (1, 'Dupont', 'Marie', 'admin@pulmocare.fr',
     '$argon2id$v=19$m=65536,t=4,p=1$c3YyU1RUcEg2OFU2dm9naQ$wAaVm7rsNywhaHEWA5Jg+ZQevgRidAhKEgzfaVPSCTw',
     'Pneumologie', 'ORDRE-ADMIN-001', 'admin', 1, NOW());

SET FOREIGN_KEY_CHECKS = 1;
