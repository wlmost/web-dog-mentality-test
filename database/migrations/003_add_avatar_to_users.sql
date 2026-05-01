-- Migration: 003
-- Description: Avatar-Feld für Benutzerprofile
-- Date: 2026-05-01
ALTER TABLE {{PREFIX}}auth_users
ADD COLUMN avatar VARCHAR(255) DEFAULT NULL AFTER full_name;
CREATE INDEX idx_avatar ON {{PREFIX}}auth_users(avatar);
