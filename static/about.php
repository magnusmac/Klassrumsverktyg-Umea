<?php
session_start();

require_once __DIR__ . '/../src/Config/Database.php';
$useDynamic = false; $page = null; $siteName = 'Klassrumsverktyg';
try {
    $db = new Database();
    $pdo = $db->getConnection();
    // site_name för title/header
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('pages_dynamic_enabled','site_name')");
    $stmt->execute();
    $pairs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    if (!empty($pairs['site_name'])) { $siteName = (string)$pairs['site_name']; }
    $useDynamic = isset($pairs['pages_dynamic_enabled']) ? ($pairs['pages_dynamic_enabled'] === '1') : true; // default: på

    if ($useDynamic) {
        $stmt = $pdo->prepare("SELECT title, content, format, is_published, updated_at FROM pages WHERE slug='about' LIMIT 1");
        $stmt->execute();
        $page = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($page && (int)$page['is_published'] !== 1) { $page = null; }
    }
} catch (Throwable $e) {
    // lämna statiskt innehåll om DB fel
}

$rendered = $page ? ($page['content'] ?? '') : '';
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page ? ('Om ' . htmlspecialchars($siteName)) : 'Om Klassrumsverktyg' ?></title>
    <link href="/assets/vendor/tailwind/tailwind.min.css" rel="stylesheet">
    <script src="/assets/vendor/lucide/lucide.min.js"></script>
</head>
<body class="flex flex-col min-h-screen bg-gray-100">
  <?php include '../header.php'; ?>

<main class="flex-grow container mx-auto px-6 py-12">
        <div class="max-w-5xl mx-auto">
            <?php if ($page): ?>
                <h1 class="text-4xl font-bold text-gray-900 mb-8"><?= htmlspecialchars($page['title']) ?></h1>
                <div class="bg-white rounded-lg shadow-md p-8 prose">
                    <?= $rendered ?>
                    <div class="mt-10 border-t border-gray-200 pt-6">
                        <p class="text-gray-500 text-sm">Senast uppdaterad: <?= htmlspecialchars($page['updated_at']) ?></p>
                    </div>
                </div>
            <?php else: ?>
                <h1 class="text-4xl font-bold text-gray-900 mb-8">Klassrumsverktyg - Open Source</h1>

<div class="bg-white rounded-lg shadow-md p-8">
  <section class="mb-12">
    <p class="text-gray-700 leading-relaxed">
      Detta är källkoden för Klassrumsverktyg.se, ett enkelt och fokuserat digitalt verktyg för lärare. Denna open source-version är skapad för att ge skolor och kommuner full kontroll över sin data genom själv-hosting.
    </p>
  </section>

  <section class="mb-8">
    <h2 class="text-2xl font-semibold text-gray-800 mb-4">Om Projektet</h2>
    <p class="text-gray-700 leading-relaxed">
      Klassrumsverktyg föddes ur en enkel fråga: hur kan vi göra lektionerna smidigare och mer fokuserade, utan onödig komplexitet? Svaret blev en ren och intuitiv digital yta för lärare, med de mest essentiella verktygen lättillgängliga.
    </p>
    <p class="text-gray-700 leading-relaxed mt-4">
      I hjärtat av Klassrumsverktyg finns en digital whiteboard där du enkelt kan addera de verktyg som behövs för att skapa tydlighet och engagemang i klassrummet.
    </p>
  </section>

  <section class="mb-8">
    <h2 class="text-2xl font-semibold text-gray-800 mb-4">För Kommuner och Skolor: Äg Er Data</h2>
    <p class="text-gray-700 leading-relaxed">
      En central anledning till att detta projekt är open source är att ge skolor och kommuner möjligheten att äga och kontrollera sin egen data. Genom att ladda ner koden från detta repo och hosta en egen instans av Klassrumsverktyg säkerställer ni att ingen användardata lämnar er egen servermiljö.
    </p>
    <ul class="list-disc list-inside text-gray-700 leading-relaxed mt-4 space-y-2">
      <li><strong>Full datakontroll:</strong> All information stannar inom organisationen.</li>
      <li><strong>Integritet:</strong> Uppfyll enklare kraven i GDPR och andra dataskyddslagar.</li>
      <li><strong>Anpassning:</strong> Möjlighet att anpassa och vidareutveckla verktyget efter egna behov.</li>
      <li><strong>Kostnadseffektivt:</strong> Inga licenskostnader för mjukvaran.</li>
    </ul>
  </section>

  <section class="mb-8">
    <h2 class="text-2xl font-semibold text-gray-800 mb-4">Bakgrund och Filosofi</h2>
    <p class="text-gray-700 leading-relaxed">
      Projektet startades i samarbete med lärare i Danderyd. Genom att lyssna, testa och iterera tillsammans med dem har Klassrumsverktyg formats för att möta verkliga behov. Ett exempel är de inbyggda, naturliga pauserna – korta stunder av rörelse och reflektion som kan integreras direkt i lektionen.
    </p>
    <p class="text-gray-700 leading-relaxed mt-4">
      Vi tror på enkelhet och integritet. Verktyget ska bara fungera, utan krångel.
    </p>
  </section>

  <section class="mb-8">
    <h2 class="text-2xl font-semibold text-gray-800 mb-4">Status och Bidrag (Contributing)</h2>
    <p class="text-gray-700 leading-relaxed">
      Detta är en första version och vi är medvetna om att allt kanske inte är perfekt. Vi välkomnar feedback, buggrapporter och pull requests från communityn för att göra Klassrumsverktyg ännu bättre.
    </p>
    <p class="text-gray-700 leading-relaxed mt-4">
      Har du idéer, hittat ett fel eller vill bidra med kod? Skapa en "Issue" eller en "Pull Request"!
    </p>
  </section>

  <div class="mt-10 border-t border-gray-200 pt-6">
    <p class="text-gray-500 text-sm">
      Senast uppdaterad: 8 april 2025
    </p>
  </div>
</div>
            <?php endif; ?>
        </div>
</main>

<?php include '../footer.php'; ?>

</body>
</html>