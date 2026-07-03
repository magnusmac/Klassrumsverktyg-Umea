-- Körs automatiskt av MariaDB-containern vid första uppstart
-- (efter 01-schema.sql). Skapar grundinställningar och ett
-- admin-konto för lokal testning: admin@klassrum.se / admin123

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
  ('site_name',                             'Klassrumsverktyg'),
  ('require_login_for_whiteboard_creation', '0'),
  ('allowed_whiteboard_creator_ip_ranges',  ''),
  ('registration_enabled',                  '1');

INSERT IGNORE INTO users (username, first_name, last_name, email, password, role, is_active)
VALUES ('admin', 'Admin', 'Admin', 'admin@klassrum.se',
        '$2y$12$CAIsh7vKCfpWYU9CszFv1.N/NsNOiPmxtmu3YuGGDI9REW9YEpBIy',
        'admin', 1);
