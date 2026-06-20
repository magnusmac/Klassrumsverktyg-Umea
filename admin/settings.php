<?php
// admin/settings.php – Systeminställningar (On‑Prem)
// Låter admin styra globala inställningar för installationen
// Första inställningen: tillåt egenregistrering (default = false)

require_once __DIR__ . '/../src/Config/Database.php';

// --- DB bootstrap ---
$database = new Database();
$pdo = $database->getConnection();

// --- Hjälpfunktioner för key/value-inställningar ---
function get_setting(PDO $pdo, string $key, $default = null) {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['setting_value'])) {
        return $row['setting_value'];
    }
    return $default;
}

function set_setting(PDO $pdo, string $key, $value, ?string $description = null) {
    // Försök uppdatera, annars insert
    $existsStmt = $pdo->prepare("SELECT id FROM system_settings WHERE setting_key = ? LIMIT 1");
    $existsStmt->execute([$key]);
    $existing = $existsStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ?, description = COALESCE(?, description), updated_at = CURRENT_TIMESTAMP WHERE setting_key = ?");
        return $stmt->execute([$value, $description, $key]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description, created_at, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        return $stmt->execute([$key, $value, $description]);
    }
}

// --- Hantera POST (spara inställningar) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_registration_setting') {
    // Checkbox skickas endast om den är förbockad
    $allow = isset($_POST['allow_self_registration']) ? '1' : '0';
    $desc  = 'Styr om nya användare kan skapa konto själva via registreringssidan (0 = av, 1 = på). Standard är 0 (av).';
    set_setting($pdo, 'allow_self_registration', $allow, $desc);

    // Post-redirect-get för att undvika resubmits
    header('Location: settings.php?saved=1');
    exit;
}

// Spara: kräver inloggning för whiteboard-skapande
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_whiteboard_login_requirement') {
    $requireLogin = isset($_POST['require_login_whiteboard']) ? '1' : '0';
    $desc  = 'När satt till 1 måste användaren vara inloggad för att kunna skapa en ny whiteboard.';
    set_setting($pdo, 'require_login_for_whiteboard_creation', $requireLogin, $desc);
    header('Location: settings.php?saved=1#wb');
    exit;
}

// Spara: tillåtna IP/CIDR för whiteboard-skapande (en per rad)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_whiteboard_ip_lock') {
    $ranges = trim($_POST['whiteboard_allowed_cidrs'] ?? '');
    $desc = 'Lista med tillåtna IP eller CIDR-intervall (en per rad) som får skapa nya whiteboards. Tomt = inga IP-begränsningar.';
    set_setting($pdo, 'allowed_whiteboard_creator_ip_ranges', $ranges, $desc);
    header('Location: settings.php?saved=1#wb');
    exit;
}

// Spara: sidans namn (titel)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_site_name') {
    $name = trim($_POST['site_name'] ?? '');
    // begränsa längden rimligt
    if (mb_strlen($name) > 100) {
        $name = mb_substr($name, 0, 100);
    }
    $desc = 'Visningsnamn/titel för installationen (t.ex. skolans/kontorets namn).';
    set_setting($pdo, 'site_name', $name, $desc);
    header('Location: settings.php?saved=1#general');
    exit;
}

// Spara: Google reCAPTCHA (v2/v3) nycklar + aktivering
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_recaptcha_settings') {
    $enabled = isset($_POST['recaptcha_enabled']) ? '1' : '0';
    $siteKey = trim($_POST['recaptcha_site_key'] ?? '');
    $secret  = trim($_POST['recaptcha_secret_key'] ?? '');

    // Lätta skydd så vi undviker extrem längd
    if (mb_strlen($siteKey) > 120)  { $siteKey = mb_substr($siteKey, 0, 120); }
    if (mb_strlen($secret) > 120)   { $secret  = mb_substr($secret, 0, 120); }

    set_setting($pdo, 'recaptcha_enabled', $enabled, 'Om 1: reCAPTCHA används på sidor som stödjer det.');
    set_setting($pdo, 'recaptcha_site_key', $siteKey, 'Google reCAPTCHA site key (offentlig).');
    // Uppdatera secret endast om fältet inte är tomt
    if ($secret !== '') {
        set_setting($pdo, 'recaptcha_secret_key', $secret, 'Google reCAPTCHA secret key (privat/server).');
    }

header('Location: settings.php?saved=1#captcha');
exit;
}

// Spara: E‑post (SMTP) inställningar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_smtp_settings') {
    $enabled = isset($_POST['smtp_enabled']) ? '1' : '0';
    $host    = trim($_POST['smtp_host'] ?? '');
    $port    = (int)($_POST['smtp_port'] ?? 587);
    $user    = trim($_POST['smtp_username'] ?? '');
    $pass    = trim($_POST['smtp_password'] ?? '');
    $enc     = strtolower(trim($_POST['smtp_encryption'] ?? 'tls')); // tls|ssl|none
    $fromAdr = trim($_POST['smtp_from_address'] ?? '');
    $fromNam = trim($_POST['smtp_from_name'] ?? '');

    if (!in_array($enc, ['tls','ssl','none'], true)) { $enc = 'tls'; }
    if ($port <= 0 || $port > 65535) { $port = 587; }

    // Rimliga längdbegränsningar
    foreach (['host'=>&$host,'user'=>&$user,'fromAdr'=>&$fromAdr,'fromNam'=>&$fromNam] as $k => &$v) {
        if (mb_strlen($v) > 190) { $v = mb_substr($v, 0, 190); }
    }

    set_setting($pdo, 'smtp_enabled', $enabled, 'Om 1: skicka e‑post via SMTP.');
    set_setting($pdo, 'smtp_host', $host, 'SMTP server host.');
    set_setting($pdo, 'smtp_port', (string)$port, 'SMTP server port.');
    set_setting($pdo, 'smtp_username', $user, 'SMTP användarnamn.');
    // Uppdatera lösenord endast om fältet inte är tomt
    if ($pass !== '') {
        set_setting($pdo, 'smtp_password', $pass, 'SMTP lösenord.');
    }
    set_setting($pdo, 'smtp_encryption', $enc, 'smtp kryptering: tls|ssl|none');
    set_setting($pdo, 'smtp_from_address', $fromAdr, 'Från‑adress.');
    set_setting($pdo, 'smtp_from_name', $fromNam, 'Från‑namn.');

    header('Location: settings.php?saved=1#smtp');
    exit;
}

// Skicka testmejl med nuvarande SMTP-inställningar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_smtp_send') {
    $testTo = trim($_POST['test_email'] ?? '');

    // Enkel validering av e‑post
    if ($testTo === '' || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        header('Location: settings.php?smtp_test=fail&msg=' . urlencode('Ogiltig e‑postadress') . '#smtp');
        exit;
    }

    // Läs inställningar
    $smtpEnabled = get_setting($pdo, 'smtp_enabled', '0') === '1';
    $smtpHost    = (string) get_setting($pdo, 'smtp_host', '');
    $smtpPort    = (int) get_setting($pdo, 'smtp_port', '587');
    $smtpUser    = (string) get_setting($pdo, 'smtp_username', '');
    $smtpPass    = (string) get_setting($pdo, 'smtp_password', '');
    $smtpEnc     = strtolower((string) get_setting($pdo, 'smtp_encryption', 'tls'));
    $fromAdr     = (string) get_setting($pdo, 'smtp_from_address', '');
    $fromNam     = (string) get_setting($pdo, 'smtp_from_name', '');
    $siteNameSet = (string) get_setting($pdo, 'site_name', 'Klassrumsverktyg');

    if (!$smtpEnabled) {
        header('Location: settings.php?smtp_test=fail&msg=' . urlencode('SMTP är inte aktiverat') . '#smtp');
        exit;
    }

    // Ladda PHPMailer
    try {
        if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
            require_once __DIR__ . '/../../vendor/autoload.php';
        }
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->Port = $smtpPort;
        $mail->SMTPAuth = ($smtpUser !== '' || $smtpPass !== '');
        if ($mail->SMTPAuth) {
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
        }
        if ($smtpEnc === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($smtpEnc === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
        }

        $fromAddress = $fromAdr !== '' ? $fromAdr : ('noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $fromName = $fromNam !== '' ? $fromNam : $siteNameSet;

        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($testTo);
        $mail->Subject = 'Testmejl – ' . ($fromName ?: $siteNameSet);
        $mail->isHTML(true);
        $mail->Body = '<p>Detta är ett testmejl från <strong>' . htmlspecialchars($fromName ?: $siteNameSet, ENT_QUOTES, 'UTF-8') . '</strong>.<br>Skickat: ' . date('Y-m-d H:i:s') . '</p>';
        $mail->AltBody = 'Detta är ett testmejl från ' . ($fromName ?: $siteNameSet) . '. Skickat: ' . date('Y-m-d H:i:s');

        $mail->send();
        header('Location: settings.php?smtp_test=ok#smtp');
        exit;
    } catch (\Throwable $e) {
        header('Location: settings.php?smtp_test=fail&msg=' . urlencode('Utskicket misslyckades: ' . $e->getMessage()) . '#smtp');
        exit;
    }
}

// Spara: Google OAuth (sign‑in) inställningar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_google_settings') {
    $gEnabled = isset($_POST['google_enabled']) ? '1' : '0';
    $gClient  = trim($_POST['google_client_id'] ?? '');

    if (mb_strlen($gClient) > 200) { $gClient = mb_substr($gClient, 0, 200); }

    set_setting($pdo, 'google_enabled', $gEnabled, 'Om 1: Google‑inloggning tillåts.');
    set_setting($pdo, 'google_client_id', $gClient, 'Google OAuth 2.0 Client ID (Web).');

    header('Location: settings.php?saved=1#google');
    exit;
}

// Spara: Kontaktuppgifter (support, allmänt, telefon, fritext)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_contact_settings') {
    $support = trim($_POST['contact_support_email'] ?? '');
    $general = trim($_POST['contact_general_email'] ?? '');
    $phone   = trim($_POST['contact_phone'] ?? '');
    $info    = trim($_POST['contact_info_text'] ?? '');

    // Rimliga längdbegränsningar
    if (mb_strlen($support) > 190) { $support = mb_substr($support, 0, 190); }
    if (mb_strlen($general) > 190) { $general = mb_substr($general, 0, 190); }
    if (mb_strlen($phone) > 40)    { $phone   = mb_substr($phone, 0, 40); }
    if (mb_strlen($info) > 2000)   { $info    = mb_substr($info, 0, 2000); }

    set_setting($pdo, 'contact_support_email', $support, 'Kontakt: e‑post för supportärenden.');
    set_setting($pdo, 'contact_general_email', $general, 'Kontakt: e‑post för allmänna frågor.');
    set_setting($pdo, 'contact_phone', $phone, 'Kontakt: telefonnummer (valfritt).');
    set_setting($pdo, 'contact_info_text', $info, 'Kontakt: fritextinfo som kan visas i footer/kontakt-sida.');

    header('Location: settings.php?saved=1#contact');
    exit;
}

// --- Läs aktuellt värde (default false/0 om ej satt) ---
$allowSelfRegistration = get_setting($pdo, 'allow_self_registration', '0') === '1';
$requireLoginWhiteboard = get_setting($pdo, 'require_login_for_whiteboard_creation', '0') === '1';
$allowedWbCidrsRaw = (string) get_setting($pdo, 'allowed_whiteboard_creator_ip_ranges', '');
$siteName = (string) get_setting($pdo, 'site_name', '');
$recaptchaEnabled  = get_setting($pdo, 'recaptcha_enabled', '0') === '1';
$recaptchaSiteKey  = (string) get_setting($pdo, 'recaptcha_site_key', '');
$recaptchaSecret   = (string) get_setting($pdo, 'recaptcha_secret_key', '');

$smtpEnabled = get_setting($pdo, 'smtp_enabled', '0') === '1';
$smtpHost    = (string) get_setting($pdo, 'smtp_host', '');
$smtpPort    = (string) get_setting($pdo, 'smtp_port', '587');
$smtpUser    = (string) get_setting($pdo, 'smtp_username', '');
// smtp_password ska inte förifyllas i UI (säkerhet)
$smtpEnc     = (string) get_setting($pdo, 'smtp_encryption', 'tls');
$smtpFromAdr = (string) get_setting($pdo, 'smtp_from_address', '');
$smtpFromNam = (string) get_setting($pdo, 'smtp_from_name', $siteName !== '' ? $siteName : 'Klassrumsverktyg');

$googleEnabled = get_setting($pdo, 'google_enabled', '0') === '1';
$googleClientId = (string) get_setting($pdo, 'google_client_id', '');

// Kontaktuppgifter
$contactSupport = (string) get_setting($pdo, 'contact_support_email', '');
$contactGeneral = (string) get_setting($pdo, 'contact_general_email', '');
$contactPhone   = (string) get_setting($pdo, 'contact_phone', '');
$contactInfo    = (string) get_setting($pdo, 'contact_info_text', '');
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Systeminställningar – <?= htmlspecialchars($siteName !== '' ? $siteName : 'Klassrumsverktyg') ?> (On‑Prem)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/vendor/fontawesome/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen">
<?php include_once 'nav.php'; ?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800">Systeminställningar</h1>
        <span class="text-sm text-gray-500">Senast uppdaterad: <?= date('Y-m-d H:i') ?></span>
    </div>

    <!-- Intro -->
    <div class="bg-white rounded-lg shadow border border-gray-200 mb-8">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                <i class="fa-solid fa-gear text-blue-500 mr-2"></i>
                Översikt
            </h2>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-blue-50 rounded-lg p-4 border border-blue-200">
                <h3 class="font-semibold text-blue-900 mb-2">On‑prem policyer</h3>
                <p class="text-gray-700">Här styr du centrala policyer för din lokala installation. Alla sidor som påverkas ska läsa från dessa inställningar.</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                <h3 class="font-semibold text-gray-900 mb-2">Standardvärden</h3>
                <p class="text-gray-700">Om en inställning saknas i databasen används ett säkert standardvärde. För <em>egenregistrering</em> är standard <strong>av</strong>.</p>
            </div>
        </div>
    </div>

    <!-- Kort: Allmänt -->
    <div id="general" class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden mb-8">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                <i class="fa-solid fa-sliders text-sky-600 mr-2"></i>
                Allmänt
            </h2>
            <div>
                <span class="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">Instansernas titel</span>
            </div>
        </div>
        <form method="post" class="p-6">
            <input type="hidden" name="action" value="save_site_name">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                <div>
                    <label for="site_name" class="block text-sm font-medium text-gray-700 mb-1">Sidans namn</label>
                    <input id="site_name" name="site_name" type="text" maxlength="100" class="w-full border rounded-lg px-3 py-2" placeholder="Klassrumsverktyg" value="<?= htmlspecialchars($siteName) ?>" />
                    <p class="text-sm text-gray-600 mt-2">Visas i titel, logga in‑sida och andra lägen där instansens namn används. Lämna tomt för standardnamn.</p>
                </div>
                <div class="self-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg inline-flex items-center">
                        <i class="fa-solid fa-floppy-disk mr-2"></i>
                        Spara
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Kort: reCAPTCHA -->
    <div id="captcha" class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden mb-8">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                <i class="fa-solid fa-shield-halved text-emerald-600 mr-2"></i>
                Google reCAPTCHA
            </h2>
            <div class="flex items-center gap-2">
                <?php if ($recaptchaEnabled): ?>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Aktiverad</span>
                <?php else: ?>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">Av</span>
                <?php endif; ?>
            </div>
        </div>
        <form method="post" class="p-6">
            <input type="hidden" name="action" value="save_recaptcha_settings">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="inline-flex items-center cursor-pointer select-none mb-4">
                        <input type="checkbox" name="recaptcha_enabled" class="sr-only" <?= $recaptchaEnabled ? 'checked' : '' ?>>
                        <span class="w-12 h-7 flex items-center bg-gray-300 rounded-full p-1 transition-all">
                            <span class="w-5 h-5 bg-white rounded-full shadow transform transition-transform <?= $recaptchaEnabled ? 'translate-x-5' : '' ?>"></span>
                        </span>
                        <span class="ml-3 text-sm text-gray-800">Aktivera reCAPTCHA på stödda sidor</span>
                    </label>

                    <label for="recaptcha_site_key" class="block text-sm font-medium text-gray-700 mb-1">Site key (offentlig)</label>
                    <input id="recaptcha_site_key" name="recaptcha_site_key" type="text" maxlength="120" class="w-full border rounded-lg px-3 py-2 mb-4" placeholder="6Lc..." value="<?= htmlspecialchars($recaptchaSiteKey) ?>" />

                    <label for="recaptcha_secret_key" class="block text-sm font-medium text-gray-700 mb-1">Secret key (server)</label>
                    <input id="recaptcha_secret_key" name="recaptcha_secret_key" type="password" maxlength="120" class="w-full border rounded-lg px-3 py-2" placeholder="••••••" value="" />
                    <p class="text-xs text-gray-500 mt-2">Av säkerhetsskäl visas inte nuvarande secret här. Lämna tomt för att behålla befintligt värde.</p>
                </div>
                <div>
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-sm text-gray-700">
                        <p class="mb-2"><strong>Hur används?</strong></p>
                        <ol class="list-decimal ml-5 space-y-1">
                            <li>Aktivera rutan ovan och fyll i dina nycklar från Google reCAPTCHA (v2/v3).</li>
                            <li>I sidor som ska skyddas, läs <code>recaptcha_enabled</code> och <code>recaptcha_site_key</code> från <code>system_settings</code> för att rendera widgeten/script.</li>
                            <li>På serversidan, validera token med <code>recaptcha_secret_key</code> mot Googles API.</li>
                        </ol>
                        <p class="mt-3">Exempel (server): <code>https://www.google.com/recaptcha/api/siteverify</code> med <em>secret</em> och <em>response</em>.</p>
                    </div>
                </div>
            </div>
            <div class="mt-6">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg inline-flex items-center">
                    <i class="fa-solid fa-floppy-disk mr-2"></i>
                    Spara
                </button>
            </div>
        </form>
    </div>

    <!-- Kort: E‑post (SMTP) -->
    <div id="smtp" class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden mb-8">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                <i class="fa-solid fa-envelope text-rose-600 mr-2"></i>
                E‑post (SMTP)
            </h2>
            <div class="flex items-center gap-2">
                <?php if ($smtpEnabled): ?>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Aktiverad</span>
                <?php else: ?>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">Av</span>
                <?php endif; ?>
            </div>
        </div>
        <form method="post" class="p-6">
            <input type="hidden" name="action" value="save_smtp_settings">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="md:col-span-1">
                    <label class="inline-flex items-center cursor-pointer select-none mb-4">
                        <input type="checkbox" name="smtp_enabled" class="sr-only" <?= $smtpEnabled ? 'checked' : '' ?>>
                        <span class="w-12 h-7 flex items-center bg-gray-300 rounded-full p-1 transition-all">
                            <span class="w-5 h-5 bg-white rounded-full shadow transform transition-transform <?= $smtpEnabled ? 'translate-x-5' : '' ?>"></span>
                        </span>
                        <span class="ml-3 text-sm text-gray-800">Aktivera SMTP‑utskick</span>
                    </label>

                    <label for="smtp_host" class="block text-sm font-medium text-gray-700 mb-1">Host</label>
                    <input id="smtp_host" name="smtp_host" type="text" class="w-full border rounded-lg px-3 py-2 mb-4" placeholder="smtp.example.se" value="<?= htmlspecialchars($smtpHost) ?>" />

                    <label for="smtp_port" class="block text-sm font-medium text-gray-700 mb-1">Port</label>
                    <input id="smtp_port" name="smtp_port" type="number" min="1" max="65535" class="w-full border rounded-lg px-3 py-2 mb-4" value="<?= htmlspecialchars($smtpPort) ?>" />

                    <label for="smtp_encryption" class="block text-sm font-medium text-gray-700 mb-1">Kryptering</label>
                    <select id="smtp_encryption" name="smtp_encryption" class="w-full border rounded-lg px-3 py-2 mb-4">
                        <option value="tls" <?= $smtpEnc==='tls'?'selected':''; ?>>TLS</option>
                        <option value="ssl" <?= $smtpEnc==='ssl'?'selected':''; ?>>SSL</option>
                        <option value="none" <?= $smtpEnc==='none'?'selected':''; ?>>Ingen</option>
                    </select>
                </div>
                <div class="md:col-span-1">
                    <label for="smtp_username" class="block text-sm font-medium text-gray-700 mb-1">Användarnamn</label>
                    <input id="smtp_username" name="smtp_username" type="text" class="w-full border rounded-lg px-3 py-2 mb-4" placeholder="user@example.se" value="<?= htmlspecialchars($smtpUser) ?>" />

                    <label for="smtp_password" class="block text-sm font-medium text-gray-700 mb-1">Lösenord</label>
                    <input id="smtp_password" name="smtp_password" type="password" class="w-full border rounded-lg px-3 py-2 mb-1" placeholder="••••••" />
                    <p class="text-xs text-gray-500 mb-4">Av säkerhetsskäl visas inte befintligt lösenord. Lämna tomt för att behålla.</p>

                    <label for="smtp_from_address" class="block text-sm font-medium text-gray-700 mb-1">From‑adress</label>
                    <input id="smtp_from_address" name="smtp_from_address" type="email" class="w-full border rounded-lg px-3 py-2 mb-4" placeholder="noreply@example.se" value="<?= htmlspecialchars($smtpFromAdr) ?>" />

                    <label for="smtp_from_name" class="block text-sm font-medium text-gray-700 mb-1">From‑namn</label>
                    <input id="smtp_from_name" name="smtp_from_name" type="text" class="w-full border rounded-lg px-3 py-2" placeholder="<?= htmlspecialchars($siteName !== '' ? $siteName : 'Klassrumsverktyg') ?>" value="<?= htmlspecialchars($smtpFromNam) ?>" />
                </div>
                <div class="md:col-span-1">
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-sm text-gray-700">
                        <p class="mb-2"><strong>Tips:</strong></p>
                        <ul class="list-disc ml-5 space-y-1">
                            <li>Om din miljö kräver proxy eller brandväggsregler, öppna för vald port och server.</li>
                            <li>För Office 365/Google Workspace: TLS på port 587 fungerar oftast bäst.</li>
                            <li>Från‑namn och adress används i alla systemmejl (t.ex. återställning av lösenord).</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="mt-6 flex flex-col md:flex-row md:items-center gap-4">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg inline-flex items-center">
                    <i class="fa-solid fa-floppy-disk mr-2"></i>
                    Spara
                </button>
                <div class="text-gray-400 select-none">|</div>
                <form method="post" class="flex items-center gap-2" onsubmit="return confirm('Skicka testmejl med nuvarande SMTP‑inställningar?');">
                    <input type="hidden" name="action" value="test_smtp_send">
                    <label for="test_email" class="text-sm text-gray-700">Testa till:</label>
                    <input id="test_email" name="test_email" type="email" class="border rounded-lg px-3 py-2 w-64" placeholder="admin@example.se" required>
                    <button class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded-lg inline-flex items-center">
                        <i class="fa-solid fa-paper-plane mr-2"></i>
                        Skicka testmejl
                    </button>
                </form>
            </div>
        </form>
    </div>

    <!-- Kort: Google OAuth -->
    <div id="google" class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden mb-8">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                <i class="fa-brands fa-google text-red-500 mr-2"></i>
                Google OAuth (inloggning)
            </h2>
            <div class="flex items-center gap-2">
                <?php if ($googleEnabled): ?>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Aktiverad</span>
                <?php else: ?>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">Av</span>
                <?php endif; ?>
            </div>
        </div>
        <form method="post" class="p-6">
            <input type="hidden" name="action" value="save_google_settings">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="inline-flex items-center cursor-pointer select-none mb-4">
                        <input type="checkbox" name="google_enabled" class="sr-only" <?= $googleEnabled ? 'checked' : '' ?>>
                        <span class="w-12 h-7 flex items-center bg-gray-300 rounded-full p-1 transition-all">
                            <span class="w-5 h-5 bg-white rounded-full shadow transform transition-transform <?= $googleEnabled ? 'translate-x-5' : '' ?>"></span>
                        </span>
                        <span class="ml-3 text-sm text-gray-800">Aktivera Google‑inloggning</span>
                    </label>

                    <label for="google_client_id" class="block text-sm font-medium text-gray-700 mb-1">Google Client ID</label>
                    <input id="google_client_id" name="google_client_id" type="text" class="w-full border rounded-lg px-3 py-2" placeholder="1234567890-abcdefg.apps.googleusercontent.com" value="<?= htmlspecialchars($googleClientId) ?>" />
                    <p class="text-xs text-gray-500 mt-2">Använd ditt <em>OAuth 2.0 Client ID (Web)</em> från Google Cloud Console.</p>
                </div>
                <div>
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-sm text-gray-700">
                        <p class="mb-2"><strong>Instruktion:</strong></p>
                        <ol class="list-decimal ml-5 space-y-1">
                            <li>Gå till Google Cloud Console → Credentials → Skapa/öppna <em>OAuth 2.0 Client ID (Web)</em>.</li>
                            <li>Lägg till dina domäner/redirect‑URI: <code class="bg-gray-100 px-1 rounded">/auth/google-callback.php</code> (om du har en sådan), samt din inloggningssida där knappen finns.</li>
                            <li>Kopiera <em>Client ID</em> hit. Vi använder det för att verifiera <code>aud</code> i ID‑token.</li>
                        </ol>
                        <p class="mt-3">UI:t för Google‑knappen bör bara visas när denna funktion är aktiverad.</p>
                    </div>
                </div>
            </div>
            <div class="mt-6">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg inline-flex items-center">
                    <i class="fa-solid fa-floppy-disk mr-2"></i>
                    Spara
                </button>
            </div>
        </form>
    </div>

    <!-- Kort: Kontaktuppgifter -->
    <div id="contact" class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden mb-8">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                <i class="fa-solid fa-address-card text-sky-700 mr-2"></i>
                Kontaktuppgifter
            </h2>
            <div class="flex items-center gap-2">
                <?php if ($contactSupport || $contactGeneral || $contactPhone): ?>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Konfigurerad</span>
                <?php else: ?>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">Tom</span>
                <?php endif; ?>
            </div>
        </div>
        <form method="post" class="p-6">
            <input type="hidden" name="action" value="save_contact_settings">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="md:col-span-1">
                    <label for="contact_support_email" class="block text-sm font-medium text-gray-700 mb-1">Support e‑post</label>
                    <input id="contact_support_email" name="contact_support_email" type="email" class="w-full border rounded-lg px-3 py-2 mb-4" placeholder="support@example.se" value="<?= htmlspecialchars($contactSupport) ?>" />

                    <label for="contact_general_email" class="block text-sm font-medium text-gray-700 mb-1">Allmänna frågor e‑post</label>
                    <input id="contact_general_email" name="contact_general_email" type="email" class="w-full border rounded-lg px-3 py-2 mb-4" placeholder="info@example.se" value="<?= htmlspecialchars($contactGeneral) ?>" />

                    <label for="contact_phone" class="block text-sm font-medium text-gray-700 mb-1">Telefonnummer</label>
                    <input id="contact_phone" name="contact_phone" type="text" class="w-full border rounded-lg px-3 py-2" placeholder="08‑123 45 67" value="<?= htmlspecialchars($contactPhone) ?>" />
                </div>
                <div class="md:col-span-2">
                    <label for="contact_info_text" class="block text-sm font-medium text-gray-700 mb-1">Fritext / ytterligare info</label>
                    <textarea id="contact_info_text" name="contact_info_text" rows="8" class="w-full border rounded-lg p-3 text-sm" placeholder="Öppettider, länkar, hur support är organiserad, m.m."><?= htmlspecialchars($contactInfo) ?></textarea>
                    <p class="text-xs text-gray-500 mt-2">Detta fält kan visas i t.ex. footer eller på en /kontakt‑sida.</p>
                </div>
            </div>
            <div class="mt-6">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg inline-flex items-center">
                    <i class="fa-solid fa-floppy-disk mr-2"></i>
                    Spara
                </button>
            </div>
        </form>
    </div>

    <!-- Kort: Registrering -->
    <div class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                <i class="fa-solid fa-user-plus text-green-500 mr-2"></i>
                Användarregistrering
            </h2>
            <div>
                <?php if ($allowSelfRegistration): ?>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Tillåten</span>
                <?php else: ?>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">Avstängd</span>
                <?php endif; ?>
            </div>
        </div>
        <form method="post" class="p-6">
            <input type="hidden" name="action" value="save_registration_setting">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="max-w-2xl">
                    <h3 class="text-gray-800 font-medium mb-1">Tillåt användare att registrera sig själva</h3>
                    <p class="text-gray-600 text-sm">När detta är på kan nya användare skapa ett konto via registreringssidan. När det är av måste administratörer skapa konton manuellt.</p>
                </div>
                <label class="inline-flex items-center cursor-pointer select-none">
                    <input type="checkbox" name="allow_self_registration" class="sr-only" <?= $allowSelfRegistration ? 'checked' : '' ?>>
                    <span class="w-12 h-7 flex items-center bg-gray-300 rounded-full p-1 transition-all">
                        <span class="w-5 h-5 bg-white rounded-full shadow transform transition-transform <?= $allowSelfRegistration ? 'translate-x-5' : '' ?>"></span>
                    </span>
                    <span class="ml-3 text-sm text-gray-800"><?= $allowSelfRegistration ? 'På' : 'Av' ?></span>
                </label>
            </div>
            <div class="mt-6 flex items-center gap-3">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fa-solid fa-floppy-disk mr-2"></i>
                    Spara
                </button>
                <button type="button" onclick="window.location.reload()" class="bg-gray-100 hover:bg-gray-200 text-gray-800 px-4 py-2 rounded-lg">
                    Återställ vy
                </button>
            </div>
        </form>
    </div>

    <!-- Kort: Skapande av whiteboards -->
    <div id="wb" class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden mt-8">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                <i class="fa-solid fa-chalkboard text-indigo-500 mr-2"></i>
                Skapande av whiteboards
            </h2>
            <div class="flex items-center gap-2">
                <?php if ($requireLoginWhiteboard): ?>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800" title="Inloggning krävs">Inloggning krävs</span>
                <?php else: ?>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800" title="Gäster tillåtna">Gäster tillåtna</span>
                <?php endif; ?>
                <?php if (trim($allowedWbCidrsRaw) !== ''): ?>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800" title="IP-låsning aktiv">IP-låsning</span>
                <?php else: ?>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-600" title="Ingen IP-låsning">Ingen IP-låsning</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Del 1: Kräver inloggning -->
        <form method="post" class="p-6 border-b border-gray-100">
            <input type="hidden" name="action" value="save_whiteboard_login_requirement">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="max-w-2xl">
                    <h3 class="text-gray-800 font-medium mb-1">Kräv inloggning för att skapa whiteboard</h3>
                    <p class="text-gray-600 text-sm">När detta är på kan endast inloggade användare skapa nya whiteboards. Befintliga whiteboards påverkas inte.</p>
                </div>
                <label class="inline-flex items-center cursor-pointer select-none">
                    <input type="checkbox" name="require_login_whiteboard" class="sr-only" <?= $requireLoginWhiteboard ? 'checked' : '' ?>>
                    <span class="w-12 h-7 flex items-center bg-gray-300 rounded-full p-1 transition-all">
                        <span class="w-5 h-5 bg-white rounded-full shadow transform transition-transform <?= $requireLoginWhiteboard ? 'translate-x-5' : '' ?>"></span>
                    </span>
                    <span class="ml-3 text-sm text-gray-800"><?= $requireLoginWhiteboard ? 'På' : 'Av' ?></span>
                </label>
            </div>
            <div class="mt-6">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fa-solid fa-floppy-disk mr-2"></i>
                    Spara
                </button>
            </div>
        </form>

        <!-- Del 2: IP-låsning -->
        <form method="post" class="p-6">
            <input type="hidden" name="action" value="save_whiteboard_ip_lock">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h3 class="text-gray-800 font-medium mb-1">Begränsa skapande till specifika IP / CIDR</h3>
                    <p class="text-gray-600 text-sm">Ange en lista med tillåtna adresser och/eller CIDR-intervall, <strong>en per rad</strong>. Exempel:<br>
                        <code class="bg-gray-100 px-1 py-0.5 rounded text-xs">192.168.0.0/24</code>, <code class="bg-gray-100 px-1 py-0.5 rounded text-xs">10.10.10.15</code>, <code class="bg-gray-100 px-1 py-0.5 rounded text-xs">2001:db8::/48</code>
                    </p>
                    <p class="text-gray-600 text-sm mt-2">Lämna tomt för att tillåta skapande från alla IP (om inte inloggningskrav är aktiverat).</p>
                </div>
                <div>
                    <label for="whiteboard_allowed_cidrs" class="block text-sm font-medium text-gray-700 mb-1">Tillåtna IP/CIDR</label>
                    <textarea id="whiteboard_allowed_cidrs" name="whiteboard_allowed_cidrs" rows="8" class="w-full border rounded-lg p-3 font-mono text-sm" placeholder="Ex:\n192.0.2.0/24\n203.0.113.7\n2001:db8::/32"><?= htmlspecialchars($allowedWbCidrsRaw) ?></textarea>
                </div>
            </div>
            <div class="mt-6">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fa-solid fa-floppy-disk mr-2"></i>
                    Spara
                </button>
            </div>
        </form>
    </div>

    <!-- Tips på implementation -->
    <div class="mt-8 text-sm text-gray-600">
        <h3 class="font-semibold text-gray-800 mb-2">Att implementera i koden</h3>
        <ul class="list-disc ml-5 space-y-1">
            <li>På <code>/register.php</code> (eller motsv.) bör en guard läsas: om <code>allow_self_registration</code> = 0, visa 403/redirect till inloggning.</li>
            <li>Admin‑flöden för manuell skapning påverkas inte och kan alltid användas av <code>role = 'admin'</code>.</li>
        </ul>
    </div>
</div>

<?php if (isset($_GET['saved']) || isset($_GET['smtp_test'])): ?>
<script>
    window.addEventListener('DOMContentLoaded', () => {
        <?php if (isset($_GET['saved'])): ?>
        showNotification('Inställning sparad', 'success');
        <?php endif; ?>
        <?php if (isset($_GET['smtp_test'])): ?>
        <?php if ($_GET['smtp_test'] === 'ok'): ?>
        showNotification('Testmejl skickat!', 'success');
        <?php else: ?>
        showNotification('<?= isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : 'Kunde inte skicka testmejl' ?>', 'error');
        <?php endif; ?>
        <?php endif; ?>
    });
</script>
<?php endif; ?>

<script>
// Enkel notifiering (återanvändbar på adminsidor)
function showNotification(message, type = 'success') {
    let el = document.getElementById('notification');
    if (el) el.remove();
    el = document.createElement('div');
    el.id = 'notification';
    el.className = 'fixed bottom-4 right-4 px-6 py-3 rounded-lg text-white shadow-lg transition-all duration-300 flex items-center';
    el.classList.add(type === 'success' ? 'bg-green-600' : (type === 'error' ? 'bg-red-600' : 'bg-blue-600'));
    el.innerHTML = `<i class="fa-solid ${type==='success'?'fa-check-circle':(type==='error'?'fa-exclamation-circle':'fa-info-circle')} mr-2"></i><span>${message}</span>`;
    document.body.appendChild(el);
    setTimeout(() => { el.classList.add('opacity-0', 'translate-y-3'); setTimeout(() => el.remove(), 300); }, 3500);
}
</script>

<script>
// Gör toggle-switchar interaktiva (visuell animation) och autospara vissa
window.addEventListener('DOMContentLoaded', () => {
  const toggles = document.querySelectorAll('label.inline-flex input[type="checkbox"].sr-only');
  toggles.forEach(input => {
    const knob = input.parentElement?.querySelector('span > span'); // inner cirkel
    const name = input.getAttribute('name') || '';
    const autoSubmitNames = new Set(['allow_self_registration','require_login_whiteboard']);

    // Init: säkerställ rätt visuellt läge (om servern renderade fel klass)
    if (knob) {
      knob.classList.toggle('translate-x-5', input.checked);
    }

    input.addEventListener('change', () => {
      if (knob) {
        knob.classList.toggle('translate-x-5', input.checked);
      }
      // Autosubmit endast för enkla toggles som inte har fler fält i samma formulär
      if (autoSubmitNames.has(name)) {
        const form = input.closest('form');
        if (form) {
          // Lägg till en liten indikator
          try {
            showNotification('Sparar…', 'info');
          } catch(e) {}
          form.submit();
        }
      }
    });
  });
});
</script>

</body>
</html>
