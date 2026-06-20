<?php
// admin/pages.php — enkel CMS-sida för statiska sidor
session_start();
require_once __DIR__ . '/../src/Config/Database.php';

// Behörighet: endast admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Endast administratörer har behörighet.';
    exit;
}

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

try {
    $db = new Database();
    $pdo = $db->getConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Kunde inte ansluta till databasen.';
    exit;
}

// Hjälp: hämta setting (om tabellen/systemet finns)
function get_setting_local(PDO $pdo, string $key, $default = ''): string {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== null) ? (string)$val : (string)$default;
    } catch (Throwable $e) {
        return (string)$default;
    }
}

$siteName = get_setting_local($pdo, 'site_name', 'Klassrumsverktyg');

// Processa POST (spara sida)
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_page') {
    $id       = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $slug     = trim($_POST['slug'] ?? '');
    $title    = trim($_POST['title'] ?? '');
    $format   = 'html'; // HTML-only
    $content  = $_POST['content'] ?? '';
    $published= isset($_POST['is_published']) ? 1 : 0;

    if ($slug === '' || !preg_match('/^[a-z0-9-]+$/i', $slug)) {
        $notice = 'Ogiltig slug. Använd a–z, 0–9 och bindestreck.';
    } elseif ($title === '') {
        $notice = 'Titel krävs.';
    // HTML-only: ingen formatvalidering behövs
    } else {
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE pages SET title=?, content=?, format=?, is_published=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$title, $content, $format, $published, $id]);
                $notice = 'Sidan uppdaterad.';
            } else {
                // kontrollera unik slug
                $chk = $pdo->prepare("SELECT id FROM pages WHERE slug = ? LIMIT 1");
                $chk->execute([$slug]);
                if ($chk->fetch()) {
                    $notice = 'Slug används redan.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO pages (slug, title, content, format, is_published, updated_at) VALUES (?,?,?,?,?, NOW())");
                    $stmt->execute([$slug, $title, $content, $format, $published]);
                    $notice = 'Sidan skapad.';
                }
            }
        } catch (Throwable $e) {
            $notice = 'Ett fel uppstod vid sparande.';
        }
    }
}

// Läs listan av sidor
$pages = [];
try {
    $q = $pdo->query("SELECT id, slug, title, format, is_published, updated_at FROM pages ORDER BY slug ASC");
    $pages = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $pages = [];
}

// Om vi editerar en sida
$edit = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM pages WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Ny sida
if (isset($_GET['new'])) {
    $edit = [
        'id' => 0,
        'slug' => '',
        'title' => '',
        'content' => '',
        'format' => 'html',
        'is_published' => 1,
    ];
}
?>
<!doctype html>
<html lang="sv">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Sidor – <?= h($siteName) ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/vendor/fontawesome/all.min.css">
  <style>
    .prose { max-width: 65ch; }
    textarea { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  </style>
</head>
<body class="bg-gray-100">
<?php include 'nav.php'; ?>
<div class="container mx-auto px-4 py-8">
  <div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold">Sidor</h1>
    <div class="flex gap-2">
      <a href="pages.php?new=1" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg inline-flex items-center"><i class="fa fa-plus mr-2"></i>Ny sida</a>
    </div>
  </div>

  <?php if ($notice): ?>
    <div class="mb-6 rounded border border-<?= str_starts_with($notice,'Ett fel') || str_starts_with($notice,'Ogilt') ? 'red' : 'green' ?>-200 bg-<?= str_starts_with($notice,'Ett fel') || str_starts_with($notice,'Ogilt') ? 'red' : 'green' ?>-50 text-<?= str_starts_with($notice,'Ett fel') || str_starts_with($notice,'Ogilt') ? 'red' : 'green' ?>-800 p-3">
      <?= h($notice) ?>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-1 bg-white rounded-lg shadow p-4">
      <h2 class="font-semibold mb-3">Alla sidor</h2>
      <ul class="divide-y">
        <?php if (!$pages): ?>
          <li class="py-3 text-gray-500">Inga sidor ännu.</li>
        <?php endif; ?>
        <?php foreach ($pages as $p): ?>
          <li class="py-3 flex items-center justify-between">
            <div>
              <div class="font-medium"><?= h($p['title']) ?></div>
              <div class="text-xs text-gray-500">slug: <code><?= h($p['slug']) ?></code> • uppdaterad: <?= h($p['updated_at']) ?></div>
            </div>
            <div class="flex items-center gap-2">
              <?php if ((int)$p['is_published'] === 1): ?><span class="text-green-600 text-xs">• publicerad</span><?php else: ?><span class="text-gray-500 text-xs">• av</span><?php endif; ?>
              <a href="pages.php?edit=<?= (int)$p['id'] ?>" class="text-blue-600 hover:text-blue-800 text-sm">Redigera</a>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="lg:col-span-2 bg-white rounded-lg shadow p-4">
      <?php if ($edit): ?>
        <h2 class="font-semibold mb-3"><?= $edit['id'] ? 'Redigera sida' : 'Ny sida' ?></h2>
        <form method="post" class="space-y-4">
          <input type="hidden" name="action" value="save_page">
          <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Slug</label>
            <input name="slug" type="text" value="<?= h($edit['slug']) ?>" class="w-full border rounded-lg px-3 py-2" placeholder="about" <?= $edit['id'] ? 'readonly class="w-full border rounded-lg px-3 py-2 bg-gray-100"' : '' ?> >
            <p class="text-xs text-gray-500 mt-1">Använd a–z, 0–9, bindestreck. Ex: <code>about</code>, <code>privacy</code>.</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Titel</label>
            <input name="title" type="text" value="<?= h($edit['title']) ?>" class="w-full border rounded-lg px-3 py-2" required>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="flex items-end">
              <label class="inline-flex items-center"><input type="checkbox" name="is_published" <?= ((int)($edit['is_published'] ?? 1)===1)?'checked':''; ?> class="mr-2">Publicerad</label>
            </div>
          </div>
          <input type="hidden" name="format" value="html">

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Innehåll</label>
            <textarea id="contentInput" name="content" rows="18" class="w-full border rounded-lg px-3 py-2" placeholder="<!-- Skriv HTML här (t.ex. <h2>Rubrik</h2><p>Text…</p>) -->"><?= h($edit['content']) ?></textarea>
            <div class="mt-4">
              <div class="flex items-center justify-between mb-1">
                <h3 class="text-sm font-medium text-gray-700">Förhandsgranskning</h3>
                <button type="button" id="togglePreview" class="text-xs text-blue-600 hover:underline">Dölj</button>
              </div>
              <div id="preview" class="prose border rounded-lg p-4 bg-gray-50 overflow-auto"></div>
            </div>
          </div>

          <div class="flex items-center gap-2">
            <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg inline-flex items-center"><i class="fa fa-save mr-2"></i>Spara</button>
            <a href="pages.php" class="text-gray-600 hover:text-gray-800 px-3 py-2">Avbryt</a>
          </div>
        </form>
      <?php else: ?>
        <div class="text-gray-600">
          <p>Välj en sida till vänster eller skapa en ny.</p>
          <p class="mt-2 text-sm">Tips: skapa en sida med slug <code>about</code> för att ersätta <em>Om</em>-sidan, eller t.ex. <code>privacy</code> för integritetspolicy.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
  const textarea = document.getElementById('contentInput');
  const preview = document.getElementById('preview');
  function updatePreview() { if (preview) preview.innerHTML = textarea ? textarea.value : ''; }
  if (textarea && preview) {
    textarea.addEventListener('input', updatePreview);
    window.addEventListener('DOMContentLoaded', updatePreview);
  }
  const toggleBtn = document.getElementById('togglePreview');
  if (toggleBtn && preview) {
    const pref = localStorage.getItem('pages_preview_visible');
    if (pref === '0') { preview.style.display = 'none'; toggleBtn.textContent = 'Visa'; }
    toggleBtn.addEventListener('click', () => {
      if (preview.style.display === 'none') {
        preview.style.display = '';
        toggleBtn.textContent = 'Dölj';
        localStorage.setItem('pages_preview_visible','1');
      } else {
        preview.style.display = 'none';
        toggleBtn.textContent = 'Visa';
        localStorage.setItem('pages_preview_visible','0');
      }
    });
  }
</script>
</body>
</html>
