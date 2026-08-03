<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Läs instansens namn från system_settings med säker fallback
$siteName = 'Klassrumsverktyg';
try {
    require_once __DIR__ . '/src/Config/Database.php';
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
    // behåll fallback
}
?>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js');
}
</script>

<header class="bg-gray-900">
    <nav class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 justify-between">
            <div class="flex items-center space-x-4">
                <i data-lucide="graduation-cap" class="h-6 w-6 text-gray-300"></i>
                <a href="/" class="text-white text-xl font-semibold"><?= htmlspecialchars($siteName) ?></a>
            </div>

            <div class="flex items-center space-x-6">
                <div class="hidden md:flex items-center space-x-6 text-base">
                    <a href="/static/about.php" class="text-gray-300 hover:text-white px-3 py-2 rounded-md text-sm font-medium">Om sidan</a>
                    <a href="/static/features.php" class="text-gray-300 hover:text-white px-3 py-2 rounded-md text-sm font-medium">Funktioner</a>
                    <a href="/static/contact.php" class="text-gray-300 hover:text-white px-3 py-2 rounded-md text-sm font-medium">Kontakt</a>
                </div>

                <?php if (isset($_SESSION['user_id'])): ?>
                    <div class="flex items-center space-x-4">
                        <span class="text-gray-300 text-sm font-medium">Välkommen, <?php echo htmlspecialchars($_SESSION['first_name'] ?? ''); ?></span>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <a href="/admin/dashboard.php" class="bg-gray-800 text-gray-300 hover:text-white px-3 py-2 rounded-md text-sm font-medium">Admin</a>
                        <?php endif; ?>
                        <a href="/logout.php" class="bg-red-600 text-white px-3 py-2 rounded-md text-sm font-medium hover:bg-red-700">Logga ut</a>
                    </div>
                <?php else: ?>
                    <a href="/login.php" class="bg-blue-600 text-white px-3 py-2 rounded-md text-sm font-medium hover:bg-blue-700">Logga in</a>
                <?php endif; ?>

                <button id="mobile-menu-button" class="md:hidden text-gray-300 focus:outline-none">
                    <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7" />
                    </svg>
                </button>
            </div>
        </div>
    </nav>

    <div id="mobile-menu" class="hidden md:hidden bg-gray-900 shadow-md py-4 px-6">
        <a href="/static/about.php" class="block text-gray-300 py-2 text-base font-medium hover:text-white">Om oss</a>
        <a href="/features.php" class="block text-gray-300 py-2 text-base font-medium hover:text-white">Funktioner</a>
        <a href="/contact.php" class="block text-gray-300 py-2 text-base font-medium hover:text-white">Kontakt</a>
        <hr class="my-4 border-gray-800">
        <?php if (isset($_SESSION['user_id'])): ?>
            <span class="block text-gray-300 py-2 text-sm font-medium">Välkommen, <?php echo htmlspecialchars($_SESSION['first_name'] ?? ''); ?></span>
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="/admin/" class="block bg-gray-800 text-gray-300 hover:text-white px-4 py-2 rounded-md text-sm font-medium">Admin</a>
            <?php endif; ?>
            <a href="/logout.php" class="block bg-red-600 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-red-700">Logga ut</a>
        <?php else: ?>
            <a href="/login.php" class="block bg-blue-600 text-white px-5 py-2 rounded-md text-sm font-medium hover:bg-blue-700">Logga in</a>
        <?php endif; ?>
    </div>
</header>

<script>
    lucide.createIcons();
    document.getElementById("mobile-menu-button").addEventListener("click", function() {
        document.getElementById("mobile-menu").classList.toggle("hidden");
    });
</script>