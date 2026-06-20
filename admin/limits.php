<?php
// admin/limits.php
require_once __DIR__ . '/../src/Config/Database.php';
require_once 'AdminController.php';

$database = new Database();
$db = $database->getConnection();
$admin = new AdminController($db);
$users = $admin->listUsers();
$globalLimit = $admin->getWhiteboardLimit();

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

// Beräkna användarstatistik
$totalUsers = count($users);
$usersWithCustomLimits = 0;
$highestLimit = 0;
$lowestLimit = PHP_INT_MAX;
$totalWhiteboards = 0;

foreach ($users as $user) {
    $userLimit = $admin->getWhiteboardLimit($user['id']);
    $userCount = $admin->getUserWhiteboardCount($user['id']);
    $totalWhiteboards += $userCount;
    
    if ($userLimit !== null && $userLimit !== $globalLimit) {
        $usersWithCustomLimits++;
    }
    
    if ($userLimit !== null) {
        $highestLimit = max($highestLimit, $userLimit);
        $lowestLimit = min($lowestLimit, $userLimit);
    }
}

// Fixa värden om det inte finns några begränsningar
if ($lowestLimit === PHP_INT_MAX) {
    $lowestLimit = 0;
}

// Räkna användare som närmar sig gränsen
$usersNearLimit = 0;
$nearLimitThreshold = 0.8; // 80% av begränsningen

foreach ($users as $user) {
    $userLimit = $admin->getWhiteboardLimit($user['id']) ?? $globalLimit;
    $userCount = $admin->getUserWhiteboardCount($user['id']);
    
    if ($userLimit > 0 && $userCount >= ($userLimit * $nearLimitThreshold)) {
        $usersNearLimit++;
    }
}
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hantera Whiteboard-begränsningar - <?= htmlspecialchars($siteName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/vendor/fontawesome/all.min.css">
</head>
<body class="bg-gray-100">
<?php include_once 'nav.php'; ?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800">Hantera Whiteboard-begränsningar</h1>
        <span class="text-sm text-gray-500">Senast uppdaterad: <?= date('Y-m-d H:i') ?></span>
    </div>
    
    <!-- Översiktsstatistik -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Global begränsning -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-blue-500">
            <div class="flex justify-between items-center">
                <div class="text-sm font-medium text-gray-500">Global whiteboard-gräns</div>
                <i class="fas fa-globe text-blue-500"></i>
            </div>
            <div class="mt-2 text-3xl font-semibold"><?= $globalLimit ?? 'Ingen' ?></div>
            <div class="text-sm text-gray-500 mt-2">
                Standard för alla användare utan egen begränsning
            </div>
        </div>

        <!-- Användare med egna begränsningar -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-purple-500">
            <div class="flex justify-between items-center">
                <div class="text-sm font-medium text-gray-500">Användare med specialgränser</div>
                <i class="fas fa-user-cog text-purple-500"></i>
            </div>
            <div class="mt-2 text-3xl font-semibold"><?= $usersWithCustomLimits ?></div>
            <div class="text-sm text-gray-500 mt-2">
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-purple-500 h-2 rounded-full" 
                         style="width: <?= $totalUsers > 0 ? round(($usersWithCustomLimits / $totalUsers) * 100) : 0 ?>%"></div>
                </div>
                <div class="flex justify-between mt-1">
                    <span><?= $usersWithCustomLimits ?> anpassade</span>
                    <span><?= $totalUsers - $usersWithCustomLimits ?> standard</span>
                </div>
            </div>
        </div>
        
        <!-- Gränsvärden -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-green-500">
            <div class="flex justify-between items-center">
                <div class="text-sm font-medium text-gray-500">Gränsvärden</div>
                <i class="fas fa-sort-amount-up text-green-500"></i>
            </div>
            <div class="mt-2 flex justify-between">
                <div>
                    <div class="text-sm text-gray-500">Lägsta</div>
                    <div class="text-2xl font-semibold"><?= $lowestLimit ?></div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Högsta</div>
                    <div class="text-2xl font-semibold"><?= $highestLimit ?></div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Nära gräns</div>
                    <div class="text-2xl font-semibold"><?= $usersNearLimit ?></div>
                </div>
            </div>
        </div>

        <!-- Sökning -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-yellow-500">
            <div class="flex justify-between items-center mb-3">
                <div class="text-sm font-medium text-gray-500">Sök användare</div>
                <i class="fas fa-search text-yellow-500"></i>
            </div>
            <div class="relative">
                <input id="userSearch" type="text" placeholder="Sök efter e-post..." 
                       class="w-full bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block p-2.5 pl-10">
                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                    <i class="fas fa-search text-gray-500"></i>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Infosektion om begränsningar -->
    <div class="bg-white shadow rounded-lg mb-8 overflow-hidden">
        <div class="border-b border-gray-200 px-6 py-4">
            <h2 class="text-xl font-semibold text-gray-800">
                <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                Om whiteboard-begränsningar
            </h2>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-blue-50 rounded-lg p-4 border border-blue-200">
                    <h3 class="font-semibold text-blue-800 mb-2">Global begränsning</h3>
                    <p class="text-gray-700">
                        Den globala begränsningen anger hur många whiteboards varje användare kan skapa som standard.
                        Detta värde används för alla användare som inte har en egen anpassad begränsning.
                    </p>
                    <p class="text-gray-700 mt-2">
                        Om du ändrar den globala begränsningen påverkas alla användare som inte har en anpassad gräns.
                    </p>
                </div>
                
                <div class="bg-purple-50 rounded-lg p-4 border border-purple-200">
                    <h3 class="font-semibold text-purple-800 mb-2">Användarbegränsningar</h3>
                    <p class="text-gray-700">
                        Du kan ange en anpassad begränsning för varje användare som överskriver den globala begränsningen.
                        Detta är användbart för att ge vissa användare fler eller färre whiteboards än standard.
                    </p>
                    <p class="text-gray-700 mt-2">
                        Lämna fältet tomt för att återgå till den globala begränsningen för en användare.
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Global begränsning -->
    <div class="bg-white shadow rounded-lg mb-8 overflow-hidden">
        <div class="border-b border-gray-200 px-6 py-4">
            <h2 class="text-xl font-semibold text-gray-800">Global begränsning</h2>
        </div>
        <div class="p-6">
            <div class="flex flex-col md:flex-row items-start md:items-center">
                <div class="mr-6 mb-4 md:mb-0 flex-1">
                    <h3 class="text-gray-700 font-medium mb-2">Ange global whiteboard-begränsning</h3>
                    <p class="text-gray-600 text-sm mb-2">
                        Denna gräns gäller för alla användare som inte har en anpassad begränsning.
                    </p>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <input type="number" id="globalLimit" value="<?= $globalLimit ?? '' ?>" min="1"
                               class="pl-10 pr-3 py-2 border rounded-lg focus:ring-blue-500 focus:border-blue-500 w-32">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-hashtag text-gray-400"></i>
                        </div>
                    </div>
                    <button onclick="setGlobalLimit()" 
                            class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center transition duration-200">
                        <i class="fas fa-save mr-2"></i>
                        Spara
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Användarbegränsningar -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="border-b border-gray-200 px-6 py-4 flex justify-between items-center">
            <h2 class="text-xl font-semibold text-gray-800">Användarbegränsningar</h2>
            <div class="flex items-center">
                <button id="resetAllLimits" onclick="resetAllLimits()" class="flex items-center bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 px-4 rounded-lg">
                    <i class="fas fa-undo mr-2"></i>
                    Återställ alla till global
                </button>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Användare
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Nuvarande antal
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Begränsning
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Status
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Åtgärder
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200" id="usersTable">
                    <?php foreach ($users as $user): 
                        $userLimit = $admin->getWhiteboardLimit($user['id']);
                        $userCount = $admin->getUserWhiteboardCount($user['id']);
                        $isCustomLimit = ($userLimit !== null && $userLimit !== $globalLimit);
                        $limitPercentage = ($userLimit > 0) ? round(($userCount / $userLimit) * 100) : 0;
                        $isNearLimit = ($userLimit > 0 && $userCount >= ($userLimit * $nearLimitThreshold));
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 h-10 w-10 bg-gray-200 rounded-full flex items-center justify-center">
                                    <i class="fas fa-user text-gray-500"></i>
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?= htmlspecialchars($user['email']) ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center">
                                <span id="count-<?= $user['id'] ?>" class="text-sm font-medium mr-2">
                                    <?= $userCount ?>
                                </span>
                                <?php if ($userLimit > 0): ?>
                                <div class="w-24 bg-gray-200 rounded-full h-2">
                                    <div class="h-2 rounded-full <?= $isNearLimit ? 'bg-yellow-500' : 'bg-green-500' ?>" 
                                         style="width: <?= min(100, $limitPercentage) ?>%"></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="relative">
                                <input type="number" id="limit-<?= $user['id'] ?>" 
                                       value="<?= $userLimit ?? '' ?>" 
                                       placeholder="<?= $globalLimit ?? 'Global' ?>"
                                       min="1" 
                                       class="border rounded-lg px-3 py-2 w-24 <?= $isCustomLimit ? 'bg-purple-50 border-purple-200' : '' ?>">
                                <?php if ($isCustomLimit): ?>
                                <span class="absolute right-0 top-0 h-2 w-2 mt-1 mr-1 bg-purple-500 rounded-full"></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <?php if ($userLimit > 0 && $userCount >= $userLimit): ?>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                    Gräns uppnådd
                                </span>
                            <?php elseif ($isNearLimit): ?>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                    Nära gräns
                                </span>
                            <?php else: ?>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                    OK
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex space-x-3">
                                <button onclick="setUserLimit(<?= $user['id'] ?>)"
                                        class="text-blue-600 hover:text-blue-900 flex items-center">
                                    <i class="fas fa-save mr-1"></i>
                                    <span>Spara</span>
                                </button>
                                <?php if ($isCustomLimit): ?>
                                <button onclick="resetUserLimit(<?= $user['id'] ?>)"
                                        class="text-gray-600 hover:text-gray-900 flex items-center">
                                    <i class="fas fa-undo mr-1"></i>
                                    <span>Återställ</span>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Sökfunktion för användartabellen
    const userSearch = document.getElementById('userSearch');
    if (userSearch) {
        userSearch.addEventListener('keyup', function() {
            filterUsers();
        });
    }
});

function filterUsers() {
    const searchQuery = document.getElementById('userSearch').value.toLowerCase();
    const rows = document.querySelectorAll('#usersTable tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        
        if (text.includes(searchQuery)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function setGlobalLimit() {
    const limit = document.getElementById('globalLimit').value;
    
    if (!limit || isNaN(parseInt(limit))) {
        alert('Vänligen ange ett giltigt nummer för den globala begränsningen.');
        return;
    }
    
    fetch('/admin/api/limits.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ 
            action: 'set_global_limit',
            limit: parseInt(limit) 
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Global begränsning uppdaterad', 'success');
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            showNotification('Ett fel uppstod: ' + (data.message || ''), 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Ett fel uppstod vid kommunikation med servern', 'error');
    });
}

function setUserLimit(userId) {
    const limitInput = document.getElementById(`limit-${userId}`);
    const limit = limitInput.value;
    
    // Om fältet är tomt, återställ till global
    if (limit === '') {
        resetUserLimit(userId);
        return;
    }
    
    if (isNaN(parseInt(limit))) {
        alert('Vänligen ange ett giltigt nummer.');
        return;
    }
    
    fetch('/admin/api/limits.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ 
            action: 'set_user_limit',
            user_id: userId,
            limit: parseInt(limit) 
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Användarbegränsning uppdaterad', 'success');
            // Markera fältet som specialanpassat
            limitInput.classList.add('bg-purple-50', 'border-purple-200');
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            showNotification('Ett fel uppstod: ' + (data.message || ''), 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Ett fel uppstod vid kommunikation med servern', 'error');
    });
}

function resetUserLimit(userId) {
    fetch('/admin/api/limits.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ 
            action: 'reset_user_limit',
            user_id: userId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Användarbegränsning återställd till global', 'success');
            const limitInput = document.getElementById(`limit-${userId}`);
            limitInput.value = '';
            limitInput.classList.remove('bg-purple-50', 'border-purple-200');
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            showNotification('Ett fel uppstod: ' + (data.message || ''), 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Ett fel uppstod vid kommunikation med servern', 'error');
    });
}

function resetAllLimits() {
    if (confirm('Är du säker på att du vill återställa alla användarbegränsningar till den globala begränsningen?')) {
        fetch('/admin/api/limits.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ 
                action: 'reset_all_limits'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Alla användarbegränsningar återställda', 'success');
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                showNotification('Ett fel uppstod: ' + (data.message || ''), 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Ett fel uppstod vid kommunikation med servern', 'error');
        });
    }
}

// Hjälpfunktion för att visa notifieringar
function showNotification(message, type = 'success') {
    // Kontrollera om vi redan har en notifiering
    let notification = document.getElementById('notification');
    
    if (notification) {
        notification.remove();
    }
    
    // Skapa ett nytt notifieringselement
    notification = document.createElement('div');
    notification.id = 'notification';
    notification.className = `fixed bottom-4 right-4 px-6 py-3 rounded-lg text-white shadow-lg transition-all duration-300 transform translate-y-0 opacity-100 flex items-center`;
    
    // Sätt bakgrundsfärg baserat på typ
    if (type === 'success') {
        notification.classList.add('bg-green-500');
    } else if (type === 'error') {
        notification.classList.add('bg-red-500');
    } else {
        notification.classList.add('bg-blue-500');
    }
    
    // Ikon baserat på typ
    let icon = 'info-circle';
    if (type === 'success') {
        icon = 'check-circle';
    } else if (type === 'error') {
        icon = 'exclamation-circle';
    }
    
    notification.innerHTML = `
        <i class="fas fa-${icon} mr-2"></i>
        <span>${message}</span>
        <button class="ml-4 focus:outline-none" onclick="this.parentElement.remove()">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    document.body.appendChild(notification);
    
    // Ta bort notifieringen efter 4 sekunder
    setTimeout(() => {
        if (notification && notification.parentElement) {
            notification.classList.add('opacity-0', 'translate-y-12');
            setTimeout(() => {
                if (notification && notification.parentElement) {
                    notification.remove();
                }
            }, 300);
        }
    }, 4000);
}
</script>

</body>
</html>