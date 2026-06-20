<?php
session_start();

require_once __DIR__ . '/../src/Config/Database.php';
$useDynamic = false; $page = null; $siteName = 'Klassrumsverktyg'; $contactEmail = '';
try {
    $db = new Database();
    $pdo = $db->getConnection();
    // site_name, pages toggle och ev. standard avsändaradress
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('pages_dynamic_enabled','site_name','smtp_from_address','contact_support_email','contact_general_email','contact_phone','contact_info_text')");
    $stmt->execute();
    $pairs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    if (!empty($pairs['site_name'])) { $siteName = (string)$pairs['site_name']; }
    $useDynamic = isset($pairs['pages_dynamic_enabled']) ? ($pairs['pages_dynamic_enabled'] === '1') : true; // default: på
    $contactEmail = (string)($pairs['smtp_from_address'] ?? '');
    $contactSupport = (string)($pairs['contact_support_email'] ?? '');
    $contactGeneral = (string)($pairs['contact_general_email'] ?? '');
    $contactPhone   = (string)($pairs['contact_phone'] ?? '');
    $contactInfo    = (string)($pairs['contact_info_text'] ?? '');

    if ($useDynamic) {
        $stmt = $pdo->prepare("SELECT title, content, format, is_published, updated_at FROM pages WHERE slug='contact' LIMIT 1");
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
    <title><?= $page ? ('Kontakt - ' . htmlspecialchars($siteName)) : 'Kontakt - ' . htmlspecialchars($siteName) ?></title>
    <link href="/assets/vendor/tailwind/tailwind.min.css" rel="stylesheet">
    <script src="/assets/vendor/lucide/lucide.min.js"></script>
</head>
<body class="flex flex-col min-h-screen bg-gray-100">
  <?php include '../header.php'; ?>

<main class="flex-grow container mx-auto px-6 py-12">
    <div class="max-w-3xl mx-auto">
        <div class="bg-white rounded-lg shadow-md p-8">
            <?php if ($page): ?>
                <h1 class="text-4xl font-bold text-gray-900 mb-8"><?= htmlspecialchars($page['title']) ?></h1>
                <div class="prose">
                    <?= $rendered ?>
                </div>
            <?php else: ?>
                <p class="text-gray-700 leading-relaxed mb-6">
                    Har du frågor, feedback eller behöver du hjälp? Du är varmt välkommen att kontakta oss.
                </p>
                <div class="space-y-4">
                    <?php if ($contactSupport): ?>
                    <div class="flex items-start gap-3">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2.25 6.75l8.954 4.772a2.25 2.25 0 0 0 2.092 0L22.25 6.75"/><path d="M3.75 6.75A2.25 2.25 0 0 1 6 4.5h12a2.25 2.25 0 0 1 2.25 2.25v10.5A2.25 2.25 0 0 1 18 19.5H6A2.25 2.25 0 0 1 3.75 17.25V6.75Z"/></svg>
                        <div>
                            <div class="font-medium">Support</div>
                            <a class="text-blue-600 hover:text-blue-800 underline" href="mailto:<?= htmlspecialchars($contactSupport) ?>">
                                <?= htmlspecialchars($contactSupport) ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($contactGeneral): ?>
                    <div class="flex items-start gap-3">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2.25 6.75l8.954 4.772a2.25 2.25 0 0 0 2.092 0L22.25 6.75"/><path d="M3.75 6.75A2.25 2.25 0 0 1 6 4.5h12a2.25 2.25 0 0 1 2.25 2.25v10.5A2.25 2.25 0 0 1 18 19.5H6A2.25 2.25 0 0 1 3.75 17.25V6.75Z"/></svg>
                        <div>
                            <div class="font-medium">Allmänna frågor</div>
                            <a class="text-blue-600 hover:text-blue-800 underline" href="mailto:<?= htmlspecialchars($contactGeneral) ?>">
                                <?= htmlspecialchars($contactGeneral) ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($contactPhone): ?>
                    <div class="flex items-start gap-3">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h5l2 5-3 2c1 4 4 7 8 8l2-3 5 2v5a2 2 0 0 1-2 2c-9.941 0-18-8.059-18-18a2 2 0 0 1 2-2z"/></svg>
                        <div>
                            <div class="font-medium">Telefon</div>
                            <div class="text-gray-700"><?= htmlspecialchars($contactPhone) ?></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($contactInfo): ?>
                    <div class="flex items-start gap-3">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12c0 4.97-4.03 9-9 9s-9-4.03-9-9 4.03-9 9-9 9 4.03 9 9z"/></svg>
                        <div>
                            <div class="font-medium">Information</div>
                            <div class="text-gray-700 whitespace-pre-line"><?= nl2br(htmlspecialchars($contactInfo)) ?></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!$contactSupport && !$contactGeneral && !$contactPhone && !$contactInfo): ?>
                    <div class="text-gray-700">Just nu finns inga kontaktuppgifter angivna. Du kan nå oss via e‑post på <a href="mailto:<?= htmlspecialchars($contactEmail ?: ('support@' . ($_SERVER['HTTP_HOST'] ?? 'example.com'))) ?>" class="text-blue-600 underline"><?= htmlspecialchars($contactEmail ?: ('support@' . ($_SERVER['HTTP_HOST'] ?? 'example.com'))) ?></a>.</div>
                    <?php endif; ?>
                </div>
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