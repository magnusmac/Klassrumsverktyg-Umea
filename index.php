<?php
session_start();
require_once __DIR__ . '/src/Config/Database.php';


$db = new Database();
$pdo = $db->getConnection();
// Hämta instansens namn från system_settings (fallback: "Klassrumsverktyg")
function get_setting(PDO $pdo, string $key, $default = null) {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['setting_value'] ?? $default;
}

function get_client_ip(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function ip_in_cidrs(string $ip, array $cidrs): bool {
    foreach ($cidrs as $cidr) {
        $cidr = trim($cidr);
        if ($cidr === '') continue;
        if (strpos($cidr, '/') === false) {
            if (strcasecmp($ip, $cidr) === 0) return true; // exakt IP
            continue;
        }
        // IPv4 CIDR
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($cidr, ':') === false) {
            [$subnet, $mask] = explode('/', $cidr, 2);
            if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
            $mask = (int)$mask;
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $maskLong = -1 << (32 - $mask);
            if (($ipLong & $maskLong) === ($subnetLong & $maskLong)) return true;
        }
        // IPv6 CIDR
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && strpos($cidr, ':') !== false) {
            [$subnet, $mask] = explode('/', $cidr, 2);
            $mask = (int)$mask;
            $ipBin     = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) continue;
            $bytes = intdiv($mask, 8);
            $bits  = $mask % 8;
            if (strncmp($ipBin, $subnetBin, $bytes) !== 0) continue;
            if ($bits === 0) return true;
            $ipByte     = ord($ipBin[$bytes]);
            $subnetByte = ord($subnetBin[$bytes]);
            $maskByte   = ~((1 << (8 - $bits)) - 1) & 0xFF;
            if (($ipByte & $maskByte) === ($subnetByte & $maskByte)) return true;
        }
    }
    return false;
}

$siteName = (string) get_setting($pdo, 'site_name', 'Klassrumsverktyg');

$requireLoginForCreation = get_setting($pdo, 'require_login_for_whiteboard_creation', '0') === '1';
$allowedCidrsRaw = (string) get_setting($pdo, 'allowed_whiteboard_creator_ip_ranges', '');
$allowedCidrs = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $allowedCidrsRaw)));

function generateUniqueCode() {
    $characters = '23456789abcdefghijkmnpqrstuvwxyz'; // Undviker förvirrande tecken som 1/l, 0/O
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

// Generera en unik kod som inte redan finns
function createUniqueWhiteboardCode($pdo) {
    do {
        $code = generateUniqueCode();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM whiteboards WHERE board_code = ?");
        $stmt->execute([$code]);
    } while ($stmt->fetchColumn() > 0);
    return $code;
}

// Hantera skapande av ny whiteboard
if (isset($_POST['create_whiteboard'])) {
    // Policyskydd: kräver inloggning?
    if ($requireLoginForCreation && empty($_SESSION['user_id'])) {
        header('Location: /login?r=whiteboard_login_required');
        http_response_code(302);
        exit;
    }
    // Policyskydd: IP-begränsning (om satt)
    if (!empty($allowedCidrs)) {
        $ip = get_client_ip();
        if (!ip_in_cidrs($ip, $allowedCidrs)) {
            header('Location: /?error=whiteboard_ip_blocked');
            http_response_code(302);
            exit;
        }
    }
    $boardCode = createUniqueWhiteboardCode($pdo);
    $userId = $_SESSION['user_id'] ?? null; // Use null for guest users
    
    // Sätt utgångsdatum baserat på om användaren är inloggad eller inte
    $expiresAt = null;
    if ($userId) {
        $expiresAt = date('Y-m-d H:i:s', strtotime('+365 days'));
    } else {
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO whiteboards (
            user_id, 
            board_code, 
            name,
            expires_at,
            is_active,
            created_at,
            last_used
        ) VALUES (
            ?, ?, ?, ?, 1, NOW(), NOW()
        )
    ");
    $name = "Whiteboard " . $boardCode;
    $stmt->execute([$userId, $boardCode, $name, $expiresAt]);
    
    header("Location: /whiteboard.php?board=" . $boardCode);
    exit;
}

// Hämta användarens whiteboards om inloggad
$userWhiteboards = [];
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("
        SELECT * 
        FROM whiteboards 
        WHERE user_id = ? 
        AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY last_used DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $userWhiteboards = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($siteName) ?> - Digital Whiteboard</title>
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link href="/assets/vendor/tailwind/tailwind.min.css" rel="stylesheet">
    <script src="/assets/vendor/lucide/lucide.min.js"></script>
</head>
<body class="flex flex-col min-h-screen bg-gray-100">
  <!-- Header -->
  <?php include 'header.php'; ?>


<!-- Main Content -->
<main class="flex-grow container mx-auto px-6 py-16">
    <div class="max-w-4xl mx-auto grid grid-cols-1 md:grid-cols-2 gap-12 items-center">
        <!-- Text Content -->
        <div class="text-left">
            <h1 class="text-5xl font-extrabold text-gray-900 mb-6">
                Gör dina lektioner tydligare
            </h1>
            <p class="text-lg text-gray-700 mb-6">
                Skapa en digital whiteboard med anpassade widgets för att hjälpa dina elever att följa med. Perfekt för projektorer och klassrumsmiljöer.
            </p>
            <div class="flex space-x-4">
                <?php $canCreate = !$requireLoginForCreation || isset($_SESSION['user_id']); ?>
                <?php if ($canCreate) : ?>
                <form method="POST" target="_blank">
                    <button type="submit" name="create_whiteboard" 
                            class="bg-blue-600 text-white px-12 py-5 rounded-full text-lg font-semibold shadow-md hover:bg-blue-700 transition-all duration-300 whitespace-nowrap">
                        Skapa Whiteboard
                    </button>
                </form>
                <?php else: ?>
                <a href="/login.php?r=whiteboard_login_required" class="bg-blue-600 text-white px-12 py-5 rounded-full text-lg font-semibold shadow-md hover:bg-blue-700 transition-all duration-300 whitespace-nowrap">Logga in för att skapa</a>
                <?php endif; ?>
                <a href="/static/about.php" 
                   class="bg-gray-200 text-gray-800 px-8 py-4 rounded-full text-lg font-semibold 
                          hover:bg-gray-300 transition-all duration-300">
                    Läs mer
                </a>
            </div>
        </div>

        <!-- Image Content -->
        <div class="flex justify-center">
            <img src="/images/screenshot.webp" alt="Digital Whiteboard" class="w-full max-w-lg rounded-lg shadow-lg">
        </div>
    </div>
</main>

<!-- Features Section with Bento Grid -->
<section class="container mx-auto px-6 py-16">
    <h2 class="text-4xl font-extrabold text-gray-900 text-center mb-12">Utforska Funktionerna</h2>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h3 class="text-xl font-semibold mb-2">Anpassade Widgets</h3>
            <p class="text-gray-600">Lägg till klockor, timers och andra verktyg för att hjälpa eleverna att hålla fokus.</p>
        </div>
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h3 class="text-xl font-semibold mb-2">Lösenordsskydd</h3>
            <p class="text-gray-600">Skydda din whiteboard med ett lösenord och dela den endast med rätt personer.</p>
        </div>
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h3 class="text-xl font-semibold mb-2">Ingen Spårning</h3>
            <p class="text-gray-600">Vi värnar om din integritet – ingen data samlas in utan ditt godkännande.</p>
        </div>
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h3 class="text-xl font-semibold mb-2">Projektorvänlig</h3>
            <p class="text-gray-600">Designad för att fungera sömlöst på stora skärmar och projektorer.</p>
        </div>
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h3 class="text-xl font-semibold mb-2">Snabb & Enkel</h3>
            <p class="text-gray-600">Starta en ny whiteboard på några sekunder – ingen installation krävs.</p>
        </div>
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h3 class="text-xl font-semibold mb-2">Körs lokalt</h3>
            <p class="text-gray-600">Tjänsten drivs lokalt av din organisation och lagrar ingen data externt.</p>
        </div>
    </div>
</section>

<div id="cookie-banner" class="fixed bottom-0 left-0 w-full bg-gray-900 text-gray-300 py-4 px-6 z-50">
        <div class="container mx-auto flex justify-between items-center">
            <p class="text-sm">Den här webbplatsen använder cookies för att förbättra din upplevelse. Genom att fortsätta använda webbplatsen godkänner du vår användning av cookies. <a href="/static/privacy.php" class="text-blue-500 hover:underline">Läs vår integritetspolicy</a>.</p>
            <button id="accept-cookies" class="bg-blue-600 text-white py-2 px-4 rounded-md text-sm hover:bg-blue-700 focus:outline-none">Okej, jag förstår</button>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const cookieBanner = document.getElementById('cookie-banner');
            const acceptCookiesButton = document.getElementById('accept-cookies');
            const cookieName = 'cookieConsent';

            function setCookie(name, value, days) {
                const date = new Date();
                date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                const expires = "expires=" + date.toUTCString();
                document.cookie = name + "=" + value + ";" + expires + ";path=/";
            }

            function getCookie(name) {
                const nameEQ = name + "=";
                const ca = document.cookie.split(';');
                for(let i = 0; i < ca.length; i++) {
                    let c = ca[i];
                    while (c.charAt(0) === ' ') c = c.substring(1, c.length);
                    if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
                }
                return null;
            }

            if (getCookie(cookieName)) {
                cookieBanner.style.display = 'none';
            } else {
                cookieBanner.style.display = 'block';
            }

            acceptCookiesButton.addEventListener('click', function() {
                setCookie(cookieName, 'true', 365); // Spara godkännandet i 365 dagar
                cookieBanner.style.display = 'none';
            });
        });
    </script>

<?php include 'footer.php'; ?>

</body>
</html>