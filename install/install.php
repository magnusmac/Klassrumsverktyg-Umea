<?php
// install/install.php – On‑prem installer för Klassrumsverktyg
// Körs en gång: skriver Database.php, skapar system_settings och kan skapa första admin.

// ---- Re‑run protection (.lock) ----
$lockFile = __DIR__ . '/.lock';
if (file_exists($lockFile)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:2rem;background:#f7fafc;color:#1a202c}</style>';
    echo '<h1>Installationen är redan låst</h1>';
    echo '<p>Ta bort filen <code>install/.lock</code> om du medvetet vill köra installationen igen.</p>';
    exit;
}

// ---- Paths ----
$TARGET_PATHS = [
    // Skriv där som finns i din repo/installation
    realpath(__DIR__ . '/..') . '/private/src/Config/Database.php',
    realpath(__DIR__ . '/..') . '/src/Config/Database.php',
];
$TEMPLATE_CANDIDATES = [
    realpath(__DIR__ . '/..') . '/src/Config/Database_template.php',
    realpath(__DIR__ . '/..') . '/private/src/Config/Database_template.php',
];

// ---- Utils ----
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function find_first_existing(array $paths){ foreach ($paths as $p) { if ($p && is_file($p)) return $p; } return null; }
function is_really_writable($path) {
    if (is_dir($path)) {
        $tmp = rtrim($path,'/').'/.__writetest_'.bin2hex(random_bytes(6));
        $ok = @file_put_contents($tmp, 'test') !== false;
        if ($ok) @unlink($tmp);
        return $ok;
    }
    if (file_exists($path)) return is_writable($path);
    return is_really_writable(dirname($path));
}

/**
 * Import a .sql file (multiple statements, comments, etc.) into the given PDO connection.
 */
function import_sql_file(PDO $pdo, string $filePath): void {
    if (!is_file($filePath)) {
        throw new RuntimeException('SQL-filen hittades inte: ' . $filePath);
    }
    $sql = file_get_contents($filePath);
    if ($sql === false) {
        throw new RuntimeException('Kunde inte läsa SQL-filen.');
    }
    $sql = str_replace("\r", '', $sql);

    // Ta bort blockkommentarer /* ... */
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

    $lines = explode("\n", $sql);
    $delimiter = ';';
    $buffer = '';
    $statements = [];

    $inSingle = false; // '
    $inDouble = false; // "
    $inBack   = false; // `

    foreach ($lines as $rawLine) {
        $line = trim($rawLine);
        // Hoppa över rena kommentarsrader
        if ($line === '' || str_starts_with($line, '--') || str_starts_with($line, '#')) {
            continue;
        }
        // Hantera DELIMITER‑kommandon (t.ex. för triggers/procedurer)
        if (strncasecmp($line, 'DELIMITER ', 10) === 0) {
            // flush buffer om något finns kvar (utan att skapa tomt statement)
            $bufTrim = trim($buffer);
            if ($bufTrim !== '') {
                $statements[] = $bufTrim;
                $buffer = '';
            }
            $delimiter = substr($line, 10);
            continue;
        }

        // Lägg tillbaka originalraden i buffer (utan trim) för att bevara whitespace i t.ex. procedurer
        $buffer .= $rawLine . "\n";

        // Iterera tecken för tecken för att hitta delimiter som inte ligger inne i quotes/backticks
        $len = strlen($buffer);
        $i = 0;
        while ($i < $len) {
            $ch = $buffer[$i];
            // quote‑tillstånd
            if ($ch === "'" && !$inDouble && !$inBack) {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle && !$inBack) {
                $inDouble = !$inDouble;
            } elseif ($ch === '`' && !$inSingle && !$inDouble) {
                $inBack = !$inBack;
            }

            // Om vi inte är i någon quote/backtick, kolla om buffern slutar med delimiter
            // För effektivitet, kontrollera bara när vi är på eller efter en möjlig slutposition
            if (!$inSingle && !$inDouble && !$inBack) {
                // Kolla om slutet av buffern matchar delimiter och att vi är på sista tecknet
                // (vi kommer bara splitta vid radslut så att DELIMITER "//" funkar)
                if ($i === $len - 1) {
                    $trimmed = rtrim($buffer);
                    if ($delimiter !== '' && substr($trimmed, -strlen($delimiter)) === $delimiter) {
                        $statement = trim(substr($trimmed, 0, -strlen($delimiter)));
                        if ($statement !== '') {
                            $statements[] = $statement;
                        }
                        $buffer = '';
                        break; // hoppa till nästa rad efter att ha flushat
                    }
                }
            }
            $i++;
        }
    }

    // Sista statement utan avslutande delimiter
    $last = trim($buffer);
    if ($last !== '') {
        $statements[] = $last;
    }

    // Kör statements
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $s = '';
    $startedTx = false;
    try {
        // Försök köra i transaction där det är möjligt (DDL kan auto‑committa i MySQL)
        if ($pdo->beginTransaction()) {
            $startedTx = true;
        }
        foreach ($statements as $idx => $stmt) {
            $s = trim($stmt);
            if ($s === '' || $s === ';') { continue; }
            $pdo->exec($s);
            // Om transaktionen bröts implicit (t.ex. vid DDL), starta om försiktigt
            if ($startedTx && !$pdo->inTransaction()) {
                // Försök starta om transaktion för återstående statements
                $pdo->beginTransaction();
            }
        }
        if ($startedTx && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($startedTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $snippet = substr(preg_replace('/\s+/', ' ', $s), 0, 200);
        throw new RuntimeException('Fel vid SQL‑import (nära): ' . $snippet . ' — ' . $e->getMessage(), 0, $e);
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}

$INLINE_TEMPLATE = <<<'PHP'
<?php
class Database {
    private $conn;
    public function __construct() {
        $host = '{DB_HOST}';
        $db   = '{DB_NAME}';
        $user = '{DB_USER}';
        $pass = '{DB_PASS}';
        $charset = 'utf8mb4';
        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $this->conn = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // Logga detaljerna men visa aldrig dem för besökaren
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            die('Databasanslutning misslyckades. Kontakta administratören.');
        }
    }
    public function getConnection(){ return $this->conn; }
}
PHP;

// ---- Preflight / health check ----
$checks = [
    'php' => [
        'ok' => version_compare(PHP_VERSION, '8.0.0', '>='),
        'msg' => 'PHP ≥ 8.0 krävs (nu: ' . PHP_VERSION . ')'
    ],
    'pdo' => [
        'ok' => class_exists('PDO'),
        'msg' => 'PDO saknas'
    ],
    'pdo_mysql' => [
        'ok' => in_array('mysql', PDO::getAvailableDrivers(), true),
        'msg' => 'pdo_mysql‑drivern saknas'
    ],
];

$errors = [];
$okMsg = null;
$createdAdmin = false;
$schemaPresent = false;   // install/schema.sql finns?
$schemaImported = false;  // importerades utan fel?

// ---- Handle POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1) DB credentials
    $db_host = trim($_POST['db_host'] ?? '');
    $db_name = trim($_POST['db_name'] ?? '');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = (string)($_POST['db_pass'] ?? '');

    if ($db_host === '' || $db_name === '' || $db_user === '') {
        $errors[] = 'Fyll i Värd, Databas och Användare.';
    }
    foreach ($checks as $k=>$c) if (!$c['ok']) $errors[] = $c['msg'];

    // 2) Testa anslutning
    if (!$errors) {
        try {
            $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
            $pdo = new PDO($dsn, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (Throwable $e) {
            $errors[] = 'Kunde inte ansluta till databasen: ' . $e->getMessage();
        }
    }

    // 3) Skriv Database.php
    if (!isset($pdo) || $errors) goto render;

    $templatePath = find_first_existing($TEMPLATE_CANDIDATES);
    $template = $templatePath ? file_get_contents($templatePath) : $INLINE_TEMPLATE;

    $rendered = '';
    if ($template !== false && $template !== '') {
        if (strpos($template, '{DB_HOST}') !== false) {
            // Placeholder-variant
            $rendered = str_replace(['{DB_HOST}','{DB_NAME}','{DB_USER}','{DB_PASS}'], [$db_host,$db_name,$db_user,$db_pass], $template);
        } else {
            // Försök ersätta tomma assignments i template-varianten
            $rendered = preg_replace([
                '/\$host\s*=\s*\'\'\s*;/',
                '/\$db\s*=\s*\'\'\s*;/',
                '/\$user\s*=\s*\'\'\s*;/',
                '/\$pass\s*=\s*\'\'\s*;/',
            ], [
                "\$host = '".addslashes($db_host)."';",
                "\$db   = '".addslashes($db_name)."';",
                "\$user = '".addslashes($db_user)."';",
                "\$pass = '".addslashes($db_pass)."';",
            ], (string)$template);
        }
    }

    // Säkerhetsnät: om renderingen inte satte värden, bygg från INLINE_TEMPLATE
    $stillEmpty = false;
    if ($rendered === '' || $rendered === null) {
        $stillEmpty = true;
    } else {
        $stillEmpty = (bool)preg_match('/(\$host|\$db|\$user|\$pass)\s*=\s*\'\'\s*;/', $rendered);
    }
    if ($stillEmpty) {
        $rendered = str_replace(['{DB_HOST}','{DB_NAME}','{DB_USER}','{DB_PASS}'], [$db_host,$db_name,$db_user,$db_pass], $INLINE_TEMPLATE);
    }

    $writtenTargets = [];
    foreach ($TARGET_PATHS as $target) {
        if (!is_dir(dirname($target))) continue; // skriv bara där katalogen finns
        if (!is_really_writable(dirname($target))) {
            $errors[] = 'Katalogen är inte skrivbar: ' . h(dirname($target));
            continue;
        }
        if (file_put_contents($target, $rendered) === false) {
            $errors[] = 'Misslyckades att skriva Database.php till: ' . h($target);
        } else {
            $writtenTargets[] = $target;

            // Auto-skydda katalogen där Database.php skrevs
            $dir = dirname($target);

            // 1) .htaccess (Apache) – skriv bara om den inte finns
            $htaccessPath = $dir . '/.htaccess';
            if (!file_exists($htaccessPath)) {
                $ht = <<<HTA
# Protect sensitive config directory
# Apache 2.4+
Require all denied

# Fallback for Apache 2.2
<IfModule !mod_authz_core.c>
  Order allow,deny
  Deny from all
</IfModule>

# Prevent content-type sniffing/download
<FilesMatch "\.(php|inc|ini|json|sql|yml|yaml|env)$">
  Require all denied
</FilesMatch>
HTA;
                @file_put_contents($htaccessPath, $ht);
            }

            // 2) index.html – enkel stub för att undvika directory listing
            $indexPath = $dir . '/index.html';
            if (!file_exists($indexPath)) {
                @file_put_contents($indexPath, "<!doctype html><title>403</title>");
            }
        }
    }
    if (!$writtenTargets) {
        $errors[] = 'Hittade ingen plats att skriva Database.php. Skapa katalogen private/src/Config eller src/Config och försök igen.';
    }

    // 4a) Importera schema.sql om den finns
    if (!$errors) {
        $schemaFile = __DIR__ . '/schema.sql';
        if (is_file($schemaFile)) {
            $schemaPresent = true;
            try {
                import_sql_file($pdo, $schemaFile);
                $schemaImported = true;
            } catch (Throwable $e) {
                $errors[] = 'Kunde inte importera install/schema.sql: ' . $e->getMessage();
            }
        }
    }
    // 4) Bas‑migreringar (system_settings & unik nyckel)
    if (!$errors) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) NOT NULL,
                setting_value TEXT NULL,
                description VARCHAR(255) NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_setting_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            // Seed säkra defaultvärden
            $seed = [
                // Grundläggande
                ['site_name','Klassrumsverktyg','Instansens namn som visas i UI.'],
                ['pages_dynamic_enabled','1','Om 1: innehåll för statiska sidor hämtas från tabellen pages.'],
                ['allow_self_registration','0','Av: användare kan inte själva registrera sig (default).'],
                ['require_login_for_whiteboard_creation','0','Om 1: inloggning krävs för att skapa whiteboards.'],
                ['allowed_whiteboard_creator_ip_ranges','', 'Radseparerad lista över IP/CIDR som får skapa whiteboards. Tomt = ingen IP-begränsning.'],
                ['app_key', bin2hex(random_bytes(16)), 'Slumpad applikationsnyckel.'],

                // SMTP (PHPMailer)
                ['smtp_host','', 'SMTP-server (t.ex. smtp.gmail.com)'],
                ['smtp_port','587', 'SMTP-port (587 TLS, 465 SSL)'],
                ['smtp_username','', 'SMTP-användarnamn'],
                ['smtp_password','', 'SMTP-lösenord'],
                ['smtp_encryption','tls', 'tls|ssl|none'],
                ['smtp_from_address','', 'Från‑adress för utgående e‑post'],
                ['smtp_from_name','Klassrumsverktyg', 'Från‑namn för utgående e‑post'],

                // reCAPTCHA
                ['recaptcha_enabled','0','Om 1: reCAPTCHA används på utvalda formulär.'],
                ['recaptcha_site_key','', 'Google reCAPTCHA site key'],
                ['recaptcha_secret_key','', 'Google reCAPTCHA secret key'],

                // Google OAuth
                ['google_oauth_enabled','0','Om 1: Visa Google‑inloggning.'],
                ['google_oauth_client_id','', 'Google OAuth Client ID'],
                ['google_oauth_client_secret','', 'Google OAuth Client Secret'],
                ['google_oauth_allowed_domains','', 'Kommaseparerade domäner som får använda Google‑inloggning (tomt = alla)'],
            ];
            $upsert = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)");
            foreach ($seed as $row) { $upsert->execute($row); }
        } catch (Throwable $e) {
            $errors[] = 'Migrering/seed misslyckades: ' . $e->getMessage();
        }
    }

    // 5) (Valfritt) skapa första admin
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_pass  = (string)($_POST['admin_pass'] ?? '');
    $admin_user  = trim($_POST['admin_username'] ?? '');
    $createAdmin = isset($_POST['create_admin']) && $_POST['create_admin'] === '1';

    if (!$errors && $createAdmin) {
        if ($admin_email === '' || $admin_pass === '' || $admin_user === '') {
            $errors[] = 'Första admin: fyll i e‑post, användarnamn och lösenord.';
        } else {
            try {
                // users‑tabellen enligt din dump (email, username, password, role, first_name, last_name, is_active m.m.)
                // role: enum('admin','teacher') – default teacher. Vi sätter admin.
                $exists = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $exists->execute([$admin_email]);
                if ($exists->fetch()) {
                    $errors[] = 'Det finns redan en användare med denna e‑post.';
                } else {
                    // Match PasswordUtils::hashPassword (Argon2id w/ costs), with a safe fallback
                    if (defined('PASSWORD_ARGON2ID')) {
                        $hash = password_hash($admin_pass, PASSWORD_ARGON2ID, [
                            'memory_cost' => 65536,
                            'time_cost'   => 4,
                            'threads'     => 3,
                        ]);
                    } else {
                        // Fallback for environments without Argon2id support
                        $hash = password_hash($admin_pass, PASSWORD_DEFAULT);
                    }
                    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, first_name, last_name, role, is_active, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, 'admin', 1, NOW(), NOW())");
                    $stmt->execute([$admin_user, $admin_email, $hash, 'Admin', 'User']);
                    $createdAdmin = true;
                }
            } catch (Throwable $e) {
                $errors[] = 'Kunde inte skapa första admin: ' . $e->getMessage();
            }
        }
    }

    // 6) Skriv lockfil om allt gick bra
    if (!$errors) {
        @file_put_contents($lockFile, (new DateTime())->format('c'));
        $okMsg = 'Installationen lyckades! Database.php skapad, systeminställningar seedade' . ($createdAdmin ? ' och första admin skapad.' : '.');
    }
}

render:
?>
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Installation – Klassrumsverktyg (On‑Prem)</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">
<div class="max-w-3xl mx-auto px-4 py-10">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Installation</h1>

    <!-- Health check -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <?php foreach ($checks as $key=>$c): ?>
            <div class="rounded-lg p-4 border <?php echo $c['ok']?'border-green-200 bg-green-50 text-green-900':'border-red-200 bg-red-50 text-red-900'; ?>">
                <div class="font-semibold mb-1">
                    <?php echo h(strtoupper($key)); ?>
                    <?php if ($c['ok']): ?><i class="fa-solid fa-circle-check ml-1"></i><?php else: ?><i class="fa-solid fa-triangle-exclamation ml-1"></i><?php endif; ?>
                </div>
                <div class="text-sm"><?php echo h($c['msg']); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($okMsg)): ?>
        <div class="bg-green-50 border border-green-200 text-green-900 rounded-lg p-4 mb-6">
            <i class="fa-solid fa-circle-check mr-2"></i><?= h($okMsg) ?>
        </div>
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-xl font-semibold mb-2">Installationsstatus</h2>
            <ul class="list-disc ml-6 space-y-1 text-gray-700">
                <?php if ($schemaPresent && $schemaImported): ?>
                    <li><strong>Schema:</strong> <span class="text-green-700">install/schema.sql importerades.</span></li>
                <?php elseif ($schemaPresent && !$schemaImported): ?>
                    <li><strong>Schema:</strong> <span class="text-red-700">install/schema.sql hittades men kunde inte importeras. Se fel ovan.</span></li>
                <?php else: ?>
                    <li><strong>Schema:</strong> <span class="text-gray-700">Ingen <code>install/schema.sql</code> hittades – hoppade över.</span></li>
                <?php endif; ?>
                <li><strong>Konfiguration:</strong> Database.php skrevs till relevanta kataloger.</li>
                <li><strong>Systeminställningar:</strong> skapade/uppdaterade i <code>system_settings</code>.</li>
                <?php if ($createdAdmin): ?>
                    <li><strong>Första admin:</strong> skapad. <span class="text-amber-700">Byt lösenord direkt efter inloggning.</span></li>
                <?php else: ?>
                    <li><strong>Första admin:</strong> ej skapad i detta steg.</li>
                <?php endif; ?>
            </ul>
        </div>
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-xl font-semibold mb-2">Nästa steg</h2>
            <ol class="list-decimal ml-6 space-y-1 text-gray-700">
                <li>Ta bort eller skydda <code class="bg-gray-100 px-1 rounded">install/install.php</code>.</li>
                <li>Ta bort/behåll <code class="bg-gray-100 px-1 rounded">install/.lock</code> enligt behov (behövs för att låsa installationen).</li>
                <li>Gå till <code class="bg-gray-100 px-1 rounded">/admin/settings.php</code> och ställ in policyer.</li>
                <li>Om du inte kör Apache: blockera åtkomst till <code class="bg-gray-100 px-1 rounded">/src/Config/</code> i din webserver.
                    <span class="block text-sm text-gray-600 mt-1">Nginx-exempel: <code class="bg-gray-100 px-1 rounded">location ^~ /src/Config/ { deny all; }</code></span>
                </li>
            </ol>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="bg-red-50 border border-red-200 text-red-900 rounded-lg p-4 mb-6">
            <div class="font-semibold mb-1"><i class="fa-solid fa-triangle-exclamation mr-2"></i>Fel vid installation</div>
            <ul class="list-disc ml-6">
                <?php foreach ($errors as $e): ?>
                    <li><?= h($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-semibold mb-4">1. Databasuppgifter</h2>
        <form method="post" class="space-y-4">
            <div class="bg-blue-50 border border-blue-200 text-blue-900 rounded-lg p-4">
                <i class="fa-solid fa-info-circle mr-2"></i>
                <span class="text-sm">Uppgifterna du fyller i här kommer att sparas i <code>Database.php</code> på följande plats(er):</span>
                <ul class="list-disc ml-6 mt-2 text-sm">
                    <?php foreach ($TARGET_PATHS as $t): if (is_dir(dirname($t))): ?>
                        <li><?= h($t) ?></li>
                    <?php endif; endforeach; ?>
                </ul>
                <p class="text-sm mt-2">Lösenordet lagras i klartext i filen. Se till att <code>private/src/Config</code> (eller motsvarande katalog) inte är åtkomlig via webben.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-gray-700 text-sm">Värd (host) *</span>
                    <input name="db_host" required class="mt-1 w-full border rounded-lg p-2" placeholder="localhost" value="<?= h($_POST['db_host'] ?? '') ?>" />
                </label>
                <label class="block">
                    <span class="text-gray-700 text-sm">Databas *</span>
                    <input name="db_name" required class="mt-1 w-full border rounded-lg p-2" placeholder="klassrumsverktyg" value="<?= h($_POST['db_name'] ?? '') ?>" />
                </label>
                <label class="block">
                    <span class="text-gray-700 text-sm">Användare *</span>
                    <input name="db_user" required class="mt-1 w-full border rounded-lg p-2" placeholder="dbuser" value="<?= h($_POST['db_user'] ?? '') ?>" />
                </label>
                <label class="block">
                    <span class="text-gray-700 text-sm">Lösenord</span>
                    <input name="db_pass" type="password" class="mt-1 w-full border rounded-lg p-2" placeholder="••••••" />
                </label>
            </div>

            <div class="mt-6">
                <h2 class="text-xl font-semibold mb-2">2. (Valfritt) Skapa första admin</h2>
                <p class="text-gray-600 text-sm mb-3">Om du kryssar i detta skapas en <strong>admin</strong> i tabellen <code>users</code> enligt databasdumpen.</p>
                <div class="flex items-center mb-3">
                    <input id="create_admin" type="checkbox" name="create_admin" value="1" class="mr-2">
                    <label for="create_admin" class="text-sm text-gray-800">Skapa första admin</label>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <label class="block">
                        <span class="text-gray-700 text-sm">Admin e‑post</span>
                        <input name="admin_email" class="mt-1 w-full border rounded-lg p-2" placeholder="admin@example.com" value="<?= h($_POST['admin_email'] ?? '') ?>" />
                    </label>
                    <label class="block">
                        <span class="text-gray-700 text-sm">Admin användarnamn</span>
                        <input name="admin_username" class="mt-1 w-full border rounded-lg p-2" placeholder="admin" value="<?= h($_POST['admin_username'] ?? '') ?>" />
                    </label>
                    <label class="block">
                        <span class="text-gray-700 text-sm">Admin lösenord</span>
                        <input name="admin_pass" type="password" class="mt-1 w-full border rounded-lg p-2" placeholder="••••••" />
                    </label>
                </div>
            </div>

            <div class="flex items-center gap-3 pt-4">
                <button class="bg-blue-600 hover:bg-blue-700 text-white rounded-lg px-4 py-2 flex items-center">
                    <i class="fa-solid fa-wrench mr-2"></i>Kör installation
                </button>
                <a href="/" class="text-gray-600 hover:underline">Avbryt</a>
            </div>
        </form>
    </div>

    <div class="text-sm text-gray-500 mt-6">
        <p>Installern skriver <code>Database.php</code> till <code>private/src/Config</code> och/eller <code>src/Config</code> om katalogerna finns och är skrivbara.</p>
        <p class="mt-1">Tabellen <code>system_settings</code> skapas (om den saknas) och nycklar seedas. Struktur och nycklar anpassade efter din dump.<?php /* users/system_settings enligt dumpen */ ?></p>
    </div>
</div>
</body>
</html>
