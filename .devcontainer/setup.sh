#!/usr/bin/env bash
# Körs automatiskt varje gång Codespace startar.
# Installerar databasen om den inte redan finns.
set -e

DB_HOST="db"
DB_NAME="klassrum"
DB_USER="root"
DB_PASS="klassrum123"
DB_CFG="/var/www/html/src/Config/Database.php"
LOCK_FILE="/var/www/html/install/.lock"

# Vänta tills MariaDB svarar (max 30 s)
echo "Väntar på databasen..."
for i in $(seq 1 30); do
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "SELECT 1;" >/dev/null 2>&1 && break
    sleep 1
done

# Skapa Database.php om den saknas
if [ ! -f "$DB_CFG" ]; then
    cat > "$DB_CFG" << PHP
<?php
class Database {
    private \$conn;
    public function getConnection() {
        if (\$this->conn !== null) return \$this->conn;
        \$dsn = "mysql:host=${DB_HOST};dbname=${DB_NAME};charset=utf8mb4";
        \$options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        \$this->conn = new PDO(\$dsn, "${DB_USER}", "${DB_PASS}", \$options);
        return \$this->conn;
    }
}
PHP
    echo "Database.php skapad."
fi

# Importera schema om tabellerna saknas
TABLE_COUNT=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';" \
    --skip-column-names 2>/dev/null || echo "0")

if [ "$TABLE_COUNT" -lt "5" ]; then
    echo "Importerar databas-schema..."
    sed 's/utf8mb4_0900_ai_ci/utf8mb4_general_ci/g' \
        /var/www/html/install/schema.sql \
        | mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME"

    # Skapa admin-konto
    HASH=$(php -r "echo password_hash('admin123', PASSWORD_DEFAULT);")
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" << SQL
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
  ('site_name',                              'Klassrumsverktyg'),
  ('require_login_for_whiteboard_creation',  '0'),
  ('allowed_whiteboard_creator_ip_ranges',   ''),
  ('registration_enabled',                   '1');

INSERT IGNORE INTO users (username, first_name, last_name, email, password, role, is_active)
VALUES ('admin', 'Admin', 'Admin', 'admin@klassrum.se', '$HASH', 'admin', 1);
SQL

    echo "Databas klar. Admin: admin@klassrum.se / admin123"
fi

# Skapa install-lås så att install.php inte visas
touch "$LOCK_FILE"

# Säkerställ att upload-mappen finns
mkdir -p /var/www/html/assets/uploads/pdfs
chmod -R 777 /var/www/html/assets/uploads

echo "Klassrumsverktyg är redo på port 8080!"
