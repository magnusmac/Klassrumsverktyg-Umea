<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../src/Config/Database.php';
require_once 'AdminController.php';

// Hantera manuell aktivering av rensningsskriptet
if (isset($_POST['trigger_cleanup']) && $_POST['trigger_cleanup'] === '1') {
    $output = [];
    $return_var = 0;
    // Kör cron_cleanup.php på ett portabelt sätt
    $cronPath = realpath(__DIR__ . '/../cron/cron_cleanup.php');
    $phpBin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    exec(escapeshellcmd($phpBin) . ' ' . escapeshellarg($cronPath) . ' 2>&1', $output, $return_var);
    $success = $return_var === 0;
    $message = $success ? 'Rensningsskriptet kördes framgångsrikt!' : 'Ett fel uppstod vid körning av rensningsskriptet.';
    // Spara resultat för visning
    $_SESSION['cleanup_result'] = [
        'success' => $success,
        'message' => $message,
        'output' => $output,
        'time' => date('Y-m-d H:i:s')
    ];
    // Omdirigera för att undvika omladdning vid uppdatering
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$database = new Database();
$db = $database->getConnection();
// Hitta loggkatalog (samma logik som cron_cleanup.php)
function kv_get_log_dir(): string {
    // 1) Projektets /logs
    $projectRoot = realpath(__DIR__ . '/../') ?: dirname(__DIR__);
    $dir = rtrim($projectRoot . '/logs', '/');
    if (is_dir($dir) && is_readable($dir)) return $dir;
    // 2) Fallback till /tmp
    $tmp = rtrim(sys_get_temp_dir() . '/klassrumsverktyg_logs', '/');
    if (is_dir($tmp) && is_readable($tmp)) return $tmp;
    // 3) Om inget hittas, returnera projektets tänkta sökväg ändå (visning hanterar avsaknad)
    return $dir;
}
// Läs instansens namn från system_settings (fallback: "Klassrumsverktyg")
if (!function_exists('kv_get_setting')) {
    function kv_get_setting(PDO $pdo, string $key, $default = null) {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['setting_value'] ?? $default;
    }
}
$siteName = (string) kv_get_setting($db, 'site_name', 'Klassrumsverktyg');
$admin = new AdminController($db);
$stats = $admin->getWhiteboardStats();

// Hämta information om rensningsloggen
$logDir = kv_get_log_dir();
$summaryFile = $logDir . '/cleanup_summary.json';
$hasRunBefore = is_file($summaryFile);

if ($hasRunBefore) {
    $raw = @file_get_contents($summaryFile);
    $summary = $raw ? json_decode($raw, true) : null;
    if (is_array($summary) && !empty($summary['last_run'])) {
        $lastRun = new DateTime($summary['last_run']);
        $now = new DateTime();
        $diff = $lastRun->diff($now);
        if ($diff->days > 0) {
            $timeAgo = $diff->days . ' dagar sedan';
        } elseif ($diff->h > 0) {
            $timeAgo = $diff->h . ' timmar sedan';
        } else {
            $timeAgo = $diff->i . ' minuter sedan';
        }
    } else {
        $hasRunBefore = false; // ogiltig/korrupt summary
    }
}

$recentLogs = [];
// Försök hitta senaste månadsloggen (cleanup_YYYY-MM.log), annars fallback till legacy cleanup.log
$pattern = rtrim($logDir, '/') . '/cleanup_*.log';
$files = glob($pattern);
if ($files) {
    rsort($files, SORT_STRING);
    $latestLog = $files[0];
} else {
    $latestLog = rtrim($logDir, '/') . '/cleanup.log';
}
if (is_file($latestLog) && is_readable($latestLog)) {
    $logs = @file($latestLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($logs !== false) {
        $recentLogs = array_slice($logs, -10);
    }
}

// Hämta senaste registrerade användare
$recentUsers = $admin->getRecentUsers(5);

// Hämta senaste skapade whiteboards
$recentWhiteboards = $admin->getRecentWhiteboards(5);

// Hämta ytterligare statistik från admin-controller
function getExtendedStats($db) {
    // Totalt antal användare
    $userQuery = "SELECT COUNT(*) as total, 
                  SUM(CASE WHEN role = 'teacher' THEN 1 ELSE 0 END) as teachers,
                  SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins,
                  SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users
                  FROM users";
    $userStmt = $db->prepare($userQuery);
    $userStmt->execute();
    $userResult = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    // Whiteboard-statistik
    $whiteboardQuery = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN background_type = 'color' THEN 1 ELSE 0 END) as color_backgrounds,
                        SUM(CASE WHEN background_type = 'image' THEN 1 ELSE 0 END) as image_backgrounds,
                        SUM(CASE WHEN password IS NOT NULL THEN 1 ELSE 0 END) as password_protected
                        FROM whiteboards";
    $whiteboardStmt = $db->prepare($whiteboardQuery);
    $whiteboardStmt->execute();
    $whiteboardResult = $whiteboardStmt->fetch(PDO::FETCH_ASSOC);
    
    // Widget-statistik
    $widgetQuery = "SELECT COUNT(*) as total,
                   type, COUNT(*) as type_count
                   FROM widgets
                   GROUP BY type
                   ORDER BY type_count DESC";
    $widgetStmt = $db->prepare($widgetQuery);
    $widgetStmt->execute();
    $widgetTypes = $widgetStmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'users' => $userResult,
        'whiteboards' => $whiteboardResult,
        'widget_types' => $widgetTypes
    ];
}

$extendedStats = getExtendedStats($db);
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?= htmlspecialchars($siteName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/vendor/fontawesome/all.min.css">
</head>
<body class="bg-gray-100">
    
<?php include_once 'nav.php'; ?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800">Admin Dashboard</h1>
        <span class="text-sm text-gray-500">Senast uppdaterad: <?= date('Y-m-d H:i') ?></span>
    </div>
    
    <!-- Visning av rensningsresultat om det finns -->
    <?php if (isset($_SESSION['cleanup_result'])): ?>
    <div class="mb-6 p-4 rounded-lg border <?= $_SESSION['cleanup_result']['success'] ? 'bg-green-100 border-green-200' : 'bg-red-100 border-red-200' ?>">
        <div class="flex items-center">
            <?php if ($_SESSION['cleanup_result']['success']): ?>
                <i class="fas fa-check-circle text-green-500 mr-2"></i>
            <?php else: ?>
                <i class="fas fa-exclamation-circle text-red-500 mr-2"></i>
            <?php endif; ?>
            <div>
                <p class="font-medium"><?= $_SESSION['cleanup_result']['message'] ?></p>
                <p class="text-sm text-gray-600 mt-1">Körd: <?= $_SESSION['cleanup_result']['time'] ?></p>
            </div>
        </div>
    </div>
    <?php unset($_SESSION['cleanup_result']); ?>
    <?php endif; ?>
    
    <!-- Översiktsstatistik -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Whiteboards -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-blue-500">
            <div class="flex justify-between items-center">
                <div class="text-sm font-medium text-gray-500">Totalt antal whiteboards</div>
                <i class="fas fa-chalkboard text-blue-500"></i>
            </div>
            <div class="mt-2 text-3xl font-semibold"><?= $stats['total_whiteboards'] ?></div>
            <div class="text-sm text-gray-500 mt-2">
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-blue-500 h-2 rounded-full" style="width: <?= round(($stats['active_whiteboards'] / max(1, $stats['total_whiteboards'])) * 100) ?>%"></div>
                </div>
                <div class="flex justify-between mt-1">
                    <span><?= $stats['active_whiteboards'] ?> aktiva</span>
                    <span><?= $extendedStats['whiteboards']['password_protected'] ?> lösenordsskyddade</span>
                </div>
            </div>
        </div>

        <!-- Användare -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-green-500">
            <div class="flex justify-between items-center">
                <div class="text-sm font-medium text-gray-500">Totalt antal användare</div>
                <i class="fas fa-users text-green-500"></i>
            </div>
            <div class="mt-2 text-3xl font-semibold"><?= $extendedStats['users']['total'] ?></div>
            <div class="text-sm text-gray-500 mt-2 flex justify-between">
                <span><?= $extendedStats['users']['teachers'] ?> lärare</span>
                <span><?= $extendedStats['users']['admins'] ?> administratörer</span>
            </div>
        </div>
        
        <!-- Användare nära gränsen -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-yellow-500">
            <div class="flex justify-between items-center">
                <div class="text-sm font-medium text-gray-500">Användare nära gränsen</div>
                <i class="fas fa-exclamation-triangle text-yellow-500"></i>
            </div>
            <div class="mt-2 text-3xl font-semibold"><?= $stats['users_near_limit'] ?></div>
            <div class="text-sm text-gray-500 mt-2">
                Användare som använt >90% av sin kvot
            </div>
        </div>

        <!-- Widgets -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-purple-500">
            <div class="flex justify-between items-center">
                <div class="text-sm font-medium text-gray-500">Widgets</div>
                <i class="fas fa-puzzle-piece text-purple-500"></i>
            </div>
            <div class="mt-2 text-3xl font-semibold"><?= array_sum(array_column($extendedStats['widget_types'], 'type_count')) ?></div>
            <div class="text-sm text-gray-500 mt-2">
                <?php
                $topWidgets = array_slice($extendedStats['widget_types'], 0, 2);
                foreach ($topWidgets as $widget) {
                    echo '<div>' . htmlspecialchars($widget['type']) . ': ' . $widget['type_count'] . '</div>';
                }
                ?>
            </div>
        </div>
    </div>
    
    <!-- Innehåll i två kolumner -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
        <!-- Snabbåtgärder (vänster kolumn) -->
        <div class="bg-white rounded-lg shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-xl font-semibold text-gray-800">Snabbåtgärder</h2>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <a href="/admin/users.php" class="block p-4 border rounded-lg hover:bg-gray-50 transition">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-user-cog text-blue-500 mr-3"></i>
                            </div>
                            <div>
                                <div class="font-medium">Hantera användare</div>
                                <div class="text-sm text-gray-500">Lägg till, redigera eller ta bort användare</div>
                            </div>
                        </div>
                    </a>
                    <a href="/admin/whiteboards.php" class="block p-4 border rounded-lg hover:bg-gray-50 transition">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-chalkboard text-green-500 mr-3"></i>
                            </div>
                            <div>
                                <div class="font-medium">Hantera whiteboards</div>
                                <div class="text-sm text-gray-500">Se och hantera alla whiteboards</div>
                            </div>
                        </div>
                    </a>
                    <a href="/admin/limits.php" class="block p-4 border rounded-lg hover:bg-gray-50 transition">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-sliders-h text-purple-500 mr-3"></i>
                            </div>
                            <div>
                                <div class="font-medium">Whiteboard-begränsningar</div>
                                <div class="text-sm text-gray-500">Ställ in begränsningar för användare</div>
                            </div>
                        </div>
                    </a>
                    <a href="/admin/stats.php" class="block p-4 border rounded-lg hover:bg-gray-50 transition">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-chart-bar text-indigo-500 mr-3"></i>
                            </div>
                            <div>
                                <div class="font-medium">Systemstatistik</div>
                                <div class="text-sm text-gray-500">Se detaljerad statistik över systemet</div>
                            </div>
                        </div>
                    </a>
                </div>
                
                <!-- Systemdiagnostik -->
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <h3 class="text-sm font-medium text-gray-700 mb-3">Systemdiagnostik</h3>
                    <div class="grid grid-cols-3 gap-3">
                        <!-- PHP Version -->
                        <div class="bg-gray-50 rounded-lg p-3 border border-gray-200">
                            <div class="text-xs font-medium text-gray-500 uppercase mb-1">PHP Version</div>
                            <div class="text-base font-semibold"><?= phpversion() ?></div>
                        </div>
                        
                        <!-- MySQL Version -->
                        <div class="bg-gray-50 rounded-lg p-3 border border-gray-200">
                            <div class="text-xs font-medium text-gray-500 uppercase mb-1">MySQL</div>
                            <?php
                            $mysqlVersionQuery = "SELECT VERSION() as version";
                            $stmt = $db->prepare($mysqlVersionQuery);
                            $stmt->execute();
                            $mysqlRow = $stmt->fetch(PDO::FETCH_ASSOC);
                            $mysqlVersion = $mysqlRow['version'] ?? 'okänd';
                            ?>
                            <div class="text-base font-semibold"><?= $mysqlVersion ?></div>
                        </div>
                        
                        <!-- Servertid -->
                        <div class="bg-gray-50 rounded-lg p-3 border border-gray-200">
                            <div class="text-xs font-medium text-gray-500 uppercase mb-1">Servertid</div>
                            <div class="text-base font-semibold"><?= date('H:i:s') ?></div>
                            <div class="text-xs text-gray-500"><?= date('Y-m-d') ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Aktivitet och Senaste (höger, tar 2 kolumner) -->
        <div>
            <!-- Aktivitetsflikar -->
            <div class="bg-white rounded-lg shadow mb-8">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h2 class="text-xl font-semibold text-gray-800">Senaste aktivitet</h2>
                </div>
                <div class="p-6">
                    <!-- Fliknavigation -->
                    <div class="border-b border-gray-200 mb-4">
                        <ul class="flex flex-wrap -mb-px" id="activityTabs" role="tablist">
                            <li class="mr-2" role="presentation">
                                <button class="inline-block py-2 px-4 border-b-2 border-blue-500 text-blue-600 font-medium text-sm focus:outline-none" 
                                        id="cleanups-tab" 
                                        data-tabs-target="#cleanups" 
                                        type="button" 
                                        role="tab" 
                                        aria-controls="cleanups" 
                                        aria-selected="true">
                                    Rensning
                                </button>
                            </li>
                            <li class="mr-2" role="presentation">
                                <button class="inline-block py-2 px-4 border-b-2 border-transparent text-gray-500 hover:text-gray-600 hover:border-gray-300 font-medium text-sm focus:outline-none" 
                                        id="users-tab" 
                                        data-tabs-target="#users" 
                                        type="button" 
                                        role="tab" 
                                        aria-controls="users" 
                                        aria-selected="false">
                                    Användare
                                </button>
                            </li>
                            <li class="mr-2" role="presentation">
                                <button class="inline-block py-2 px-4 border-b-2 border-transparent text-gray-500 hover:text-gray-600 hover:border-gray-300 font-medium text-sm focus:outline-none" 
                                        id="whiteboards-tab" 
                                        data-tabs-target="#whiteboards" 
                                        type="button" 
                                        role="tab" 
                                        aria-controls="whiteboards" 
                                        aria-selected="false">
                                    Whiteboards
                                </button>
                            </li>
                        </ul>
                    </div>
                    
                    <!-- Innehåll för flikarna -->
                    <div id="activityTabContent">
                        <!-- Rensningsaktivitet -->
                        <div class="block" id="cleanups" role="tabpanel" aria-labelledby="cleanups-tab">
                            <div class="space-y-4">
                                <?php if (!empty($recentLogs)): ?>
                                <div class="text-sm">
                                    <div class="mb-2 font-medium">Senaste rensningsloggar:</div>
                                    <div class="bg-gray-50 p-3 rounded-lg max-h-48 overflow-y-auto text-xs font-mono">
                                        <?php foreach ($recentLogs as $log): ?>
                                            <?php 
                                                $logClass = "text-gray-800";
                                                if (strpos($log, "[ERROR]") !== false) {
                                                    $logClass = "text-red-600 font-semibold";
                                                } elseif (strpos($log, "[WARNING]") !== false) {
                                                    $logClass = "text-amber-600";
                                                } elseif (strpos($log, "[SUMMARY]") !== false) {
                                                    $logClass = "text-green-700 font-medium";
                                                }
                                            ?>
                                            <div class="py-1 border-b border-gray-200 <?= $logClass ?>"><?= htmlspecialchars($log) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="text-gray-500 text-sm italic">
                                    Inga loggposter hittades ännu.
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Användaraktivitet -->
                        <div class="hidden" id="users" role="tabpanel" aria-labelledby="users-tab">
                            <div class="space-y-4">
                                <?php if (!empty($recentUsers)): ?>
                                <div class="text-sm">
                                    <div class="mb-2 font-medium">Senast registrerade användare:</div>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full bg-white">
                                            <thead>
                                                <tr class="bg-gray-100 text-gray-600 uppercase text-xs">
                                                    <th class="py-2 px-4 text-left">Användarnamn</th>
                                                    <th class="py-2 px-4 text-left">E‑post</th>
                                                    <th class="py-2 px-4 text-left">Roll</th>
                                                    <th class="py-2 px-4 text-left">Registrerad</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recentUsers as $user): ?>
                                                <tr class="border-b hover:bg-gray-50">
                                                    <td class="py-2 px-4"><?= htmlspecialchars($user['username']) ?></td>
                                                    <td class="py-2 px-4"><?= htmlspecialchars($user['email']) ?></td>
                                                    <td class="py-2 px-4"><?= htmlspecialchars($user['role']) ?></td>
                                                    <td class="py-2 px-4"><?= date('Y-m-d H:i', strtotime($user['created_at'])) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="text-gray-500 text-sm italic">
                                    Inga nya användare hittades.
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Whiteboard-aktivitet -->
                        <div class="hidden" id="whiteboards" role="tabpanel" aria-labelledby="whiteboards-tab">
                            <div class="space-y-4">
                                <?php if (!empty($recentWhiteboards)): ?>
                                <div class="text-sm">
                                    <div class="mb-2 font-medium">Senast skapade whiteboards:</div>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full bg-white">
                                            <thead>
                                                <tr class="bg-gray-100 text-gray-600 uppercase text-xs">
                                                    <th class="py-2 px-4 text-left">Namn</th>
                                                    <th class="py-2 px-4 text-left">Kod</th>
                                                    <th class="py-2 px-4 text-left">Skapad av</th>
                                                    <th class="py-2 px-4 text-left">Datum</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recentWhiteboards as $wb): ?>
                                                <tr class="border-b hover:bg-gray-50">
                                                    <td class="py-2 px-4"><?= htmlspecialchars($wb['name']) ?></td>
                                                    <td class="py-2 px-4"><?= htmlspecialchars($wb['board_code']) ?></td>
                                                    <td class="py-2 px-4">
                                                        <?= $wb['user_id'] ? htmlspecialchars($wb['username'] ?? 'Användare #'.$wb['user_id']) : 'Gäst' ?>
                                                    </td>
                                                    <td class="py-2 px-4"><?= date('Y-m-d H:i', strtotime($wb['created_at'])) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="text-gray-500 text-sm italic">
                                    Inga nya whiteboards hittades.
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Systemunderhåll och rensning -->
    <div class="bg-white rounded-lg shadow mb-8">
        <div class="border-b border-gray-200 px-6 py-4 flex justify-between items-center">
            <h2 class="text-xl font-semibold text-gray-800">Systemunderhåll</h2>
            <form method="post" action="">
                <input type="hidden" name="trigger_cleanup" value="1">
                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white py-2 px-4 rounded-lg transition flex items-center">
                    <i class="fas fa-broom mr-2"></i>
                    Kör rensningsskript manuellt
                </button>
            </form>
        </div>
        
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h3 class="font-medium text-lg mb-4">Status för automatisk rensning</h3>
                    
                    <?php if ($hasRunBefore): ?>
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <div class="flex items-center">
                            <div class="w-3 h-3 rounded-full bg-green-500 mr-2"></div>
                            <span class="font-medium">Status: Aktiv</span>
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-4">
                            <div class="bg-white p-3 rounded border border-gray-200">
                                <div class="text-xs text-gray-500 uppercase">Senaste körning</div>
                                <div class="font-medium"><?= $lastRun->format('Y-m-d H:i:s') ?></div>
                                <div class="text-xs text-gray-500"><?= $timeAgo ?></div>
                            </div>
                            <div class="bg-white p-3 rounded border border-gray-200">
                                <div class="text-xs text-gray-500 uppercase">Totalt antal körningar</div>
                                <div class="font-medium"><?= $summary['total_runs'] ?></div>
                            </div>
                        </div>
                        
                        <?php if (isset($summary['statistics']) && is_array($summary['statistics'])): ?>
                            <?php if (isset($summary['statistics']['error'])): ?>
                                <div class="mt-4 bg-red-50 p-3 rounded border border-red-200 text-red-600">
                                    <div class="font-medium">Senaste körning misslyckades</div>
                                    <div class="text-sm"><?= htmlspecialchars($summary['statistics']['error']) ?></div>
                                </div>
                            <?php else: ?>
                                <div class="mt-4">
                                    <div class="font-medium mb-2">Senaste rensning:</div>
                                    <div class="grid grid-cols-3 gap-2">
                                        <div class="bg-white p-3 rounded border border-gray-200 text-center">
                                            <div class="text-xl font-semibold text-blue-600"><?= $summary['statistics']['found_whiteboards'] ?? 0 ?></div>
                                            <div class="text-xs text-gray-500">whiteboards hittades</div>
                                        </div>
                                        <div class="bg-white p-3 rounded border border-gray-200 text-center">
                                            <div class="text-xl font-semibold text-blue-600"><?= $summary['statistics']['deleted_whiteboards'] ?? 0 ?></div>
                                            <div class="text-xs text-gray-500">whiteboards raderades</div>
                                        </div>
                                        <div class="bg-white p-3 rounded border border-gray-200 text-center">
                                            <div class="text-xl font-semibold text-blue-600"><?= $summary['statistics']['deleted_widgets'] ?? 0 ?></div>
                                            <div class="text-xs text-gray-500">widgets raderades</div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="bg-yellow-50 p-4 rounded-lg border border-yellow-200">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-triangle text-yellow-500 mr-2"></i>
                            <span class="font-medium">Status: Har ej körts ännu</span>
                        </div>
                        <p class="mt-3 text-sm text-gray-600">
                            Det verkar som att det automatiska rensningsjobbet inte har körts ännu.
                            Du kan köra det manuellt eller vänta tills nästa schemalagda körning.</p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div>
                    <h3 class="font-medium text-lg mb-4">Om automatisk rensning</h3>
                    <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                        <div class="text-sm text-gray-700">
                            <p class="mb-3">
                                Systemet rensar automatiskt bort whiteboards som uppfyller följande kriterier:
                            </p>
                            <ul class="space-y-2">
                                <li class="flex items-start">
                                    <i class="fas fa-check-circle text-green-500 mt-0.5 mr-2"></i>
                                    <span>Skapade av icke-registrerade användare (gästwhiteboards)</span>
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-check-circle text-green-500 mt-0.5 mr-2"></i>
                                    <span>Äldre än tre dagar</span>
                                </li>
                            </ul>
                            <div class="mt-4 bg-white p-3 rounded border border-gray-200">
                                <p class="text-sm text-gray-600">
                                    <i class="fas fa-info-circle text-blue-500 mr-1"></i>
                                    Rensningen sker automatiskt varje dag genom ett CRON-jobb. Alla relaterade data (widgets, studentgrupper, etc.) tas också bort.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bakgrundsanvändning -->
    <div class="bg-white rounded-lg shadow mb-8">
        <div class="border-b border-gray-200 px-6 py-4">
            <h2 class="text-xl font-semibold text-gray-800">Bakgrundsanvändning</h2>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h3 class="font-medium mb-3">Bakgrundstyp-fördelning</h3>
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <div class="w-full bg-gray-200 rounded-full h-4 mb-2">
                            <?php 
                            $colorPercentage = round(($extendedStats['whiteboards']['color_backgrounds'] / max(1, $extendedStats['whiteboards']['total'])) * 100);
                            $imagePercentage = round(($extendedStats['whiteboards']['image_backgrounds'] / max(1, $extendedStats['whiteboards']['total'])) * 100);
                            ?>
                            <div class="flex h-4 rounded-full overflow-hidden">
                                <div class="bg-blue-500 h-4" style="width: <?= $colorPercentage ?>%"></div>
                                <div class="bg-green-500 h-4" style="width: <?= $imagePercentage ?>%"></div>
                                <div class="bg-gray-400 h-4" style="width: <?= 100 - $colorPercentage - $imagePercentage ?>%"></div>
                            </div>
                        </div>
                        <div class="flex justify-between text-xs text-gray-600">
                            <span><span class="inline-block w-3 h-3 bg-blue-500 mr-1"></span> Färg (<?= $colorPercentage ?>%)</span>
                            <span><span class="inline-block w-3 h-3 bg-green-500 mr-1"></span> Bild (<?= $imagePercentage ?>%)</span>
                            <span><span class="inline-block w-3 h-3 bg-gray-400 mr-1"></span> Annat (<?= 100 - $colorPercentage - $imagePercentage ?>%)</span>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-3 mt-4">
                            <div class="bg-white p-3 rounded border border-gray-200 text-center">
                                <div class="text-xl font-semibold text-blue-600"><?= $extendedStats['whiteboards']['color_backgrounds'] ?></div>
                                <div class="text-xs text-gray-500">Färgbakgrunder</div>
                            </div>
                            <div class="bg-white p-3 rounded border border-gray-200 text-center">
                                <div class="text-xl font-semibold text-green-600"><?= $extendedStats['whiteboards']['image_backgrounds'] ?></div>
                                <div class="text-xs text-gray-500">Bildbakgrunder</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Widget-användning -->
                <div>
                    <h3 class="font-medium mb-3">Widget-användning</h3>
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <div class="space-y-3">
                            <?php
                            $widgetTypes = array_slice($extendedStats['widget_types'], 0, 4);
                            $totalWidgets = array_sum(array_column($extendedStats['widget_types'], 'type_count'));
                            
                            if ($totalWidgets > 0):
                                foreach ($widgetTypes as $widget) {
                                    $percentage = round(($widget['type_count'] / $totalWidgets) * 100);
                                    $colorClass = match(strtolower(substr($widget['type'], 0, 4))) {
                                        'time' => 'bg-blue-500',
                                        'text' => 'bg-green-500',
                                        'imag' => 'bg-purple-500',
                                        'coun' => 'bg-yellow-500',
                                        'vide' => 'bg-red-500',
                                        'poll' => 'bg-indigo-500',
                                        default => 'bg-gray-500'
                                    };
                            ?>
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs text-gray-600"><?= htmlspecialchars($widget['type']) ?></span>
                                    <span class="text-xs text-gray-600"><?= $widget['type_count'] ?> (<?= $percentage ?>%)</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="<?= $colorClass ?> h-2 rounded-full" style="width: <?= $percentage ?>%"></div>
                                </div>
                            </div>
                            <?php } else: ?>
                            <div class="text-center text-gray-500 py-3">
                                Inga widgets hittades i systemet.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript för flikar -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Hämta alla flik-knappar och innehåll
        const tabs = document.querySelectorAll('[data-tabs-target]');
        const tabContents = document.querySelectorAll('[role="tabpanel"]');
        
        // Lägg till klickhändelser för varje flik
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const target = document.querySelector(tab.dataset.tabsTarget);
                
                // Göm alla tabContents
                tabContents.forEach(tc => {
                    tc.classList.add('hidden');
                    tc.classList.remove('block');
                });
                
                // Visa det valda innehållet
                target.classList.remove('hidden');
                target.classList.add('block');
                
                // Uppdatera aktiv flik-stil
                tabs.forEach(t => {
                    t.classList.remove('border-blue-500', 'text-blue-600');
                    t.classList.add('border-transparent', 'text-gray-500', 'hover:text-gray-600', 'hover:border-gray-300');
                    t.setAttribute('aria-selected', 'false');
                });
                
                tab.classList.remove('border-transparent', 'text-gray-500', 'hover:text-gray-600', 'hover:border-gray-300');
                tab.classList.add('border-blue-500', 'text-blue-600');
                tab.setAttribute('aria-selected', 'true');
            });
        });
    });
</script>
</body>
</html>