<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
require_once __DIR__ . '/../src/Config/Database.php';


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

require_once 'AdminController.php';
$admin = new AdminController($db);
$users = $admin->listUsers();

// Förbättrad version av user listing som bör innehålla auth_type
// För att stödja detta, behöver vi uppdatera AdminController.php eller lägga till fallback
// för att hantera när auth_type inte finns i databasen
if (!isset($users[0]['auth_type'])) {
    // Uppdatera SQL-frågan om den körs direkt här istället för via AdminController
    $query = "SELECT u.*, 'Manuell' AS auth_type_guess
              FROM users u 
              ORDER BY u.created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$usersPerPage = 10;  // Antal användare per sida
$totalUsers = count($users);
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$totalPages = ceil($totalUsers / $usersPerPage);

// Se till att current page inte är utanför gränserna
$currentPage = min($currentPage, max(1, $totalPages));

// Beräkna start- och slutindex för aktuell sida
$startIndex = ($currentPage - 1) * $usersPerPage;
$endIndex = min($startIndex + $usersPerPage, $totalUsers);

// Sortera användare efter svenska alfabetet (innan paginering)
setlocale(LC_COLLATE, 'sv_SE.UTF-8');
usort($users, function($a, $b) {
    return strcoll(
        mb_strtolower(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''), 'UTF-8'),
        mb_strtolower(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''), 'UTF-8')
    );
});

// Aktiva användare beräknas efter filtrering och sortering
$activeUsersCount = array_reduce($users, function($count, $user) {
    return $count + ($user['is_active'] ? 1 : 0);
}, 0);

// Hämta endast användare för den aktuella sidan
$pageUsers = array_slice($users, $startIndex, $usersPerPage);
$teacherUsers = array_reduce($users, function($count, $user) {
    return $count + ($user['role'] === 'teacher' ? 1 : 0);
}, 0);
$adminUsers = array_reduce($users, function($count, $user) {
    return $count + ($user['role'] === 'admin' ? 1 : 0);
}, 0);

// Google vs. Manuell statistik
$googleUsers = array_reduce($users, function($count, $user) {
    $authTypeRaw = strtolower(trim($user['auth_type'] ?? ''));
    return $count + ($authTypeRaw === 'google' ? 1 : 0);
}, 0);
$manualUsers = $totalUsers - $googleUsers;
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title>Hantera användare - <?= htmlspecialchars($siteName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/vendor/fontawesome/all.min.css">
</head>
<body class="bg-gray-100">

<?php include_once 'nav.php'; ?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800">Hantera användare</h1>
        <span class="text-sm text-gray-500">Senast uppdaterad: <?= date('Y-m-d H:i') ?></span>
    </div>
    
    <!-- Översiktsstatistik -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Totalt antal användare -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-blue-500">
            <div class="flex justify-between items-center">
                <div class="text-sm font-medium text-gray-500">Totalt antal användare</div>
                <i class="fas fa-users text-blue-500"></i>
            </div>
            <div class="mt-2 text-3xl font-semibold"><?= $totalUsers ?></div>
            <div class="text-sm text-gray-500 mt-2">
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-blue-500 h-2 rounded-full" style="width: <?= round(($activeUsersCount / max(1, $totalUsers)) * 100) ?>%"></div>
                </div>
                <div class="flex justify-between mt-1">
                    <span><?= $activeUsersCount ?> aktiva</span>
                    <span><?= $totalUsers - $activeUsersCount ?> inaktiva</span>
                </div>
            </div>
        </div>

        <!-- Roller -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-green-500">
            <div class="flex justify-between items-center">
                <div class="text-sm font-medium text-gray-500">Användarroller</div>
                <i class="fas fa-user-tag text-green-500"></i>
            </div>
            <div class="mt-2 text-3xl font-semibold"><?= $teacherUsers ?></div>
            <div class="text-sm text-gray-500 mt-2 flex justify-between">
                <span><?= $teacherUsers ?> lärare</span>
                <span><?= $adminUsers ?> administratörer</span>
            </div>
        </div>
        
        <!-- Autentiseringsmetod -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-purple-500">
            <div class="flex justify-between items-center">
                <div class="text-sm font-medium text-gray-500">Autentiseringsmetod</div>
                <i class="fas fa-sign-in-alt text-purple-500"></i>
            </div>
            <div class="mt-2 flex">
                <div>
                    <div class="flex items-center">
                        <div class="w-3 h-3 rounded-full bg-purple-500 mr-2"></div>
                        <span class="text-sm">Google</span>
                    </div>
                    <div class="text-xl font-semibold ml-5"><?= $googleUsers ?></div>
                </div>
                <div class="ml-6">
                    <div class="flex items-center">
                        <div class="w-3 h-3 rounded-full bg-indigo-500 mr-2"></div>
                        <span class="text-sm">Manuell</span>
                    </div>
                    <div class="text-xl font-semibold ml-5"><?= $manualUsers ?></div>
                </div>
            </div>
        </div>

        <!-- Åtgärder -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-yellow-500">
            <div class="flex justify-between items-center mb-3">
                <div class="text-sm font-medium text-gray-500">Snabbåtgärder</div>
                <i class="fas fa-bolt text-yellow-500"></i>
            </div>
            <div class="space-y-2">
                <button onclick="showAddUserModal()" class="w-full bg-blue-500 hover:bg-blue-600 text-white py-2 px-4 rounded transition flex items-center justify-center">
                    <i class="fas fa-user-plus mr-2"></i>
                    Lägg till ny användare
                </button>
                <button onclick="exportUsers()" class="w-full bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded transition flex items-center justify-center">
                    <i class="fas fa-file-export mr-2"></i>
                    Exportera lista
                </button>
                <button onclick="showImportModal()" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white py-2 px-4 rounded transition flex items-center justify-center">
                    <i class="fas fa-file-import mr-2"></i>
                    Importera användare (CSV)
                </button>
            </div>
        </div>
    </div>
    
    <!-- Användartabell -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="border-b border-gray-200 px-6 py-4 flex justify-between items-center">
            <h2 class="text-xl font-semibold text-gray-800">Användarlista</h2>
            <div class="relative">
                <input id="userSearch" type="text" placeholder="Sök användare..." class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-64 pl-10 p-2.5">
                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                    <i class="fas fa-search text-gray-500"></i>
                </div>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 table-fixed">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/6">
                            Namn
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/6">
                            Email
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/12">
                            Roll
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/6">
                            Skola
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/12">
                            Inloggning
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/12">
                            Status
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/6">
                            Åtgärder
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php 
                    foreach ($pageUsers as $user): 
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 h-10 w-10 flex items-center justify-center rounded-full bg-gray-100 text-gray-500">
                                    <?php 
                                    // Visa initialer från namn
                                    $initials = mb_strtoupper(mb_substr($user['first_name'], 0, 1, 'UTF-8') . mb_substr($user['last_name'], 0, 1, 'UTF-8'), 'UTF-8');
                                    echo htmlspecialchars($initials);
                                    ?>
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-900 truncate max-w-[180px]" title="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>">
                                        <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>
                                    </div>
                                    <div class="text-xs text-gray-500 truncate max-w-[180px]" title="<?= htmlspecialchars($user['username']) ?>">
                                        <?= htmlspecialchars($user['username']) ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm text-gray-900 truncate max-w-[180px]" title="<?= htmlspecialchars($user['email']) ?>"><?= htmlspecialchars($user['email']) ?></div>
                            <div class="text-xs text-gray-500">
                                <?php 
                                $lastLogin = $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : 'Aldrig';
                                echo "Senast: " . $lastLogin;
                                ?>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                      <?= $user['role'] === 'admin' ? 'bg-purple-100 text-purple-800' : 'bg-green-100 text-green-800' ?>">
                                <?= $user['role'] === 'admin' ? 'Admin' : 'Lärare' ?>
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <div class="truncate max-w-[140px]" title="<?= htmlspecialchars($user['school'] ?? '-') ?>">
                                <?= htmlspecialchars($user['school'] ?? '-') ?>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <?php
                            // Bestäm autentiseringsmetod
                            $authTypeRaw = strtolower(trim($user['auth_type'] ?? ''));
                            $isGoogle = ($authTypeRaw === 'google');
                            ?>
                            <div class="flex items-center">
                                <?php if ($isGoogle): ?>
                                <i class="fab fa-google text-red-500"></i>
                                <span class="ml-1.5 text-sm">Google</span>
                                <?php else: ?>
                                <i class="fas fa-key text-gray-500"></i>
                                <span class="ml-1.5 text-sm">Manuell</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                       <?= $user['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                <?= $user['is_active'] ? 'Aktiv' : 'Inaktiv' ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <div class="flex space-x-1 flex-wrap gap-y-1">
                                <button onclick="editUser(<?= $user['id'] ?>)" 
                                        class="text-indigo-600 hover:text-indigo-900 flex items-center mr-1">
                                    <i class="fas fa-edit mr-1"></i>
                                    <span class="hidden sm:inline">Redigera</span>
                                </button>
                                <button onclick="resetPassword(<?= $user['id'] ?>)"
                                        class="text-yellow-600 hover:text-yellow-900 flex items-center mr-1">
                                    <i class="fas fa-key mr-1"></i>
                                    <span class="hidden sm:inline">Återställ</span>
                                </button>
                                <button onclick="toggleStatus(<?= $user['id'] ?>, <?= $user['is_active'] ? 0 : 1 ?>)"
                                        class="<?= $user['is_active'] ? 'text-gray-600 hover:text-gray-900' : 'text-green-600 hover:text-green-900' ?> flex items-center mr-1">
                                    <i class="fas <?= $user['is_active'] ? 'fa-ban' : 'fa-check' ?> mr-1"></i>
                                    <span class="hidden sm:inline"><?= $user['is_active'] ? 'Inaktivera' : 'Aktivera' ?></span>
                                </button>
                                <button onclick="deleteUser(<?= $user['id'] ?>)"
                                        class="text-red-600 hover:text-red-900 flex items-center">
                                    <i class="fas fa-trash mr-1"></i>
                                    <span class="hidden sm:inline">Ta bort</span>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Paginering -->
        <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
            <div class="text-sm text-gray-500">
                Visar <?= $startIndex + 1 ?>-<?= $endIndex ?> av <?= $totalUsers ?> användare
            </div>
            
            <?php if ($totalPages > 1): ?>
            <div class="flex space-x-2">
                <?php if ($currentPage > 1): ?>
                <a href="?page=<?= $currentPage - 1 ?>" class="px-3 py-1 rounded border border-gray-300 bg-white text-gray-700 hover:bg-gray-50">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php endif; ?>
                
                <?php 
                // Visa max 5 sidnummer
                $startPage = max(1, $currentPage - 2);
                $endPage = min($totalPages, $startPage + 4);
                
                // Justera startPage om vi är nära slutet
                if ($endPage == $totalPages && $endPage - 4 > 0) {
                    $startPage = max(1, $endPage - 4);
                }
                
                for ($i = $startPage; $i <= $endPage; $i++): 
                ?>
                <a href="?page=<?= $i ?>" class="px-3 py-1 rounded border <?= $i == $currentPage ? 'bg-blue-500 text-white border-blue-500' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' ?>">
                    <?= $i ?>
                </a>
                <?php endfor; ?>
                
                <?php if ($currentPage < $totalPages): ?>
                <a href="?page=<?= $currentPage + 1 ?>" class="px-3 py-1 rounded border border-gray-300 bg-white text-gray-700 hover:bg-gray-50">
                    <i class="fas fa-chevron-right"></i>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Import-modal -->
<div id="importModal" class="fixed inset-0 z-40 hidden">
  <div class="absolute inset-0 bg-black/50" onclick="hideImportModal()"></div>
  <div class="relative z-10 max-w-2xl mx-auto my-10 bg-white rounded-lg shadow-xl">
    <div class="px-6 py-4 border-b flex items-center justify-between">
      <h3 class="text-lg font-semibold">Importera användare</h3>
      <button class="text-gray-500 hover:text-gray-800" onclick="hideImportModal()"><i class="fas fa-times"></i></button>
    </div>
    <form id="importForm" class="p-6" enctype="multipart/form-data">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Fil (CSV) *</label>
          <input id="importFile" name="file" type="file" accept=".csv" required class="w-full border rounded-lg p-2">
          <p class="text-xs text-gray-500 mt-2">Max 10 MB. Förväntade kolumner: <code class="bg-gray-100 px-1 rounded">email</code>, <code class="bg-gray-100 px-1 rounded">first_name</code>, <code class="bg-gray-100 px-1 rounded">last_name</code>, <code class="bg-gray-100 px-1 rounded">role</code> (admin/teacher), <code class="bg-gray-100 px-1 rounded">school</code>, <code class="bg-gray-100 px-1 rounded">is_active</code> (0/1), <code class="bg-gray-100 px-1 rounded">password</code> (valfri).</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Fältseparator (CSV)</label>
          <select id="csvDelimiter" name="delimiter" class="w-full border rounded-lg p-2">
            <option value=",">Komma (,)</option>
            <option value=";">Semikolon (;)</option>
            <option value="\t">Tabb (\t)</option>
          </select>
          <div class="mt-4">
            <label class="inline-flex items-center">
              <input id="hasHeader" name="has_header" type="checkbox" class="mr-2" checked>
              <span class="text-sm">Första raden är rubriker</span>
            </label>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-6">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Standardroll</label>
          <select id="defaultRole" name="default_role" class="w-full border rounded-lg p-2">
            <option value="teacher">Lärare</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Aktivera användare</label>
          <select id="defaultActive" name="default_active" class="w-full border rounded-lg p-2">
            <option value="1">Ja</option>
            <option value="0">Nej</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Lösenord</label>
          <select id="passwordStrategy" name="password_strategy" class="w-full border rounded-lg p-2">
            <option value="provided_or_generate">Använd "password"-kolumn eller generera</option>
            <option value="generate_always">Alltid generera</option>
            <option value="reject_missing">Avvisa rader utan lösenord</option>
          </select>
        </div>
      </div>

      <div class="mt-6 flex items-center justify-between">
        <div class="text-sm text-gray-500">
          <a href="/admin/api/import_users.php?template=csv" class="hover:underline"><i class="fas fa-download mr-1"></i>Ladda ner CSV‑mall</a>
        </div>
        <div class="flex gap-3">
          <button type="button" class="px-4 py-2 rounded-lg border" onclick="hideImportModal()">Avbryt</button>
          <button id="importSubmit" type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white flex items-center">
            <i class="fas fa-file-import mr-2"></i> Importera
          </button>
        </div>
      </div>

      <div id="importFeedback" class="mt-4 hidden"></div>
    </form>
  </div>
</div>

<!-- Add User modal -->
<div id="addUserModal" class="fixed inset-0 z-40 hidden">
  <div class="absolute inset-0 bg-black/50" onclick="hideAddUserModal()"></div>
  <div class="relative z-10 max-w-xl mx-auto my-10 bg-white rounded-lg shadow-xl">
    <div class="px-6 py-4 border-b flex items-center justify-between">
      <h3 class="text-lg font-semibold">Lägg till användare</h3>
      <button class="text-gray-500 hover:text-gray-800" onclick="hideAddUserModal()"><i class="fas fa-times"></i></button>
    </div>
    <form id="addUserForm" class="p-6">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Förnamn *</label>
          <input type="text" name="first_name" required class="w-full border rounded-lg p-2" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Efternamn *</label>
          <input type="text" name="last_name" required class="w-full border rounded-lg p-2" />
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">E‑post *</label>
          <input type="email" name="email" required class="w-full border rounded-lg p-2" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Roll</label>
          <select name="role" class="w-full border rounded-lg p-2">
            <option value="teacher">Lärare</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Skola</label>
          <input type="text" name="school" class="w-full border rounded-lg p-2" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Aktiv</label>
          <select name="is_active" class="w-full border rounded-lg p-2">
            <option value="1">Ja</option>
            <option value="0">Nej</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Användarnamn (valfritt)</label>
          <input type="text" name="username" class="w-full border rounded-lg p-2" placeholder="Lämna tomt för auto" />
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Lösenord (valfritt)</label>
          <input type="text" name="password" class="w-full border rounded-lg p-2" placeholder="Lämna tomt för att generera & tvinga byte" />
        </div>
      </div>
      <div class="mt-6 flex items-center justify-between">
        <div id="addUserFeedback" class="text-sm text-gray-500"></div>
        <div class="flex gap-3">
          <button type="button" class="px-4 py-2 rounded-lg border" onclick="hideAddUserModal()">Avbryt</button>
          <button id="addUserSubmit" type="submit" class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white flex items-center">
            <i class="fas fa-user-plus mr-2"></i> Skapa
          </button>
        </div>
      </div>
    </form>
  </div>
</div>
<!-- Edit User modal -->
<div id="editUserModal" class="fixed inset-0 z-40 hidden">
  <div class="absolute inset-0 bg-black/50" onclick="hideEditUserModal()"></div>
  <div class="relative z-10 max-w-xl mx-auto my-10 bg-white rounded-lg shadow-xl">
    <div class="px-6 py-4 border-b flex items-center justify-between">
      <h3 class="text-lg font-semibold">Redigera användare</h3>
      <button class="text-gray-500 hover:text-gray-800" onclick="hideEditUserModal()"><i class="fas fa-times"></i></button>
    </div>
    <form id="editUserForm" class="p-6">
      <input type="hidden" name="id" />
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Förnamn *</label>
          <input type="text" name="first_name" required class="w-full border rounded-lg p-2" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Efternamn *</label>
          <input type="text" name="last_name" required class="w-full border rounded-lg p-2" />
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">E‑post *</label>
          <input type="email" name="email" required class="w-full border rounded-lg p-2" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Roll</label>
          <select name="role" class="w-full border rounded-lg p-2">
            <option value="teacher">Lärare</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Skola</label>
          <input type="text" name="school" class="w-full border rounded-lg p-2" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Aktiv</label>
          <select name="is_active" class="w-full border rounded-lg p-2">
            <option value="1">Ja</option>
            <option value="0">Nej</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Användarnamn</label>
          <input type="text" name="username" class="w-full border rounded-lg p-2" />
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Nytt lösenord (valfritt)</label>
          <input type="password" name="password" class="w-full border rounded-lg p-2" placeholder="Lämna tomt för att behålla nuvarande lösenord" />
        </div>
      </div>
      <div class="mt-6 flex items-center justify-between">
        <div id="editUserFeedback" class="text-sm text-gray-500"></div>
        <div class="flex gap-3">
          <button type="button" class="px-4 py-2 rounded-lg border" onclick="hideEditUserModal()">Avbryt</button>
          <button id="editUserSubmit" type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white flex items-center">
            <i class="fas fa-save mr-2"></i> Spara
          </button>
        </div>
      </div>
    </form>
  </div>
</div>
<!-- Modaler och JavaScript -->
<script>
// ===== Import-modal logik =====
function showImportModal(){
  document.getElementById('importModal').classList.remove('hidden');
}
function hideImportModal(){
  document.getElementById('importModal').classList.add('hidden');
}

// Hantera submit
const importForm = document.getElementById('importForm');
if (importForm) {
  importForm.addEventListener('submit', function(e){
    e.preventDefault();

    const fileInput = document.getElementById('importFile');
    if (!fileInput.files || !fileInput.files[0]) {
      showImportFeedback('Välj en fil först.', 'error');
      return;
    }

    const btn = document.getElementById('importSubmit');
    btn.disabled = true;
    btn.classList.add('opacity-75');

    const formData = new FormData(importForm);

    fetch('/admin/api/import_users.php', {
      method: 'POST',
      body: formData
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showImportFeedback(`Import klar: ${data.imported} importerade, ${data.skipped || 0} hoppade över.`, 'success');

        // Om API:t returnerar temp-lösenord som CSV (base64), erbjud nedladdning
        if (data.temp_csv_b64) {
          try {
            const byteChars = atob(data.temp_csv_b64);
            const byteNumbers = new Array(byteChars.length);
            for (let i = 0; i < byteChars.length; i++) {
              byteNumbers[i] = byteChars.charCodeAt(i);
            }
            const byteArray = new Uint8Array(byteNumbers);
            const blob = new Blob([byteArray], { type: 'text/csv;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = (data.temp_csv_filename || 'users-temp-passwords.csv');
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 2000);
          } catch (e) {
            console.warn('Kunde inte skapa CSV-nedladdning:', e);
          }
        }

        setTimeout(() => { location.reload(); }, 1400);
      } else {
        showImportFeedback(data.message || 'Importen misslyckades.', 'error');
      }
    })
    .catch(err => {
      console.error(err);
      showImportFeedback('Ett oväntat fel inträffade vid import.', 'error');
    })
    .finally(() => {
      btn.disabled = false;
      btn.classList.remove('opacity-75');
    });
  });
}

function showImportFeedback(msg, type){
  const el = document.getElementById('importFeedback');
  el.className = 'mt-4 rounded-lg p-3 ' + (type === 'success' ? 'bg-green-50 border border-green-200 text-green-800' : 'bg-red-50 border border-red-200 text-red-800');
  el.innerHTML = `<div class="flex items-center"><i class="fas ${type==='success'?'fa-check-circle':'fa-exclamation-triangle'} mr-2"></i><span>${msg}</span></div>`;
  el.classList.remove('hidden');
}

document.addEventListener('DOMContentLoaded', function() {
    // Sökfunktion för användartabellen
    const userSearch = document.getElementById('userSearch');
    if (userSearch) {
        userSearch.addEventListener('keyup', function() {
            const searchQuery = this.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(searchQuery)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }
});

function editUser(userId) {
  // Öppna modal
  document.getElementById('editUserModal').classList.remove('hidden');
  // Töm ev. tidigare feedback
  const fb = document.getElementById('editUserFeedback'); if (fb) fb.textContent = '';
  // Hämta och fyll i data
  apiFetch(`${API_BASE}?action=get-user&id=${userId}`, { method: 'GET' })
    .then(data => {
      const u = data.user || data; // stöd för {user:{...}} eller direkt objekt
      const form = document.getElementById('editUserForm');
      form.elements['id'].value = u.id || userId;
      form.elements['first_name'].value = u.first_name || '';
      form.elements['last_name'].value = u.last_name || '';
      form.elements['email'].value = u.email || '';
      form.elements['role'].value = (u.role === 'admin' ? 'admin' : 'teacher');
      form.elements['school'].value = u.school || '';
      form.elements['is_active'].value = (String(u.is_active ?? '1') === '0' ? '0' : '1');
      form.elements['username'].value = u.username || '';
    })
    .catch(err => {
      const fb = document.getElementById('editUserFeedback');
      fb.className = 'text-sm text-red-700';
      fb.textContent = 'Kunde inte läsa användardata: ' + err.message;
    });
}
function hideEditUserModal(){
  document.getElementById('editUserModal').classList.add('hidden');
}

// ====== API helper ======
const API_BASE = '/admin/api/users.php';
async function apiFetch(url, options = {}) {
  const meta = document.querySelector('meta[name="csrf-token"]');
  const csrf = meta ? meta.getAttribute('content') : null;
  const baseHeaders = csrf ? { 'X-CSRF-Token': csrf } : {};
  const opts = {
    ...options,
    headers: { ...(options.headers || {}), ...baseHeaders }
  };
  const res = await fetch(url, opts);
  let data = null;
  try { data = await res.json(); } catch(_) {}
  if (!res.ok) {
    const msg = (data && data.message) ? data.message : `HTTP ${res.status} ${res.statusText}`;
    throw new Error(msg);
  }
  return data || { success: true };
}

function resetPassword(userId) {
    if (confirm('Är du säker på att du vill återställa lösenordet?')) {
        apiFetch(`${API_BASE}?action=reset-password&id=${userId}`, { method: 'POST' })
          .then(data => {
            if (data.success) alert('Ett återställningsmail har skickats till användaren');
            else alert('Ett fel uppstod: ' + (data.message || 'Okänt fel'));
          })
          .catch(err => alert('Kunde inte återställa lösenordet: ' + err.message + '\nKontrollera att endpointen finns.'));
    }
}

function toggleStatus(userId, status) {
    const action = status ? 'aktivera' : 'inaktivera';
    if (confirm(`Är du säker på att du vill ${action} användaren?`)) {
        apiFetch(`${API_BASE}?action=toggle-user-status&id=${userId}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ status: status })
        })
          .then(data => { if (data.success) location.reload(); else alert('Ett fel uppstod: ' + (data.message || 'Okänt fel')); })
          .catch(err => alert('Kunde inte ändra status: ' + err.message + '\nKontrollera att endpointen finns.'));
    }
}

function deleteUser(userId) {
    if (confirm('Är du säker på att du vill ta bort användaren? Detta tar även bort alla whiteboards.')) {
        apiFetch(`${API_BASE}?action=delete-user&id=${userId}`, { method: 'DELETE' })
          .then(data => { if (data.success) location.reload(); else alert('Ett fel uppstod: ' + (data.message || 'Okänt fel')); })
          .catch(err => alert('Kunde inte ta bort användaren: ' + err.message + '\nKontrollera att endpointen finns.'));
    }
}

function showAddUserModal(){
  document.getElementById('addUserModal').classList.remove('hidden');
}
function hideAddUserModal(){
  document.getElementById('addUserModal').classList.add('hidden');
}

function exportUsers() {
    // Implementera exportfunktion
    window.location.href = '/admin/export-users.php';
}
// ===== AddUser-modal logik =====
const addUserForm = document.getElementById('addUserForm');
if (addUserForm) {
  addUserForm.addEventListener('submit', function(e){
    e.preventDefault();
    const btn = document.getElementById('addUserSubmit');
    btn.disabled = true; btn.classList.add('opacity-75');

    const formObj = Object.fromEntries(new FormData(addUserForm).entries());
    formObj.mode = 'single';

    (function(){
      const meta = document.querySelector('meta[name="csrf-token"]');
      const csrf = meta ? meta.getAttribute('content') : null;
      fetch('/admin/api/import_users.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', ...(csrf ? {'X-CSRF-Token': csrf} : {}) },
        body: JSON.stringify(formObj)
      })
      .then(r => r.json())
      .then(data => {
        const fb = document.getElementById('addUserFeedback');
        if (data.success) {
          fb.className = 'text-sm text-green-700';
          fb.innerHTML = '<i class="fas fa-check-circle mr-1"></i> Användare skapad';

          if (data.temp_csv_b64) {
            try {
              const byteChars = atob(data.temp_csv_b64);
              const byteNumbers = new Array(byteChars.length);
              for (let i = 0; i < byteChars.length; i++) byteNumbers[i] = byteChars.charCodeAt(i);
              const byteArray = new Uint8Array(byteNumbers);
              const blob = new Blob([byteArray], { type: 'text/csv;charset=utf-8' });
              const url = URL.createObjectURL(blob);
              const a = document.createElement('a');
              a.href = url;
              a.download = (data.temp_csv_filename || 'user-temp-password.csv');
              document.body.appendChild(a); a.click(); a.remove();
              setTimeout(() => URL.revokeObjectURL(url), 1500);
            } catch(e) { console.warn('Kunde inte skapa CSV-nedladdning:', e); }
          }

          setTimeout(() => { location.reload(); }, 1200);
        } else {
          fb.className = 'text-sm text-red-700';
          fb.innerHTML = '<i class="fas fa-exclamation-triangle mr-1"></i>' + (data.message || 'Kunde inte skapa användare');
        }
      })
      .catch(err => {
        console.error(err);
        const fb = document.getElementById('addUserFeedback');
        fb.className = 'text-sm text-red-700';
        fb.innerHTML = '<i class="fas fa-exclamation-triangle mr-1"></i>Ett oväntat fel inträffade';
      })
      .finally(() => {
        btn.disabled = false; btn.classList.remove('opacity-75');
      });
    })()
  });
}

// ===== EditUser-modal logik =====
const editUserForm = document.getElementById('editUserForm');
if (editUserForm) {
  editUserForm.addEventListener('submit', function(e){
    e.preventDefault();
    const btn = document.getElementById('editUserSubmit');
    btn.disabled = true; btn.classList.add('opacity-75');
    const formObj = Object.fromEntries(new FormData(editUserForm).entries());
    const userId = formObj.id;
    delete formObj.id;
    // Normalisera typer
    formObj.is_active = formObj.is_active === '1' ? 1 : 0;
    if (!formObj.password) {
      delete formObj.password; // skicka inte tomt lösenord
    }
    apiFetch(`${API_BASE}?action=update-user&id=${userId}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(formObj)
    })
    .then(data => {
      const fb = document.getElementById('editUserFeedback');
      if (data.success) {
        fb.className = 'text-sm text-green-700';
        fb.textContent = 'Sparat!';
        setTimeout(() => { location.reload(); }, 900);
      } else {
        fb.className = 'text-sm text-red-700';
        fb.textContent = data.message || 'Uppdatering misslyckades';
      }
    })
    .catch(err => {
      const fb = document.getElementById('editUserFeedback');
      fb.className = 'text-sm text-red-700';
      fb.textContent = 'Ett fel uppstod: ' + err.message;
    })
    .finally(() => {
      btn.disabled = false; btn.classList.remove('opacity-75');
    });
  });
}
</script>

</body>
</html>
