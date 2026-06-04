-- Migration: 005
-- Description: Passwort-Reset-Tokens + auth_logs um Reset-Aktionen erweitern
-- Date: 2026-06-04

-- Tabelle für Passwort-Reset-Token
CREATE TABLE IF NOT EXISTS {{PREFIX}}auth_password_resets (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT         NOT NULL,
  token_hash  CHAR(64)    NOT NULL,           -- SHA-256 des gesendeten Tokens
  expires_at  DATETIME    NOT NULL,
  created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY  uq_user     (user_id),          -- max. ein aktiver Reset pro User
  INDEX       idx_token   (token_hash),
  INDEX       idx_expires (expires_at),
  FOREIGN KEY (user_id) REFERENCES {{PREFIX}}auth_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Einmalige Passwort-Reset-Tokens (1 h Gültigkeit)';

-- auth_logs ENUM um Reset-Aktionen erweitern
ALTER TABLE {{PREFIX}}auth_logs
  MODIFY COLUMN action ENUM(
    'login_success',
    'login_failed',
    'logout',
    '2fa_success',
    '2fa_failed',
    'account_locked',
    'password_reset_requested',
    'password_reset_success',
    'password_reset_failed'
  ) NOT NULL;
