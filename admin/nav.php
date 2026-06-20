<?php

// Safely access session without forcing session_start() if headers already sent.
if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        session_start();
    }
}
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
$adminName = isset($_SESSION) && isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : 'Admin';

// Helper to mark active link
function nav_item(string $href, string $label, string $faIcon, string $current): string {
    $isActive = (basename($href) === $current);
    $base = 'px-3 py-2 rounded-md text-sm font-medium flex items-center gap-2';
    $classes = $isActive
        ? 'text-white bg-gray-800'
        : 'text-gray-300 hover:text-white hover:bg-gray-800/50';
    return '<a href="' . htmlspecialchars($href) . '" class="' . $classes . ' ' . $base . '">'
         . '<i class="' . htmlspecialchars($faIcon) . '"></i>'
         . '<span>' . htmlspecialchars($label) . '</span>'
         . '</a>';
}
?>
<link rel="stylesheet" href="/assets/vendor/fontawesome/all.min.css" referrerpolicy="no-referrer" />

<!-- Navbar -->
<nav class="bg-gray-900 sticky top-0 z-40 shadow">
  <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="flex h-16 items-center justify-between">

      <!-- Left: Brand + Hamburger -->
      <div class="flex items-center">
        <a href="/admin/dashboard.php" class="flex items-center gap-2">
          <i class="fa-solid fa-chalkboard-user h-5 w-5 text-gray-200"></i>
          <h1 class="text-white text-lg sm:text-xl font-semibold">Adminpanel</h1>
        </a>
      </div>

      <!-- Desktop: Main links -->
      <div class="hidden md:flex items-center gap-1">
        <?= nav_item('/admin/whiteboards.php', 'Whiteboards', 'fa-regular fa-square', $current) ?>
        <?= nav_item('/admin/brainbreaks.php', 'Brain Breaks', 'fa-solid fa-person-running', $current) ?>
        <?= nav_item('/admin/stats.php', 'Statistik', 'fa-solid fa-chart-line', $current) ?>

        <!-- System dropdown -->
        <div class="relative" id="systemMenuWrapper">
          <button type="button" id="systemMenuButton" class="px-3 py-2 rounded-md text-sm font-medium flex items-center gap-2 text-gray-300 hover:text-white hover:bg-gray-800/50" aria-haspopup="menu" aria-expanded="false">
            <i class="fa-solid fa-server"></i>
            <span>System</span>
            <i class="fa-solid fa-caret-down"></i>
          </button>
          <div id="systemMenu" class="hidden absolute right-0 mt-2 w-52 rounded-md bg-white shadow-lg ring-1 ring-black/5 focus:outline-none">
            <a href="/admin/settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"><i class="fa-solid fa-gear mr-2"></i>Inställningar</a>
            <a href="/admin/limits.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"><i class="fa-solid fa-sliders mr-2"></i>Begränsningar</a>
            <a href="/admin/users.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"><i class="fa-regular fa-user mr-2"></i>Användare</a>
            <a href="/admin/alerts.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"><i class="fa-regular fa-bell mr-2"></i>Alerts</a>
            <a href="/admin/mfa.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"><i class="fa-solid fa-shield mr-2"></i>MFA</a>
          </div>
        </div>

        <!-- More dropdown -->
        <div class="relative" id="moreMenuWrapper">
          <button type="button" id="moreMenuButton" class="px-3 py-2 rounded-md text-sm font-medium flex items-center gap-2 text-gray-300 hover:text-white hover:bg-gray-800/50" aria-haspopup="menu" aria-expanded="false">
            <i class="fa-solid fa-ellipsis"></i>
            <span>Mer</span>
            <i class="fa-solid fa-caret-down"></i>
          </button>
          <div id="moreMenu" class="hidden absolute right-0 mt-2 w-52 rounded-md bg-white shadow-lg ring-1 ring-black/5 focus:outline-none">
            <a href="/admin/pages.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"><i class="fa-regular fa-file-lines mr-2"></i>Sidor</a>
          </div>
        </div>
      </div>

      <!-- Right: Actions (desktop) -->
      <div class="hidden md:flex items-center gap-2">

        <!-- User dropdown -->
        <div class="relative" x-data="{ open:false }">
          <button type="button" id="userMenuButton" class="rounded-full bg-gray-800 px-3 py-2 text-gray-300 hover:text-white flex items-center gap-2" aria-haspopup="menu" aria-expanded="false">
            <i class="fa-regular fa-circle-user text-lg"></i>
            <span class="hidden sm:inline"><?= htmlspecialchars($adminName) ?></span>
            <i class="fa-solid fa-caret-down"></i>
          </button>
          <div id="userMenu" class="hidden absolute right-0 mt-2 w-48 rounded-md bg-white shadow-lg ring-1 ring-black/5 focus:outline-none">
            <a href="/dashboard.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"><i class="fa-solid fa-arrow-left mr-2"></i>Lämna admin</a>
            <div class="my-1 border-t border-gray-200"></div>
            <a href="/logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50"><i class="fa-solid fa-right-from-bracket mr-2"></i>Logga ut</a>
          </div>
        </div>

      </div>

      <!-- Mobile: Hamburger -->
      <div class="md:hidden flex items-center gap-2">
        <button id="mobileMenuButton" type="button" class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 hover:text-white hover:bg-gray-800 focus:outline-none" aria-controls="mobileMenu" aria-expanded="false">
          <span class="sr-only">Öppna huvudmeny</span>
          <i class="fa-solid fa-bars text-lg"></i>
        </button>
      </div>
    </div>
  </div>

  <!-- Mobile menu -->
  <div id="mobileMenu" class="md:hidden hidden border-t border-gray-800">
    <div class="space-y-1 px-2 py-3">
      <?= nav_item('/admin/whiteboards.php', 'Whiteboards', 'fa-regular fa-square', $current) ?>
      <?= nav_item('/admin/brainbreaks.php', 'Brain Breaks', 'fa-solid fa-person-running', $current) ?>
      <?= nav_item('/admin/stats.php', 'Statistik', 'fa-solid fa-chart-line', $current) ?>

      <div class="px-3 pt-3 text-xs uppercase tracking-wider text-gray-400">System</div>
      <a href="/admin/settings.php" class="block px-3 py-2 rounded-md text-sm font-medium text-gray-300 hover:bg-gray-800/50"><i class="fa-solid fa-gear mr-2"></i>Inställningar</a>
      <a href="/admin/limits.php" class="block px-3 py-2 rounded-md text-sm font-medium text-gray-300 hover:bg-gray-800/50"><i class="fa-solid fa-sliders mr-2"></i>Begränsningar</a>
      <a href="/admin/users.php" class="block px-3 py-2 rounded-md text-sm font-medium text-gray-300 hover:bg-gray-800/50"><i class="fa-regular fa-user mr-2"></i>Användare</a>
      <a href="/admin/alerts.php" class="block px-3 py-2 rounded-md text-sm font-medium text-gray-300 hover:bg-gray-800/50"><i class="fa-regular fa-bell mr-2"></i>Alerts</a>
      <a href="/admin/mfa.php" class="block px-3 py-2 rounded-md text-sm font-medium text-gray-300 hover:bg-gray-800/50"><i class="fa-solid fa-shield mr-2"></i>MFA</a>

      <div class="px-3 pt-3 text-xs uppercase tracking-wider text-gray-400">Mer</div>
      <a href="/admin/pages.php" class="block px-3 py-2 rounded-md text-sm font-medium text-gray-300 hover:bg-gray-800/50"><i class="fa-regular fa-file-lines mr-2"></i>Sidor</a>

      <div class="px-3 pt-3 text-xs uppercase tracking-wider text-gray-400">Konto</div>
      <a href="/dashboard.php" class="block px-3 py-2 rounded-md text-sm font-medium text-gray-300 hover:bg-gray-800/50"><i class="fa-solid fa-arrow-left mr-2"></i>Lämna admin</a>
      <a href="/logout.php" class="block px-3 py-2 rounded-md text-sm font-medium text-red-500 hover:bg-gray-800/50"><i class="fa-solid fa-right-from-bracket mr-2"></i>Logga ut</a>
    </div>
  </div>
</nav>

<script>
// --- Mobile menu toggle ---
(function () {
  const btn = document.getElementById('mobileMenuButton');
  const menu = document.getElementById('mobileMenu');
  if (btn && menu) {
    btn.addEventListener('click', () => {
      const isHidden = menu.classList.contains('hidden');
      btn.setAttribute('aria-expanded', String(isHidden));
      menu.classList.toggle('hidden');
    });
  }
})();

// --- User dropdown (tiny vanilla JS) ---
(function () {
  const btn = document.getElementById('userMenuButton');
  const menu = document.getElementById('userMenu');
  if (!btn || !menu) return;
  function close() { menu.classList.add('hidden'); btn.setAttribute('aria-expanded', 'false'); }
  function open() { menu.classList.remove('hidden'); btn.setAttribute('aria-expanded', 'true'); }
  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    menu.classList.contains('hidden') ? open() : close();
  });
  document.addEventListener('click', (e) => {
    if (!menu.contains(e.target) && e.target !== btn) close();
  });
})();

// --- More dropdown ---
(function () {
  const btn = document.getElementById('moreMenuButton');
  const menu = document.getElementById('moreMenu');
  if (!btn || !menu) return;
  function close() { menu.classList.add('hidden'); btn.setAttribute('aria-expanded', 'false'); }
  function open() { menu.classList.remove('hidden'); btn.setAttribute('aria-expanded', 'true'); }
  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    menu.classList.contains('hidden') ? open() : close();
  });
  document.addEventListener('click', (e) => {
    if (!menu.contains(e.target) && e.target !== btn) close();
  });
})();

// --- System dropdown ---
(function () {
  const btn = document.getElementById('systemMenuButton');
  const menu = document.getElementById('systemMenu');
  if (!btn || !menu) return;
  function close() { menu.classList.add('hidden'); btn.setAttribute('aria-expanded', 'false'); }
  function open() { menu.classList.remove('hidden'); btn.setAttribute('aria-expanded', 'true'); }
  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    menu.classList.contains('hidden') ? open() : close();
  });
  document.addEventListener('click', (e) => {
    if (!menu.contains(e.target) && e.target !== btn) close();
  });
})();
</script>