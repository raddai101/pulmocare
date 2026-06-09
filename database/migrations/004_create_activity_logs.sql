-- Migration 004 — Table activity_logs
CREATE TABLE IF NOT EXISTS activity_logs (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED DEFAULT NULL,
    action     VARCHAR(80)  NOT NULL,
    ip_address VARCHAR(45)  DEFAULT NULL,
    context    JSON         DEFAULT NULL,
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_action  (action),
    INDEX idx_created (created_at),
    CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
