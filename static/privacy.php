<?php
session_start();

require_once __DIR__ . '/../src/Config/Database.php';
$useDynamic = false; $page = null; $siteName = 'Klassrumsverktyg'; $contactEmail = '';
try {
    $db = new Database();
    $pdo = $db->getConnection();
    // Läs inställningar
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('pages_dynamic_enabled','site_name','smtp_from_address')");
    $stmt->execute();
    $pairs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    if (!empty($pairs['site_name'])) { $siteName = (string)$pairs['site_name']; }
    $useDynamic = isset($pairs['pages_dynamic_enabled']) ? ($pairs['pages_dynamic_enabled'] === '1') : true; // default: på
    $contactEmail = (string)($pairs['smtp_from_address'] ?? '');

    if ($useDynamic) {
        $stmt = $pdo->prepare("SELECT title, content, format, is_published, updated_at FROM pages WHERE slug='privacy' LIMIT 1");
        $stmt->execute();
        $page = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($page && (int)$page['is_published'] !== 1) { $page = null; }
    }
} catch (Throwable $e) {
    // fallback till statiskt innehåll
}

$rendered = $page ? ($page['content'] ?? '') : '';
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Integritetspolicy - <?= htmlspecialchars($siteName) ?></title>
    <link href="/assets/vendor/tailwind/tailwind.min.css" rel="stylesheet">
    <script src="/assets/vendor/lucide/lucide.min.js"></script>
</head>
<body class="flex flex-col min-h-screen bg-gray-100">
   <?php include '../header.php'; ?>

<main class="flex-grow container mx-auto px-6 py-12">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-4xl font-bold text-gray-900 mb-8">Integritetspolicy för <?= htmlspecialchars($siteName) ?></h1>

        <div class="bg-white rounded-lg shadow-md p-8">
            <?php if ($page): ?>
                <div class="prose">
                    <?= $rendered ?>
                </div>
            <?php else: ?>
            <section class="mb-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">1. Introduktion</h2>
                <p class="text-gray-700 leading-relaxed">
                    Denna integritetspolicy beskriver hur <?= htmlspecialchars($siteName) ?> ("vi", "oss", "vår") samlar in, använder och skyddar personuppgifter. Vi strävar efter att uppfylla kraven i tillämplig dataskyddslagstiftning, inklusive GDPR.
                </p>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">2. Personuppgiftsansvarig</h2>
                <p class="text-gray-700 leading-relaxed">
                    <?= htmlspecialchars($siteName) ?> (lokal instansdrift). Kontakt: <a class="text-blue-600 hover:text-blue-800 underline" href="mailto:<?= htmlspecialchars($contactEmail ?: ('privacy@' . ($_SERVER['HTTP_HOST'] ?? 'example.com'))) ?>"><?= htmlspecialchars($contactEmail ?: ('privacy@' . ($_SERVER['HTTP_HOST'] ?? 'example.com'))) ?></a>
                </p>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">3. Vilka personuppgifter samlar vi in?</h2>
                <ul class="list-disc list-inside text-gray-700 leading-relaxed space-y-3">
                    <li><strong>Kontoinformation:</strong> Namn och e‑postadress när ett konto skapas.</li>
                    <li><strong>Inloggningstjänster:</strong> Om tredjepartsinloggning (t.ex. Google) är aktiverad kan uppgifter delas enligt den leverantörens villkor.</li>
                    <li><strong>Skydd mot missbruk:</strong> Vi kan använda skyddsmekanismer (t.ex. Google reCAPTCHA) för att motverka spam och missbruk.</li>
                    <li><strong>Kontakt:</strong> Om du kontaktar oss via e‑post eller formulär behandlar vi de uppgifter du lämnar för att besvara din förfrågan.</li>
                    <li><strong>Icke‑registrerade användare:</strong> För besökare utan konto samlar vi i normalfallet inte in personuppgifter utöver vad som krävs för grundläggande säkerhet och drift.
                </ul>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">4. Varför samlar vi in personuppgifter?</h2>
                <ul class="list-disc list-inside text-gray-700 leading-relaxed space-y-3">
                    <li><strong>Tillhandahålla tjänsten:</strong> Skapa/hantera konton, autentisering och access.</li>
                    <li><strong>Förenklad inloggning:</strong> Möjliggöra inloggning via externa identitetsleverantörer om aktiverat.</li>
                    <li><strong>Skydd och säkerhet:</strong> För att förhindra missbruk, upptäcka bedrägerier och säkra driften.</li>
                    <li><strong>Support:</strong> Hantera frågor och återkoppling från användare.</li>
                </ul>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">5. Rättslig grund</h2>
                <ul class="list-disc list-inside text-gray-700 leading-relaxed space-y-3">
                    <li><strong>Avtal och berättigat intresse:</strong> Behandling som krävs för att leverera tjänsten och skydda plattformen.</li>
                    <li><strong>Samtycke:</strong> När du uttryckligen godkänner särskild behandling (t.ex. valda integreringar) kan samtycke utgöra rättslig grund.</li>
                </ul>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">6. Hur vi skyddar personuppgifter</h2>
                <ul class="list-disc list-inside text-gray-700 leading-relaxed space-y-3">
                    <li>Tekniska och organisatoriska säkerhetsåtgärder tillämpas för att skydda uppgifter.</li>
                    <li>Uppgifter delas inte med tredje part utan rättslig grund och ändamål.</li>
                    <li>Uppgifter lagras och behandlas i den miljö där din instans körs (on‑premises eller vald leverantör) i enlighet med lokal konfiguration.</li>
                </ul>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">7. Dina rättigheter</h2>
                <ul class="list-disc list-inside text-gray-700 leading-relaxed space-y-3">
                    <li><strong>Tillgång:</strong> Begära information om behandlade uppgifter.</li>
                    <li><strong>Rättelse:</strong> Korrigera felaktiga eller ofullständiga uppgifter.</li>
                    <li><strong>Radering:</strong> Begära radering när uppgifterna inte längre behövs eller enligt lagstadgade rättigheter.</li>
                    <li><strong>Klagomål:</strong> Inge klagomål till relevant tillsynsmyndighet (t.ex. IMY i Sverige).
                </ul>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">8. Delning med tredje part</h2>
                <p class="text-gray-700 leading-relaxed">
                    Delning kan förekomma med identitets‑ och säkerhetstjänster (t.ex. Google Sign‑In, reCAPTCHA) om dessa är aktiverade, i enlighet med respektive leverantörs villkor och integritetspolicy.
                </p>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">9. Lagringstid</h2>
                <p class="text-gray-700 leading-relaxed">
                    Kontouppgifter sparas under tiden du har ett aktivt konto och raderas därefter enligt gällande rutin, med beaktande av eventuella lagkrav.
                </p>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">10. Ändringar i policyn</h2>
                <p class="text-gray-700 leading-relaxed">
                    Vi kan uppdatera denna policy. Senaste versionen finns på denna sida.
                </p>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">11. Kontakt</h2>
                <p class="text-gray-700 leading-relaxed">
                    Kontakta oss via e‑post: <a class="text-blue-600 hover:text-blue-800 underline" href="mailto:<?= htmlspecialchars($contactEmail ?: ('privacy@' . ($_SERVER['HTTP_HOST'] ?? 'example.com'))) ?>"><?= htmlspecialchars($contactEmail ?: ('privacy@' . ($_SERVER['HTTP_HOST'] ?? 'example.com'))) ?></a>
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