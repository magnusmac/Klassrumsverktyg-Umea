<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$siteName = 'Klassrumsverktyg';
try {
    require_once __DIR__ . '/../src/Config/Database.php';
    $db = new Database();
    $pdo = $db->getConnection();
    if (!function_exists('kv_get_setting')) {
        function kv_get_setting(PDO $pdo, string $key, $default = null) {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row['setting_value'] ?? $default;
        }
    }
    $name = (string) kv_get_setting($pdo, 'site_name', '');
    if ($name !== '') { $siteName = $name; }
} catch (Throwable $e) {
    // fallback
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - <?= htmlspecialchars($siteName) ?></title>
    <link href="/assets/vendor/tailwind/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <div class="flex h-screen">
        <!-- Sidebar -->
        <div class="w-64 bg-gray-800 text-white p-4">
            <div class="text-2xl font-bold mb-8"><?= htmlspecialchars($siteName) ?></div>
            <nav>
                <a href="/admin/?action=dashboard" class="block py-2 px-4 rounded hover:bg-gray-700 mb-1">Dashboard</a>
                <a href="/admin/?action=users" class="block py-2 px-4 rounded hover:bg-gray-700 mb-1">Användare</a>
                <a href="/admin/?action=logs" class="block py-2 px-4 rounded hover:bg-gray-700 mb-1">Systemloggar</a>
                <a href="/" class="block py-2 px-4 rounded hover:bg-gray-700 mb-1">Tillbaka till appen</a>
            </nav>
        </div>

        <!-- Main content -->
        <div class="flex-1 p-8 overflow-y-auto">
            <?php if (isset($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <?php include $content; ?>
        </div>
    </div>
</body>
</html>