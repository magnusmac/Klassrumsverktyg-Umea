<?php
require_once __DIR__ . '/../src/Config/Database.php';


$database = new Database();
$db = $database->getConnection();

// Hämta instansens namn (fallback: "Klassrumsverktyg")
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
$whiteboards = $admin->listWhiteboards();
$whiteboardId = isset($_GET['id']) ? intval($_GET['id']) : null;

// Statistikberäkningar
$totalWhiteboards = count($whiteboards);
$activeWhiteboards = array_reduce($whiteboards, function($count, $wb) {
    return $count + ($wb['is_active'] ? 1 : 0);
}, 0);

// Beräkna antal whiteboards per användare
$whiteboardsByUser = [];
$whiteboardsWithoutUser = 0;

foreach ($whiteboards as $wb) {
    if (!empty($wb['user_id'])) {
        $userId = $wb['user_id'];
        if (!isset($whiteboardsByUser[$userId])) {
            $whiteboardsByUser[$userId] = [
                'count' => 0,
                'email' => $wb['email'] ?? 'Okänd',
                'name' => $wb['user_name'] ?? 'Användare #' . $userId
            ];
        }
        $whiteboardsByUser[$userId]['count']++;
    } else {
        $whiteboardsWithoutUser++;
    }
}

// Sortera användare efter antal whiteboards
uasort($whiteboardsByUser, function($a, $b) {
    return $b['count'] - $a['count'];
});

// Ta de 5 största användarna
$topUsers = array_slice($whiteboardsByUser, 0, 5, true);

// Beräkna bakgrundsstatistik
$colorBackgrounds = array_reduce($whiteboards, function($count, $wb) {
    return $count + ($wb['background_type'] === 'color' ? 1 : 0);
}, 0);

$imageBackgrounds = array_reduce($whiteboards, function($count, $wb) {
    return $count + ($wb['background_type'] === 'image' ? 1 : 0);
}, 0);

$passwordProtected = array_reduce($whiteboards, function($count, $wb) {
    return $count + (!empty($wb['password']) ? 1 : 0);
}, 0);

// Paginering av whiteboard-listan
$whiteboardsPerPage = 10;  // Antal whiteboards per sida
$totalPages = ceil($totalWhiteboards / $whiteboardsPerPage);
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$currentPage = min($currentPage, max(1, $totalPages));
$startIndex = ($currentPage - 1) * $whiteboardsPerPage;
$endIndex = min($startIndex + $whiteboardsPerPage, $totalWhiteboards);
$pageWhiteboards = array_slice($whiteboards, $startIndex, $whiteboardsPerPage);
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hantera Whiteboards - <?= htmlspecialchars($siteName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/vendor/fontawesome/all.min.css">
</head>
<body class="bg-gray-100">

<?php include_once 'nav.php'; ?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800">Hantera Whiteboards</h1>
        <span class="text-sm text-gray-500">Senast uppdaterad: <?= date('Y-m-d H:i') ?></span>
    </div>
    
    <!-- Översiktsstatistik -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Totalt antal whiteboards -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-blue-500">
            <div class="flex justify-between items-center">
                <div class="text-sm font-medium text-gray-500">Totalt antal whiteboards</div>
                <i class="fas fa-chalkboard text-blue-500"></i>
            </div>
            <div class="mt-2 text-3xl font-semibold"><?= $totalWhiteboards ?></div>
            <div class="text-sm text-gray-500 mt-2">
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-blue-500 h-2 rounded-full" style="width: <?= round(($activeWhiteboards / max(1, $totalWhiteboards)) * 100) ?>%"></div>
                </div>
                <div class="flex justify-between mt-1">
                    <span><?= $activeWhiteboards ?> aktiva</span>
                    <span><?= $totalWhiteboards - $activeWhiteboards ?> inaktiva</span>
                </div>
            </div>
        </div>

        <!-- Bakgrunder -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-green-500">
            <div class="flex justify-between items-center">
                <div class="text-sm font-medium text-gray-500">Bakgrunder</div>
                <i class="fas fa-image text-green-500"></i>
            </div>
            <div class="mt-2 flex justify-between">
                <div>
                    <div class="text-sm text-gray-500">Färg</div>
                    <div class="text-2xl font-semibold"><?= $colorBackgrounds ?></div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Bild</div>
                    <div class="text-2xl font-semibold"><?= $imageBackgrounds ?></div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Lösenord</div>
                    <div class="text-2xl font-semibold"><?= $passwordProtected ?></div>
                </div>
            </div>
        </div>
        
        <!-- Användare -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-purple-500">
            <div class="flex justify-between items-center">
                <div class="text-sm font-medium text-gray-500">Användare med whiteboards</div>
                <i class="fas fa-users text-purple-500"></i>
            </div>
            <div class="mt-2 text-3xl font-semibold"><?= count($whiteboardsByUser) ?></div>
            <div class="text-sm text-gray-500 mt-2">
                <?php if ($whiteboardsWithoutUser > 0): ?>
                <div>Gästwhiteboards: <?= $whiteboardsWithoutUser ?></div>
                <?php endif; ?>
                <div>Genomsnitt per användare: <?= round($totalWhiteboards / max(1, count($whiteboardsByUser)), 1) ?></div>
            </div>
        </div>

    </div>
    
    <!-- Användning per användare -->
    <div class="bg-white shadow rounded-lg mb-8 overflow-hidden">
        <div class="border-b border-gray-200 px-6 py-4">
            <h2 class="text-xl font-semibold text-gray-800">Användning per användare</h2>
        </div>
        <div class="p-6">
            <?php if (empty($topUsers)): ?>
                <div class="text-gray-500 text-center py-4">Ingen användardata tillgänglig</div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($topUsers as $userId => $userData): ?>
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                            <div class="flex justify-between items-center mb-2">
                                <div class="font-medium"><?= htmlspecialchars($userData['name']) ?></div>
                                <div class="text-sm text-gray-500"><?= htmlspecialchars($userData['email']) ?></div>
                            </div>
                            <?php 
                            // Beräkna procentuellt användande
                            $percentage = round(($userData['count'] / $totalWhiteboards) * 100);
                            ?>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-purple-500 h-2 rounded-full" style="width: <?= $percentage ?>%"></div>
                            </div>
                            <div class="flex justify-between mt-1 text-xs text-gray-500">
                                <span><?= $userData['count'] ?> whiteboards</span>
                                <span><?= $percentage ?>% av totalen</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Whiteboard-tabell -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="border-b border-gray-200 px-6 py-4 flex justify-between items-center">
            <h2 class="text-xl font-semibold text-gray-800">Whiteboard-lista</h2>
            
            <div class="flex items-center">
                <button onclick="exportWhiteboards()" class="flex items-center bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 px-4 rounded-lg mr-2">
                    <i class="fas fa-file-export mr-2"></i>
                    Exportera
                </button>
            </div>
        </div>
        <!-- Filter- och sökverktyg nära listan -->
        <div class="px-6 pt-4 pb-2">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-3 items-center">
                <!-- Sök -->
                <div class="lg:col-span-5">
                    <div class="relative">
                        <input id="whiteboardSearch" type="text" placeholder="Sök whiteboards..."
                               class="w-full bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block p-2.5 pl-10">
                        <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                            <i class="fas fa-search text-gray-500"></i>
                        </div>
                    </div>
                </div>
                <!-- Statusknappar -->
                <div class="lg:col-span-3">
                    <div class="flex space-x-2">
                        <button id="filterActive" class="flex-1 py-1 px-2 text-xs border rounded hover:bg-gray-50 bg-white border-gray-300">Aktiva</button>
                        <button id="filterInactive" class="flex-1 py-1 px-2 text-xs border rounded hover:bg-gray-50 bg-white border-gray-300">Inaktiva</button>
                        <button id="filterAll" class="flex-1 py-1 px-2 text-xs border rounded hover:bg-gray-50 bg-blue-50 border-blue-300 font-medium">Alla</button>
                    </div>
                </div>
                <!-- Extra filter -->
                <div class="lg:col-span-3 grid grid-cols-2 gap-2">
                    <select id="filterBg" class="w-full py-1.5 px-2 text-xs border rounded bg-white border-gray-300">
                        <option value="all">Alla bakgrunder</option>
                        <option value="color">Färg</option>
                        <option value="image">Bild</option>
                        <option value="password">Lösenordsskyddad</option>
                    </select>
                    <select id="filterOwner" class="w-full py-1.5 px-2 text-xs border rounded bg-white border-gray-300">
                        <option value="all">Alla ägare</option>
                        <option value="guest">Gäst</option>
                        <?php foreach ($whiteboardsByUser as $uid => $ud): ?>
                            <option value="<?= 'user-' . (int)$uid ?>"><?= htmlspecialchars($ud['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Räknare -->
                <div class="lg:col-span-1 text-right text-xs text-gray-500">
                    Visar <span id="filterCount"><?= count($pageWhiteboards) ?></span>/<?= $totalWhiteboards ?>
                </div>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 table-fixed">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/12">
                            Kod
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-2/12">
                            Namn
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-2/12">
                            Användare
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/12">
                            Skydd
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/12">
                            Skapad
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/12">
                            Senast använd
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/12">
                            Status
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-2/12">
                            Åtgärder
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200" id="whiteboardsTable">
                    <?php foreach ($pageWhiteboards as $wb): ?>
                    <tr class="hover:bg-gray-50"
                        data-status="<?= $wb['is_active'] ? 'active' : 'inactive' ?>"
                        data-bg="<?= htmlspecialchars($wb['background_type'] ?? 'none') ?>"
                        data-pw="<?= !empty($wb['password']) ? '1' : '0' ?>"
                        data-owner="<?= !empty($wb['user_id']) ? 'user-' . (int)$wb['user_id'] : 'guest' ?>">
                        <td class="px-6 py-4">
                            <div class="font-mono text-sm bg-gray-100 rounded px-2 py-1 inline-block">
                                <?= htmlspecialchars($wb['board_code']) ?>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="truncate max-w-[180px]" title="<?= htmlspecialchars($wb['name']) ?>">
                                <?= htmlspecialchars($wb['name']) ?>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <?php if (!empty($wb['email'])): ?>
                            <div class="truncate max-w-[180px]" title="<?= htmlspecialchars($wb['email']) ?>">
                                <?= htmlspecialchars($wb['email']) ?>
                            </div>
                            <?php else: ?>
                            <span class="text-gray-500 italic">Gästwhiteboard</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <?php if (!empty($wb['password'])): ?>
                              <span class="inline-flex items-center text-xs font-medium text-yellow-800 bg-yellow-100 rounded-full px-2 py-0.5">
                                <i class="fas fa-lock mr-1"></i> Lösenord
                              </span>
                            <?php else: ?>
                              <span class="text-gray-400">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <div title="<?= date('Y-m-d H:i', strtotime($wb['created_at'])) ?>"><?= date('Y-m-d', strtotime($wb['created_at'])) ?></div>
                            <div class="text-xs text-gray-500"><?= date('H:i', strtotime($wb['created_at'])) ?></div>
                        </td>
                        <td class="px-6 py-4">
                            <?php if (!empty($wb['last_used'])): ?>
                            <div title="<?= date('Y-m-d H:i', strtotime($wb['last_used'])) ?>"><?= date('Y-m-d', strtotime($wb['last_used'])) ?></div>
                            <div class="text-xs text-gray-500"><?= date('H:i', strtotime($wb['last_used'])) ?></div>
                            <?php else: ?>
                            <span class="text-gray-500 italic">Aldrig använd</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                       <?= $wb['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                <?= $wb['is_active'] ? 'Aktiv' : 'Inaktiv' ?>
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex space-x-1 flex-wrap gap-y-1">
                                <a href="../whiteboard.php?board=<?= htmlspecialchars($wb['board_code']) ?>" target="_blank" 
                                   class="text-blue-600 hover:text-blue-900 flex items-center mr-1">
                                    <i class="fas fa-external-link-alt mr-1"></i>
                                    <span class="hidden sm:inline">Öppna</span>
                                </a>
                                <button onclick="deleteWhiteboard(<?= $wb['id'] ?>)" 
                                        class="text-red-600 hover:text-red-900 flex items-center">
                                    <i class="fas fa-trash-alt mr-1"></i>
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
                Visar <?= $startIndex + 1 ?>-<?= $endIndex ?> av <?= $totalWhiteboards ?> whiteboards
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Sökfunktion för whiteboard-tabellen
    const whiteboardSearch = document.getElementById('whiteboardSearch');
    let searchTimer = null;
    if (whiteboardSearch) {
        whiteboardSearch.addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(filterWhiteboards, 200);
        });
    }
    const filterBgSel = document.getElementById('filterBg');
    const filterOwnerSel = document.getElementById('filterOwner');
    if (filterBgSel) filterBgSel.addEventListener('change', () => { bgFilter = filterBgSel.value; filterWhiteboards(); });
    if (filterOwnerSel) filterOwnerSel.addEventListener('change', () => { ownerFilter = filterOwnerSel.value; filterWhiteboards(); });

    // Filterknappar
    const btnActive = document.getElementById('filterActive');
    if (btnActive) btnActive.addEventListener('click', function() {
        setActiveFilter('active');
        this.classList.add('bg-blue-50', 'border-blue-300', 'font-medium');
        document.getElementById('filterInactive')?.classList.remove('bg-blue-50', 'border-blue-300', 'font-medium');
        document.getElementById('filterAll')?.classList.remove('bg-blue-50', 'border-blue-300', 'font-medium');
    });

    const btnInactive = document.getElementById('filterInactive');
    if (btnInactive) btnInactive.addEventListener('click', function() {
        setActiveFilter('inactive');
        this.classList.add('bg-blue-50', 'border-blue-300', 'font-medium');
        document.getElementById('filterActive')?.classList.remove('bg-blue-50', 'border-blue-300', 'font-medium');
        document.getElementById('filterAll')?.classList.remove('bg-blue-50', 'border-blue-300', 'font-medium');
    });

    const btnAll = document.getElementById('filterAll');
    if (btnAll) btnAll.addEventListener('click', function() {
        setActiveFilter('all');
        this.classList.add('bg-blue-50', 'border-blue-300', 'font-medium');
        document.getElementById('filterActive')?.classList.remove('bg-blue-50', 'border-blue-300', 'font-medium');
        document.getElementById('filterInactive')?.classList.remove('bg-blue-50', 'border-blue-300', 'font-medium');
    });
});

let activeFilter = 'all'; // status: 'all' | 'active' | 'inactive'
let bgFilter = 'all';     // 'all' | 'color' | 'image' | 'password'
let ownerFilter = 'all';  // 'all' | 'guest' | 'user-<id>'

function setActiveFilter(filter) {
    activeFilter = filter;
    filterWhiteboards();
}

function filterWhiteboards() {
    const searchQuery = (document.getElementById('whiteboardSearch')?.value || '').toLowerCase();
    const rows = document.querySelectorAll('#whiteboardsTable tr');
    let shown = 0;

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const status = row.getAttribute('data-status');
        const bg = row.getAttribute('data-bg') || 'none';
        const pw = row.getAttribute('data-pw') === '1';
        const owner = row.getAttribute('data-owner') || 'guest';

        const matchesSearch = !searchQuery || text.includes(searchQuery);
        const matchesStatus = (activeFilter === 'all') || (status === activeFilter);
        const matchesBg = (bgFilter === 'all') ||
                          (bgFilter === 'color' && bg === 'color') ||
                          (bgFilter === 'image' && bg === 'image') ||
                          (bgFilter === 'password' && pw === true);
        const matchesOwner = (ownerFilter === 'all') || (owner === ownerFilter);

        const visible = matchesSearch && matchesStatus && matchesBg && matchesOwner;
        row.style.display = visible ? '' : 'none';
        if (visible) shown++;
    });

    const cnt = document.getElementById('filterCount');
    if (cnt) cnt.textContent = String(shown);
}

function deleteWhiteboard(whiteboardId) {
    if (confirm('Är du säker på att du vill ta bort denna whiteboard och all tillhörande data?')) {
        fetch(`/admin/api/whiteboards/${whiteboardId}`, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Ett fel uppstod vid borttagning av whiteboard');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ett fel uppstod vid kommunikation med servern');
        });
    }
}

function exportWhiteboards() {
    window.location.href = '/admin/export-whiteboards.php';
}
</script>

</body>
</html>