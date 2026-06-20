<?php
session_start();

require_once __DIR__ . '/../src/Config/Database.php';
$useDynamic = false; $page = null; $siteName = 'Klassrumsverktyg';
try {
    $db = new Database();
    $pdo = $db->getConnection();
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('pages_dynamic_enabled','site_name')");
    $stmt->execute();
    $pairs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    if (!empty($pairs['site_name'])) { $siteName = (string)$pairs['site_name']; }
    $useDynamic = isset($pairs['pages_dynamic_enabled']) ? ($pairs['pages_dynamic_enabled'] === '1') : true;

    if ($useDynamic) {
        $stmt = $pdo->prepare("SELECT title, content, format, is_published, updated_at FROM pages WHERE slug='terms' LIMIT 1");
        $stmt->execute();
        $page = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($page && (int)$page['is_published'] !== 1) { $page = null; }
    }
} catch (Throwable $e) {
    // fallback to static
}

$rendered = $page ? ($page['content'] ?? '') : '';
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Användarvillkor - <?= htmlspecialchars($siteName) ?></title>
    <link href="/assets/vendor/tailwind/tailwind.min.css" rel="stylesheet">
    <script src="/assets/vendor/lucide/lucide.min.js"></script>
</head>
<body class="flex flex-col min-h-screen bg-gray-100">
  <!-- Header -->
  <?php include '../header.php'; ?>

<!-- Main Content -->
<main class="flex-grow container mx-auto px-6 py-12">
    <div class="max-w-4xl mx-auto">
        <div class="bg-white rounded-lg shadow-md p-8">
            <?php if ($page): ?>
                <h1 class="text-4xl font-bold text-gray-900 mb-8"><?= htmlspecialchars($page['title']) ?></h1>
                <div class="prose">
                    <?= $rendered ?>
                </div>
            <?php else: ?>
                <h1 class="text-4xl font-bold text-gray-900 mb-8">Användarvillkor för <?= htmlspecialchars($siteName) ?></h1>
                <section class="mb-8">
                    <h2 class="text-2xl font-semibold text-gray-800 mb-4">1. Godkännande av villkor</h2>
                    <p class="text-gray-700 leading-relaxed">
                        Genom att använda <?= htmlspecialchars($siteName) ?> accepterar du dessa användarvillkor. Om du inte accepterar villkoren, vänligen avstå från att använda tjänsten.
                    </p>
                </section>
                <section class="mb-8">
                    <h2 class="text-2xl font-semibold text-gray-800 mb-4">2. Beskrivning av tjänsten</h2>
                    <p class="text-gray-700 leading-relaxed">
                        <?= htmlspecialchars($siteName) ?> är en kostnadsfri plattform som tillhandahåller digitala verktyg för undervisning. Målet är att göra digitala hjälpmedel tillgängliga och användarvänliga.
                    </p>
                </section>
                <section class="mb-8">
                    <h2 class="text-2xl font-semibold text-gray-800 mb-4">3. Användning av tjänsten</h2>
                    <ul class="list-disc list-inside text-gray-700 leading-relaxed space-y-2">
                        <li>Tjänsten får användas i utbildningssyfte.</li>
                        <li>Du får inte använda tjänsten för olagliga eller skadliga aktiviteter.</li>
                        <li>Du får inte försöka att skada, störa eller olovligen komma åt system.</li>
                    </ul>
                </section>
                <section class="mb-8">
                    <h2 class="text-2xl font-semibold text-gray-800 mb-4">4. Immateriella rättigheter</h2>
                    <p class="text-gray-700 leading-relaxed">
                        Allt innehåll på <?= htmlspecialchars($siteName) ?> är skyddat av upphovsrätt och andra rättigheter. Du får inte kopiera, distribuera eller ändra något utan tillstånd.
                    </p>
                </section>
                <section class="mb-8">
                    <h2 class="text-2xl font-semibold text-gray-800 mb-4">5. Ansvarsfriskrivning</h2>
                    <p class="text-gray-700 leading-relaxed">
                        <?= htmlspecialchars($siteName) ?> tillhandahålls "i befintligt skick" utan några garantier. Vi ansvarar inte för avbrott, fel eller skador som kan uppstå vid användning.
                    </p>
                </section>
                <section class="mb-8">
                    <h2 class="text-2xl font-semibold text-gray-800 mb-4">6. Integritet</h2>
                    <p class="text-gray-700 leading-relaxed">
                        Se vår <a href="/static/privacy.php" class="text-blue-600 hover:text-blue-800 underline">integritetspolicy</a> för detaljer.
                    </p>
                </section>
                <section class="mb-8">
                    <h2 class="text-2xl font-semibold text-gray-800 mb-4">7. Ändringar i villkoren</h2>
                    <p class="text-gray-700 leading-relaxed">
                        Vi kan ändra dessa villkor. Eventuella uppdateringar publiceras på denna sida. Fortsatt användning innebär att du accepterar de nya villkoren.
                    </p>
                </section>
                <section class="mb-8">
                    <h2 class="text-2xl font-semibold text-gray-800 mb-4">8. Tillämplig lag</h2>
                    <p class="text-gray-700 leading-relaxed">
                        Dessa användarvillkor styrs av lokal tillämplig lag i den miljö där systemet används.
                    </p>
                </section>
                <section class="mb-8">
                    <h2 class="text-2xl font-semibold text-gray-800 mb-4">9. Kontakt</h2>
                    <p class="text-gray-700 leading-relaxed">
                        För frågor om användarvillkor, se <a href="/static/contact.php" class="text-blue-600 hover:text-blue-800 underline">kontaktsidan</a>.
                    </p>
                </section>
            <?php endif; ?>
            <div class="mt-10 border-t border-gray-200 pt-6">
                <p class="text-gray-500 text-sm">
                    Senast uppdaterad: <?= $page ? htmlspecialchars($page['updated_at']) : date('Y-m-d') ?>
                </p>
            </div>
        </div>
    </div>
</main>

<?php include '../footer.php'; ?>

</body>
</html>