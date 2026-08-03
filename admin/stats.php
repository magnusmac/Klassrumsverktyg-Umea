<?php
// admin/stats.php
require_once __DIR__ . '/../src/Config/Database.php';
require_once 'AdminController.php';

$database = new Database();
$db = $database->getConnection();
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

// Om det inte finns en metod för användarstatistik i AdminController behöver vi definiera en fallback
if (!isset($stats['users_near_limit'])) {
    $stats['users_near_limit'] = 0;
    
    // Beräkna användare nära sin kvot direkt här om metoden saknas
    $nearLimitQuery = "SELECT COUNT(*) as count FROM (
                       SELECT u.id, COUNT(w.id) as wb_count, wl.max_whiteboards
                       FROM users u
                       JOIN whiteboard_limits wl ON u.id = wl.user_id
                       LEFT JOIN whiteboards w ON u.id = w.user_id
                       WHERE u.is_active = 1
                       GROUP BY u.id, wl.max_whiteboards
                       HAVING (wb_count / wl.max_whiteboards) >= 0.9
                      ) as near_limit";
    $nearLimitStmt = $db->prepare($nearLimitQuery);
    $nearLimitStmt->execute();
    $nearLimitResult = $nearLimitStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($nearLimitResult) {
        $stats['users_near_limit'] = $nearLimitResult['count'];
    }
}

// Hämta information om uppladdade bakgrundsbilder
function getBackgroundImagesStats() {
    $totalSize = 0;
    $fileCount = 0;
    $userFolders = 0;
    $fileTypes = [];
    
    $baseDir = '../uploads/backgrounds';
    if (is_dir($baseDir)) {
        $folders = array_diff(scandir($baseDir), ['.', '..']);
        $userFolders = count($folders);
        
        foreach ($folders as $folder) {
            $folderPath = $baseDir . '/' . $folder;
            if (is_dir($folderPath)) {
                $files = array_diff(scandir($folderPath), ['.', '..']);
                foreach ($files as $file) {
                    $filePath = $folderPath . '/' . $file;
                    if (is_file($filePath)) {
                        $fileCount++;
                        $fileSize = filesize($filePath);
                        $totalSize += $fileSize;
                        
                        // Hämta filtyp
                        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                        if (!isset($fileTypes[$extension])) {
                            $fileTypes[$extension] = [
                                'count' => 0,
                                'size' => 0
                            ];
                        }
                        $fileTypes[$extension]['count']++;
                        $fileTypes[$extension]['size'] += $fileSize;
                    }
                }
            }
        }
    }
    
    // Sortera filtyper efter antal
    arsort($fileTypes);
    
    return [
        'total_size' => $totalSize,
        'file_count' => $fileCount,
        'user_folders' => $userFolders,
        'avg_size' => $fileCount > 0 ? $totalSize / $fileCount : 0,
        'file_types' => $fileTypes
    ];
}

$imageStats = getBackgroundImagesStats();

// Hämta ytterligare statistik från databasstrukturen
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
    
    // Brain breaks statistik
    $brainBreaksQuery = "SELECT COUNT(*) as total,
                         SUM(CASE WHEN is_public = 1 THEN 1 ELSE 0 END) as public_breaks,
                         AVG(duration) as avg_duration
                         FROM brain_breaks";
    $brainBreaksStmt = $db->prepare($brainBreaksQuery);
    $brainBreaksStmt->execute();
    $brainBreaksResult = $brainBreaksStmt->fetch(PDO::FETCH_ASSOC);
    
    // Senaste aktiviteten
    $activityQuery = "SELECT 'user' as type, username as name, created_at FROM users
                     UNION ALL
                     SELECT 'whiteboard' as type, name, created_at FROM whiteboards
                     ORDER BY created_at DESC LIMIT 10";
    $activityStmt = $db->prepare($activityQuery);
    $activityStmt->execute();
    $recentActivity = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'users' => $userResult,
        'whiteboards' => $whiteboardResult,
        'widget_types' => $widgetTypes,
        'brain_breaks' => $brainBreaksResult,
        'recent_activity' => $recentActivity
    ];
}

$extendedStats = getExtendedStats($db);

// Formatera bytes till läsbara storlekar
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Debug-information till loggfilen för att se om filer hittas
error_log("Bakgrundsstatistik: " . print_r(getBackgroundImagesStats(), true));
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Systemstatistik - <?= htmlspecialchars($siteName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/vendor/fontawesome/all.min.css">
</head>
<body class="bg-gray-100">

<?php include_once 'nav.php'; ?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800">Systemstatistik</h1>
        <span class="text-sm text-gray-500">Senast uppdaterad: <?= date('Y-m-d H:i') ?></span>
    </div>
    
    <!-- Översiktsstatistik -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Användare -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-blue-500">
            <div class="flex justify-between items-center">
                <div class="text-sm font-medium text-gray-500">Totalt antal användare</div>
                <i class="fas fa-users text-blue-500"></i>
            </div>
            <div class="mt-2 text-3xl font-semibold"><?= $extendedStats['users']['total'] ?></div>
            <div class="text-sm text-gray-500 mt-2 flex justify-between">
                <span><?= $extendedStats['users']['teachers'] ?> lärare</span>
                <span><?= $extendedStats['users']['admins'] ?> administratörer</span>
            </div>
        </div>

        <!-- Whiteboards -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-green-500">
            <div class="flex justify-between items-center">
                <div class="text-sm font-medium text-gray-500">Totalt antal whiteboards</div>
                <i class="fas fa-chalkboard text-green-500"></i>
            </div>
            <div class="mt-2 text-3xl font-semibold"><?= $stats['total_whiteboards'] ?></div>
            <div class="text-sm text-gray-500 mt-2">
                <?php
                $wbTotal = (int)($extendedStats['whiteboards']['total'] ?? 0);
                $wbActive = (int)($stats['active_whiteboards'] ?? 0);
                $activePct = $wbTotal > 0 ? round(($wbActive / $wbTotal) * 100) : 0;
                ?>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-green-500 h-2 rounded-full" style="width: <?= $activePct ?>%"></div>
                </div>
                <div class="flex justify-between mt-1">
                    <span><?= $wbActive ?> aktiva (<?= $activePct ?>%)</span>
                    <span><?= $extendedStats['whiteboards']['password_protected'] ?> lösenordsskyddade</span>
                </div>
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

        <!-- Brain Breaks -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-red-500">
            <div class="flex justify-between items-center">
                <div class="text-sm font-medium text-gray-500">Brain Breaks</div>
                <i class="fas fa-brain text-red-500"></i>
            </div>
            <div class="mt-2 text-3xl font-semibold"><?= $extendedStats['brain_breaks']['total'] ?></div>
            <div class="text-sm text-gray-500 mt-2">
                <?php
                $bbTotal = (int)($extendedStats['brain_breaks']['total'] ?? 0);
                $bbPublic = (int)($extendedStats['brain_breaks']['public_breaks'] ?? 0);
                $bbPct = $bbTotal > 0 ? round(($bbPublic / $bbTotal) * 100) : 0;
                ?>
                <div><?= $bbPublic ?> publika (<?= $bbPct ?>%)</div>
                <div>Genomsnittlig längd: <?= round($extendedStats['brain_breaks']['avg_duration']) ?> sek</div>
            </div>
        </div>
    </div>
    
    <!-- Resursanvändning -->
    <div class="bg-white rounded-lg shadow mb-8">
        <div class="border-b border-gray-200 px-6 py-4">
            <h2 class="text-xl font-semibold text-gray-800">Resursanvändning</h2>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Total bildstorlek -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="text-sm font-medium text-gray-500 mb-2">Total lagringsanvändning</div>
                    <div class="text-2xl font-semibold"><?= formatBytes($imageStats['total_size']) ?></div>
                    <div class="text-sm text-gray-500 mt-2">Från <?= $imageStats['file_count'] ?> bilder</div>
                </div>
                
                <!-- Genomsnittlig bildstorlek -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="text-sm font-medium text-gray-500 mb-2">Genomsnittlig bildstorlek</div>
                    <div class="text-2xl font-semibold"><?= formatBytes($imageStats['avg_size']) ?></div>
                    <div class="text-sm text-gray-500 mt-2">Per uppladdad bild</div>
                </div>
                
                <!-- Användare med egna bilder -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="text-sm font-medium text-gray-500 mb-2">Användare med egna bilder</div>
                    <div class="text-2xl font-semibold"><?= $imageStats['user_folders'] ?></div>
                    <?php
                    $usersTotal = (int)($extendedStats['users']['total'] ?? 0);
                    $userFolders = (int)($imageStats['user_folders'] ?? 0);
                    $ufPct = $usersTotal > 0 ? round(($userFolders / $usersTotal) * 100) : 0;
                    ?>
                    <div class="text-sm text-gray-500 mt-2">
                        <?= $ufPct ?>% av alla användare
                    </div>
                </div>
            </div>
            
            <!-- Bakgrundsanvändning -->
            <div class="mt-6">
                <div class="text-sm font-medium text-gray-700 mb-2">Bakgrundstyp-användning</div>
                <div class="w-full bg-gray-200 rounded-full h-4">
                    <?php 
                    $wbTotal2 = (int)($extendedStats['whiteboards']['total'] ?? 0);
                    $wbColor = (int)($extendedStats['whiteboards']['color_backgrounds'] ?? 0);
                    $wbImage = (int)($extendedStats['whiteboards']['image_backgrounds'] ?? 0);
                    if ($wbTotal2 > 0) {
                        $colorPercentage = round(($wbColor / $wbTotal2) * 100);
                        $imagePercentage = round(($wbImage / $wbTotal2) * 100);
                    } else {
                        $colorPercentage = 0;
                        $imagePercentage = 0;
                    }
                    $otherPercentage = max(0, 100 - $colorPercentage - $imagePercentage);
                    ?>
                    <div class="flex h-4 rounded-full overflow-hidden">
                        <div class="bg-blue-500 h-4" style="width: <?= $colorPercentage ?>%"></div>
                        <div class="bg-green-500 h-4" style="width: <?= $imagePercentage ?>%"></div>
                        <div class="bg-gray-400 h-4" style="width: <?= $otherPercentage ?>%"></div>
                    </div>
                </div>
                <div class="flex justify-between mt-1 text-xs text-gray-600">
                    <span><span class="inline-block w-3 h-3 bg-blue-500 mr-1"></span> Färg (<?= $colorPercentage ?>%)</span>
                    <span><span class="inline-block w-3 h-3 bg-green-500 mr-1"></span> Bild (<?= $imagePercentage ?>%)</span>
                    <span><span class="inline-block w-3 h-3 bg-gray-400 mr-1"></span> Annat (<?= $otherPercentage ?>%)</span>
                </div>
            </div>
            
            <!-- Filtypsfördelning -->
            <div class="mt-6">
                <div class="text-sm font-medium text-gray-700 mb-2">Filtypsfördelning</div>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <?php if (count($imageStats['file_types']) > 0): ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <h3 class="text-sm font-medium text-gray-700 mb-2">Antal filer per typ</h3>
                                <div class="space-y-2">
                                    <?php 
                                    foreach ($imageStats['file_types'] as $ext => $info): 
                                        $fileCount = (int)($imageStats['file_count'] ?? 0);
                                        $percentage = $fileCount > 0 ? round(($info['count'] / $fileCount) * 100) : 0;
                                        $color = match($ext) {
                                            'jpg', 'jpeg' => 'bg-blue-500',
                                            'png' => 'bg-green-500',
                                            'gif' => 'bg-purple-500',
                                            'webp' => 'bg-yellow-500',
                                            default => 'bg-gray-500'
                                        };
                                    ?>
                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs text-gray-600">.<?= $ext ?></span>
                                            <span class="text-xs text-gray-600"><?= $info['count'] ?> (<?= $percentage ?>%)</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="<?= $color ?> h-2 rounded-full" style="width: <?= $percentage ?>%"></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div>
                                <h3 class="text-sm font-medium text-gray-700 mb-2">Total storlek per typ</h3>
                                <div class="space-y-2">
                                    <?php 
                                    foreach ($imageStats['file_types'] as $ext => $info): 
                                        $totalSize = (int)($imageStats['total_size'] ?? 0);
                                        $percentage = $totalSize > 0 ? round(($info['size'] / $totalSize) * 100) : 0;
                                        $color = match($ext) {
                                            'jpg', 'jpeg' => 'bg-blue-500',
                                            'png' => 'bg-green-500',
                                            'gif' => 'bg-purple-500',
                                            'webp' => 'bg-yellow-500',
                                            default => 'bg-gray-500'
                                        };
                                    ?>
                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs text-gray-600">.<?= $ext ?></span>
                                            <span class="text-xs text-gray-600"><?= formatBytes($info['size']) ?> (<?= $percentage ?>%)</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="<?= $color ?> h-2 rounded-full" style="width: <?= $percentage ?>%"></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-gray-500 py-4">Inga filer hittades</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Användning och gränser -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
        <!-- Användargränser -->
        <div class="bg-white rounded-lg shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-xl font-semibold text-gray-800">Användargränser</h2>
            </div>
            <div class="p-6">
                <div class="mb-4">
                    <div class="text-sm font-medium text-gray-500 mb-2">Användare nära gränsen</div>
                    <div class="text-3xl font-semibold"><?= $stats['users_near_limit'] ?></div>
                    <div class="text-sm text-gray-500 mt-1">Användare som använt >90% av sin kvot</div>
                </div>
                
                <div class="mt-6">
                    <div class="text-sm font-medium text-gray-500 mb-2">Användning per användare</div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead>
                                <tr>
                                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider py-2">Användare</th>
                                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider py-2">Whiteboards</th>
                                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider py-2">Användning</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php
                                // Hämta faktisk data för användargränser
                                $userLimitsQuery = "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name, 
                                                   COUNT(w.id) AS used_whiteboards, 
                                                   wl.max_whiteboards AS limit_whiteboards
                                                   FROM users u
                                                   LEFT JOIN whiteboards w ON u.id = w.user_id
                                                   LEFT JOIN whiteboard_limits wl ON u.id = wl.user_id
                                                   WHERE u.role = 'teacher' AND wl.max_whiteboards > 0
                                                   GROUP BY u.id, u.first_name, u.last_name, wl.max_whiteboards
                                                   ORDER BY (COUNT(w.id) / wl.max_whiteboards) DESC
                                                   LIMIT 5";
                                $userLimitsStmt = $db->prepare($userLimitsQuery);
                                $userLimitsStmt->execute();
                                $topUsers = $userLimitsStmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                foreach ($topUsers as $user) {
                                    $limit = (int)($user['limit_whiteboards'] ?? 0);
                                    $used = (int)($user['used_whiteboards'] ?? 0);
                                    $percentage = $limit > 0 ? ($used / $limit) * 100 : 0;
                                    $colorClass = 'bg-green-500';
                                    if ($percentage > 90) {
                                        $colorClass = 'bg-red-500';
                                    } elseif ($percentage > 70) {
                                        $colorClass = 'bg-yellow-500';
                                    }
                                ?>
                                <tr>
                                    <td class="py-2"><?= htmlspecialchars($user['name']) ?></td>
                                    <td class="py-2"><?= $user['used_whiteboards'] ?> / <?= $user['limit_whiteboards'] ?></td>
                                    <td class="py-2">
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="<?= $colorClass ?> h-2 rounded-full" style="width: <?= $percentage ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Senaste aktiviteten -->
        <div class="bg-white rounded-lg shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-xl font-semibold text-gray-800">Senaste aktiviteten</h2>
            </div>
            <div class="p-6">
                <ul>
                    <?php foreach ($extendedStats['recent_activity'] as $activity) { 
                        $icon = $activity['type'] == 'user' ? 'fas fa-user' : 'fas fa-chalkboard';
                        $colorClass = $activity['type'] == 'user' ? 'text-blue-500' : 'text-green-500';
                        $typeText = $activity['type'] == 'user' ? 'Ny användare' : 'Ny whiteboard';
                    ?>
                    <li class="py-2 border-b border-gray-100 last:border-0">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i class="<?= $icon ?> <?= $colorClass ?>"></i>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-900"><?= $typeText ?>: <?= htmlspecialchars($activity['name']) ?></div>
                                <div class="text-xs text-gray-500"><?= date('Y-m-d H:i', strtotime($activity['created_at'])) ?></div>
                            </div>
                        </div>
                    </li>
                    <?php } ?>
                </ul>
            </div>
        </div>
    </div>
    
    <!-- Systemdiagnostik -->
    <div class="bg-white rounded-lg shadow">
        <div class="border-b border-gray-200 px-6 py-4">
            <h2 class="text-xl font-semibold text-gray-800">Systemdiagnostik</h2>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- PHP Version -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="text-sm font-medium text-gray-500 mb-2">PHP Version</div>
                    <div class="text-xl font-semibold"><?= phpversion() ?></div>
                </div>
                
                <!-- MySQL Version -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="text-sm font-medium text-gray-500 mb-2">MySQL Version</div>
                    <?php
                    $mysqlVersionQuery = "SELECT VERSION() as version";
                    $stmt = $db->prepare($mysqlVersionQuery);
                    $stmt->execute();
                    $mysqlVersion = $stmt->fetch(PDO::FETCH_ASSOC)['version'];
                    ?>
                    <div class="text-xl font-semibold"><?= $mysqlVersion ?></div>
                </div>
                
                <!-- Servertid -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="text-sm font-medium text-gray-500 mb-2">Servertid</div>
                    <div class="text-xl font-semibold"><?= date('Y-m-d H:i:s') ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>