-- Migration: 004
-- Description: Rate-Limit-Tabelle für KI-Endpoint (ai.php)
-- Date: 2026-06-04
CREATE TABLE IF NOT EXISTS {{PREFIX}}ai_rate_limits (
  identifier     VARCHAR(64)       NOT NULL,  -- 'user_{id}' oder 'ip_{sha1}'
  window_hour    DATETIME          NOT NULL,  -- Fensterbeginn, auf Stunde gerundet
  request_count  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (identifier, window_hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Stündliches Rate Limiting für OpenAI-Calls';
