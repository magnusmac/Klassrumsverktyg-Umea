#!/usr/bin/env bash
# Startar Klassrumsverktyg med ett enda kommando:  bash starta.sh
# Fungerar i GitHub Codespaces och på valfri dator med Docker.
set -e
cd "$(dirname "$0")"

# 1. Skapa databaskonfiguration om den saknas (pekar på Docker-tjänsten "db")
if [ ! -f src/Config/Database.php ]; then
    cat > src/Config/Database.php << 'PHP'
<?php
class Database {
    private $conn;
    public function getConnection() {
        if ($this->conn !== null) return $this->conn;
        $dsn = "mysql:host=db;dbname=klassrum;charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $this->conn = new PDO($dsn, "root", "klassrum123", $options);
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            die('Databasanslutning misslyckades. Kontakta administratören.');
        }
        return $this->conn;
    }
}
PHP
    echo "src/Config/Database.php skapad."
fi

# 2. Lås installeraren och se till att uppladdningsmappen finns
touch install/.lock
mkdir -p assets/uploads/pdfs

# 3. Starta allt (databasen initieras automatiskt första gången)
echo ""
echo "Startar Klassrumsverktyg på http://localhost:8080"
echo "Logga in med: admin@klassrum.se / admin123"
echo "Avsluta med Ctrl+C."
echo ""
docker compose up
