<?php
session_start();
require_once __DIR__ . '/src/Config/Database.php';
require_once 'controllers/WhiteboardController.php';
require_once 'includes/display_alerts.php';

function requireLogin() {
   if (!isset($_SESSION['user_id'])) {
       header('Location: /login.php');
       exit;
   }
   
   $database = new Database();
   $pdo = $database->getConnection();
   
   $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
   $stmt->execute([$_SESSION['user_id']]);
   $user = $stmt->fetch(PDO::FETCH_ASSOC);
   
   if (!$user) {
       header('Location: /logout.php');
       exit;
   }

   $_SESSION['user_role'] = $user['role'];
   
   return $user['id'];
}


$database = new Database();
$pdo = $database->getConnection();
// Läs instansens namn från system_settings (fallback: "Klassrumsverktyg")
if (!function_exists('kv_get_setting')) {
    function kv_get_setting(PDO $pdo, string $key, $default = null) {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['setting_value'] ?? $default;
    }
}
$siteName = (string) kv_get_setting($pdo, 'site_name', 'Klassrumsverktyg');

$controller = new WhiteboardController($pdo);
$userId = requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
   switch ($_POST['action']) {
       case 'create':
           $name = $_POST['name'] ?? 'Ny Whiteboard';
           $expiresAt = date('Y-m-d H:i:s', strtotime('+365 days'));
           $controller->createWhiteboard($userId, $name, $expiresAt);
           header('Location: /dashboard.php');
           exit;

       case 'update':
           $boardId = $_POST['board_id'] ?? null;
           $name = $_POST['name'] ?? null;
           if ($boardId && $name) {
               $controller->updateWhiteboard($userId, $boardId, $name);
           }
           header('Location: /dashboard.php');
           exit;

       case 'delete':
           $boardId = $_POST['board_id'] ?? null;
           if ($boardId) {
               $controller->deleteWhiteboard($userId, $boardId);
           }
           header('Location: /dashboard.php');
           exit;

        case 'update_password':
            $boardId = $_POST['board_id'] ?? null;
            $password = $_POST['password'] ?? null;
            if ($boardId) {
                $controller->updateWhiteboardPassword($userId, $boardId, $password);
            }
            header('Location: /dashboard.php');
            exit;
   }
}

$whiteboards = $controller->getWhiteboardsForUser($userId);
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mina Whiteboards</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#111827">
    <link rel="apple-touch-icon" href="/assets/img/icon-192.png">
    <link href="/assets/vendor/tailwind/tailwind.min.css" rel="stylesheet">
    <script src="/assets/vendor/lucide/lucide.min.js"></script>
    <script>if ('serviceWorker' in navigator) { navigator.serviceWorker.register('/sw.js'); }</script>
</head>
<body class="bg-gray-50 flex flex-col min-h-screen">
<nav class="bg-gray-900">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 justify-between">
            <div class="flex items-center space-x-4">
                <i data-lucide="graduation-cap" class="h-6 w-6 text-gray-300"></i>
                <h1 class="text-white text-xl font-semibold"><?= htmlspecialchars($siteName) ?></h1>
            </div>
            
            <div class="flex items-center space-x-6">
                <div class="flex items-center">
                    <div class="relative rounded-md shadow-sm">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <i data-lucide="search" class="h-5 w-5 text-gray-400"></i>
                        </div>
                        <input type="text" class="block w-full rounded-md border-0 bg-gray-800 py-1.5 pl-10 pr-3 text-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-blue-500 sm:text-sm sm:leading-6" placeholder="Sök...">
                    </div>
                </div>
                
                <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                    <a href="/admin/dashboard.php" class="text-gray-300 hover:text-white px-3 py-2 rounded-md text-sm font-medium">
                        Admin
                    </a>
                <?php endif; ?>
                
                <button onclick="document.getElementById('newBoardModal').classList.remove('hidden')" 
                        class="rounded-md bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500">
                    Ny whiteboard
                </button>
                
                <button onclick="document.getElementById('helpModal').classList.remove('hidden')" 
                        class="rounded-full bg-gray-800 p-2 text-gray-400 hover:text-white">
                    <i data-lucide="help-circle" class="h-5 w-5"></i>
                </button>
                
                <button onclick="openProfileModal()" type="button" class="rounded-full bg-gray-800 p-2 text-gray-400 hover:text-white">
                    <i data-lucide="user" class="h-5 w-5"></i>
                </button>
                
                <a href="/logout.php" class="rounded-full bg-gray-800 p-2 text-gray-400 hover:text-white">
                    <i data-lucide="log-out" class="h-5 w-5"></i>
                </a>
            </div>
        </div>
    </div>
</nav>

<script>
    lucide.createIcons();
</script>


    <div class="container mx-auto px-4 py-8">
         <!-- Alert banners will be displayed here -->
         <div class="alerts-container">
            <?php displayAlerts(); ?>
        </div>
        <header class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Mina whiteboards</h1>
        </header>

      <!-- Whiteboard Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php foreach ($whiteboards as $board): ?>
        <?php 
        // Använda det befintliga is_password_protected-fältet
        $hasPassword = isset($board['is_password_protected']) && $board['is_password_protected'] == 1;
        
        // Varje whiteboard får en unik "accent-färg" baserat på ID för att lätt skilja dem åt
        $boardId = $board['id'];
        $colorSeed = ($boardId * 13) % 360; // Olika färger för HSL
        $accentColor = "hsl({$colorSeed}, 70%, 65%)"; // Pastellfärg genererad från ID
        ?>
        
        <div class="bg-white rounded-lg shadow-md hover:shadow-lg transition duration-200 overflow-hidden border border-gray-100 flex">
            <!-- Färgindikator på vänster sida -->
            <div class="w-2" style="background-color: <?= $accentColor ?>"></div>
            
            <!-- Innehåll -->
            <div class="p-5 flex-1">
                <!-- Övre del med titel och ikoner -->
                <div class="flex justify-between items-start mb-3">
                    <div class="flex-grow">
                        <!-- Titel med lösenordsindikator -->
                        <div class="flex items-center mb-1">
                            <?php if ($hasPassword): ?>
                                <span class="mr-2 text-amber-500">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                              d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                    </svg>
                                </span>
                            <?php endif; ?>
                            <a href="/whiteboard.php?board=<?= htmlspecialchars($board['board_code']) ?>" 
                               target="_blank" 
                               class="text-xl font-semibold text-gray-900 hover:text-blue-600 hover:underline transition">
                                <?= htmlspecialchars($board['name']) ?>
                            </a>
                        </div>
                        
                        <!-- Tavlakod -->
                        <div class="flex items-center flex-wrap mt-1">
                            <span class="inline-flex items-center text-sm text-gray-500">
                                <svg class="w-4 h-4 mr-1 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                        d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14" />
                                </svg>
                                <span class="font-mono"><?= htmlspecialchars($board['board_code']) ?></span>
                            </span>
                            
                            <!-- Lösenordstaggen visas bredvid koden -->
                            <?php if ($hasPassword): ?>
                                <span class="ml-3 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">
                                    Lösenordsskyddad
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="flex space-x-1">
                        <!-- Redigera-knapp med tooltip -->
                        <button onclick="editBoard(<?= $board['id'] ?>, '<?= htmlspecialchars($board['name']) ?>')"
                                class="p-2 rounded-full text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition"
                                title="Redigera">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                        </button>

                        <!-- Lösenord-knapp med färgindikering -->
                        <button onclick="toggleWhiteboardPassword(<?= $board['id'] ?>)"
                                class="p-2 rounded-full <?= $hasPassword ? 'text-amber-500 bg-amber-50' : 'text-gray-400 hover:text-amber-500 hover:bg-amber-50' ?> transition"
                                title="<?= $hasPassword ? 'Ändra lösenord' : 'Lägg till lösenord' ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                      d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </button>

                        <!-- Ta bort-knapp -->
                        <button onclick="deleteBoard(<?= $board['id'] ?>)"
                                class="p-2 rounded-full text-gray-400 hover:text-red-600 hover:bg-red-50 transition"
                                title="Ta bort">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </div>
                </div>
                
                <!-- Avdelare -->
                <div class="border-t my-3"></div>
                
                <!-- Metadata -->
                <div class="grid grid-cols-2 gap-2 text-sm text-gray-600">
                    <div>
                        <span class="text-gray-500">Skapad:</span><br>
                        <?= date('Y-m-d H:i', strtotime($board['created_at'])) ?>
                    </div>
                    <div>
                        <span class="text-gray-500">Senast ändrad:</span><br>
                        <?= date('Y-m-d H:i', strtotime($board['updated_at'])) ?>
                    </div>
                </div>
                
                <!-- Öppna-knapp -->
                <div class="mt-4">
                    <a href="/whiteboard.php?board=<?= htmlspecialchars($board['board_code']) ?>" 
                      target="_blank" 
                      class="flex items-center justify-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                  d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                        Öppna whiteboard
                    </a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

        <!-- Modal för ny whiteboard -->
        <div id="newBoardModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full">
            <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Skapa ny whiteboard</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="boardName">
                            Namn på whiteboard
                        </label>
                        <input type="text" name="name" id="boardName" required
                               class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                    </div>
                    <div class="flex justify-end space-x-2">
                        <button type="button" onclick="document.getElementById('newBoardModal').classList.add('hidden')"
                                class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300">
                            Avbryt
                        </button>
                        <button type="submit"
                                class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                            Skapa
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function editBoard(id, name) {
            document.getElementById('editBoardId').value = id;
            document.getElementById('editBoardName').value = name;
            document.getElementById('editBoardModal').classList.remove('hidden');
        }

        function deleteBoard(id) {
            document.getElementById('deleteBoardId').value = id;
            document.getElementById('deleteBoardModal').classList.remove('hidden');
        }
    </script>

    <!-- Modal för redigering -->
<div id="editBoardModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Redigera whiteboard</h3>
        <form method="POST">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="board_id" id="editBoardId">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="editBoardName">Namn</label>
                <input type="text" name="name" id="editBoardName" required class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
            </div>
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="document.getElementById('editBoardModal').classList.add('hidden')" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300">Avbryt</button>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">Spara</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal för borttagning -->
<div id="deleteBoardModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Ta bort whiteboard</h3>
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="board_id" id="deleteBoardId">
            <p class="mb-4">Är du säker på att du vill ta bort denna whiteboard?</p>
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="document.getElementById('deleteBoardModal').classList.add('hidden')" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300">Avbryt</button>
                <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700">Ta bort</button>
            </div>
        </form>
    </div>
</div>


<!-- Profile modal -->
<script>
function openProfileModal() {
    document.getElementById('profileModal').classList.remove('hidden');
}

function closeProfileModal() {
    document.getElementById('profileModal').classList.add('hidden');
}

function switchProfileTab(tab) {
    document.querySelectorAll('.tab-content').forEach(content => content.classList.add('hidden'));
    document.getElementById(tab + 'Tab').classList.remove('hidden');
    
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('border-blue-500', 'text-blue-600');
        if (button.dataset.tab === tab) {
            button.classList.add('border-blue-500', 'text-blue-600');
        }
    });
}

function toggleBackgroundOptions(type) {
    const colorOption = document.getElementById('colorOption');
    const gradientOption = document.getElementById('gradientOption');
    const imageOption = document.getElementById('imageOption');
    
    [colorOption, gradientOption, imageOption].forEach(el => el.classList.add('hidden'));
    
    switch(type) {
        case 'color':
            colorOption.classList.remove('hidden');
            break;
        case 'gradient':
            gradientOption.classList.remove('hidden');
            break;
        case 'image':
            imageOption.classList.remove('hidden');
            break;
    }
    updatePreview();
}

function updatePreview() {
    const type = document.querySelector('[name="background_type"]').value;
    const preview = document.getElementById('backgroundPreview');
    
    switch(type) {
        case 'color':
            const color = document.querySelector('[name="background_color"]').value;
            preview.style.background = color;
            break;
        case 'gradient':
            const color1 = document.querySelector('[name="gradient_color_1"]').value;
            const color2 = document.querySelector('[name="gradient_color_2"]').value;
            const direction = document.querySelector('[name="gradient_direction"]').value;
            preview.style.background = `linear-gradient(${direction}, ${color1}, ${color2})`;
            break;
    }
}

function selectGradient(gradientValue) {
    document.getElementById('backgroundPreview').style.background = gradientValue;
    document.querySelector('[name="background_value"]').value = gradientValue;
}

document.addEventListener('DOMContentLoaded', () => {
    switchProfileTab('password');
    
    document.querySelectorAll('[name="background_color"], [name="gradient_color_1"], [name="gradient_color_2"], [name="gradient_direction"]')
        .forEach(input => input.addEventListener('input', updatePreview));
        
    document.getElementById('background-upload')?.addEventListener('change', event => {
        const file = event.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = e => {
                document.getElementById('backgroundPreview').style.background = `url(${e.target.result}) center/cover`;
            }
            reader.readAsDataURL(file);
        }
    });
});
</script>
<!-- Updated Profile Modal with Account Deletion and Custom Background Features -->
<script>
function openProfileModal() {
    document.getElementById('profileModal').classList.remove('hidden');
}

function closeProfileModal() {
    document.getElementById('profileModal').classList.add('hidden');
}

function switchProfileTab(tab) {
    document.querySelectorAll('.tab-content').forEach(content => content.classList.add('hidden'));
    document.getElementById(tab + 'Tab').classList.remove('hidden');
    
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('border-blue-500', 'text-blue-600');
        if (button.dataset.tab === tab) {
            button.classList.add('border-blue-500', 'text-blue-600');
        }
    });
}

function toggleBackgroundOptions(type) {
    const colorOption = document.getElementById('colorOption');
    const gradientOption = document.getElementById('gradientOption');
    const imageOption = document.getElementById('imageOption');
    const customOption = document.getElementById('customOption');
    
    [colorOption, gradientOption, imageOption, customOption].forEach(el => el.classList.add('hidden'));
    
    switch(type) {
        case 'color':
            colorOption.classList.remove('hidden');
            break;
        case 'gradient':
            gradientOption.classList.remove('hidden');
            break;
        case 'image':
            imageOption.classList.remove('hidden');
            break;
        case 'custom':
            customOption.classList.remove('hidden');
            break;
    }
    updatePreview();
}

function updatePreview() {
    const type = document.querySelector('[name="background_type"]').value;
    const preview = document.getElementById('backgroundPreview');
    
    switch(type) {
        case 'color':
            const color = document.querySelector('[name="background_color"]').value;
            preview.style.background = color;
            break;
        case 'gradient':
            const color1 = document.querySelector('[name="gradient_color_1"]').value;
            const color2 = document.querySelector('[name="gradient_color_2"]').value;
            const direction = document.querySelector('[name="gradient_direction"]').value;
            preview.style.background = `linear-gradient(${direction}, ${color1}, ${color2})`;
            break;
        case 'custom':
            const customBgId = document.querySelector('input[name="custom_background_id"]:checked')?.value;
            if (customBgId) {
                const imagePath = document.querySelector(`input[name="custom_background_id"][value="${customBgId}"]`).dataset.path;
                preview.style.background = `url(${imagePath}) center/cover`;
            }
            break;
    }
}

function selectGradient(gradientValue) {
    document.getElementById('backgroundPreview').style.background = gradientValue;
    document.querySelector('[name="background_value"]').value = gradientValue;
}

function confirmDeleteAccount() {
    if (confirm('Är du säker på att du vill radera ditt konto? Detta kommer att ta bort alla dina whiteboards och data och kan inte ångras.')) {
        document.getElementById('deleteAccountForm').classList.remove('hidden');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    switchProfileTab('password');
    
    document.querySelectorAll('[name="background_color"], [name="gradient_color_1"], [name="gradient_color_2"], [name="gradient_direction"]')
        .forEach(input => input.addEventListener('input', updatePreview));
        
    document.getElementById('background-upload')?.addEventListener('change', event => {
        const file = event.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = e => {
                document.getElementById('backgroundPreview').style.background = `url(${e.target.result}) center/cover`;
            }
            reader.readAsDataURL(file);
        }
    });
    
    document.querySelectorAll('input[name="custom_background_id"]').forEach(radio => {
        radio.addEventListener('change', updatePreview);
    });
});
</script>

<!-- Profile Modal -->
<div id="profileModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 overflow-hidden">
    <div class="bg-white rounded-lg w-full max-w-2xl p-6 m-4 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-semibold">Profilinställningar</h2>
            <button onclick="closeProfileModal()" class="p-2 hover:bg-gray-100 rounded-full">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="border-b border-gray-200 mb-6">
            <div class="flex space-x-6">
                <button onclick="switchProfileTab('password')" 
                        class="tab-button px-4 py-2 border-b-2 border-transparent hover:border-gray-300"
                        data-tab="password">
                    Byt lösenord
                </button>
                <button onclick="switchProfileTab('defaults')" 
                        class="tab-button px-4 py-2 border-b-2 border-transparent hover:border-gray-300"
                        data-tab="defaults">
                    Grundinställningar
                </button>
                <button onclick="switchProfileTab('backgrounds')" 
                        class="tab-button px-4 py-2 border-b-2 border-transparent hover:border-gray-300"
                        data-tab="backgrounds">
                    Mina bakgrunder
                </button>
                <button onclick="switchProfileTab('account')" 
                        class="tab-button px-4 py-2 border-b-2 border-transparent hover:border-gray-300"
                        data-tab="account">
                    Konto
                </button>
            </div>
        </div>

        <div id="passwordTab" class="tab-content">
            <form action="/api/update-password.php" method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Nuvarande lösenord</label>
                    <input type="password" name="current_password" required
                           class="w-full border rounded-md p-2">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Nytt lösenord</label>
                    <input type="password" name="new_password" required
                           class="w-full border rounded-md p-2">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Bekräfta nytt lösenord</label>
                    <input type="password" name="confirm_password" required
                           class="w-full border rounded-md p-2">
                </div>
                <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                    Uppdatera lösenord
                </button>
            </form>
        </div>

        <div id="defaultsTab" class="tab-content hidden">
            <form action="/api/update-default.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2">Bakgrundstyp</label>
                    <select name="background_type" onchange="toggleBackgroundOptions(this.value)"
                            class="w-full border rounded-md p-2">
                        <option value="color">Enfärgad</option>
                        <option value="gradient">Gradient</option>
                        <option value="image">Bild</option>
                        <option value="custom">Mina uppladdade bilder</option>
                    </select>
                </div>

                <!-- Preview -->
                <div id="backgroundPreview" class="w-full h-32 rounded-lg border"></div>

                <div id="colorOption">
                    <label class="block text-sm font-medium mb-2">Bakgrundsfärg</label>
                    <input type="color" name="background_color" value="#ffffff"
                           class="h-10 w-full border rounded-md">
                </div>

                <div id="gradientOption" class="hidden space-y-6">
                    <!-- Fördefinierade gradienter -->
                    <div>
                        <h3 class="text-sm font-medium text-gray-700 mb-4">Färggradienter</h3>
                        <div class="grid grid-cols-3 gap-4">
                            <?php
                            $gradients = [
                                ['name' => 'Soluppgång', 'value' => 'linear-gradient(to right, #ff6b6b, #feca57)'],
                                ['name' => 'Ocean', 'value' => 'linear-gradient(to right, #4facfe, #00f2fe)'],
                                ['name' => 'Skog', 'value' => 'linear-gradient(to right, #43c6ac, #f8ffae)'],
                                ['name' => 'Lavendel', 'value' => 'linear-gradient(to right, #834d9b, #d04ed6)'],
                                ['name' => 'Skymning', 'value' => 'linear-gradient(to right, #281483, #8f6ed5)'],
                                ['name' => 'Höst', 'value' => 'linear-gradient(to right, #f6d365, #fda085)']
                            ];
                            
                            foreach ($gradients as $gradient): ?>
                                <button 
                                    onclick="selectGradient('<?php echo $gradient['value']; ?>')"
                                    class="group relative h-20 rounded-lg hover:ring-2 hover:ring-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    style="background: <?php echo $gradient['value']; ?>"
                                    title="<?php echo htmlspecialchars($gradient['name']); ?>"
                                >
                                    <span class="invisible group-hover:visible absolute -top-8 left-1/2 transform -translate-x-1/2 
                                               px-2 py-1 bg-gray-900 text-white text-xs rounded whitespace-nowrap">
                                        <?php echo htmlspecialchars($gradient['name']); ?>
                                    </span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Anpassad gradient -->
                    <div class="pt-6 border-t border-gray-200 space-y-4">
                        <h3 class="text-sm font-medium text-gray-700">Anpassad gradient</h3>
                        <div>
                            <label class="block text-sm font-medium mb-2">Första färgen</label>
                            <input type="color" name="gradient_color_1" value="#ffffff"
                                   class="h-10 w-full border rounded-md">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2">Andra färgen</label>
                            <input type="color" name="gradient_color_2" value="#e2e2e2"
                                   class="h-10 w-full border rounded-md">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2">Riktning</label>
                            <select name="gradient_direction" class="w-full border rounded-md p-2">
                                <option value="to right">Vänster till höger</option>
                                <option value="to bottom">Uppifrån och ner</option>
                                <option value="to bottom right">Diagonal</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="imageOption" class="hidden">
                    <label class="block text-sm font-medium mb-2">Bakgrundsbild</label>
                    <div class="border-2 border-dashed rounded-lg p-4 text-center">
                        <input type="file" name="background_image" accept="image/*" class="hidden" id="background-upload">
                        <label for="background-upload" class="cursor-pointer">
                            <div class="mx-auto h-12 w-12 text-gray-400">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                          d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                </svg>
                            </div>
                            <p class="mt-2 text-sm text-gray-600">Klicka för att välja bild</p>
                        </label>
                    </div>
                </div>

                <div id="customOption" class="hidden">
                    <?php
                    // Get user's custom backgrounds
                    $stmt = $pdo->prepare("SELECT id, name, image_path FROM user_backgrounds WHERE user_id = ?");
                    $stmt->execute([$userId]);
                    $customBackgrounds = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    
                    <div class="space-y-4">
                        <h3 class="text-sm font-medium text-gray-700">Välj bland dina uppladdade bakgrunder</h3>
                        
                        <?php if (empty($customBackgrounds)): ?>
                            <p class="text-gray-500 text-sm">Du har inga uppladdade bakgrunder ännu. Gå till fliken "Mina bakgrunder" för att ladda upp.</p>
                        <?php else: ?>
                            <div class="grid grid-cols-3 gap-3">
                                <?php foreach ($customBackgrounds as $bg): ?>
                                    <div class="relative">
                                        <input type="radio" 
                                               name="custom_background_id" 
                                               id="bg_<?= $bg['id'] ?>" 
                                               value="<?= $bg['id'] ?>"
                                               data-path="<?= htmlspecialchars($bg['image_path']) ?>"
                                               class="hidden peer">
                                        <label for="bg_<?= $bg['id'] ?>" 
                                               class="block h-24 w-full rounded-lg border-2 overflow-hidden peer-checked:border-blue-500 peer-checked:ring-2 peer-checked:ring-blue-500">
                                            <div class="h-full w-full bg-center bg-cover" 
                                                 style="background-image: url('<?= htmlspecialchars($bg['image_path']) ?>')"></div>
                                        </label>
                                        <span class="block text-xs text-center mt-1 truncate"><?= htmlspecialchars($bg['name']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="background_value" id="customBackgroundPath">
                        <?php endif; ?>
                    </div>
                </div>

                <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                    Spara inställningar
                </button>
            </form>
        </div>

        <!-- Custom Background Management Tab -->
        <div id="backgroundsTab" class="tab-content hidden">
            <div class="mb-6">
                <h3 class="text-lg font-medium text-gray-900 mb-2">Mina uppladdade bakgrunder</h3>
                <p class="text-sm text-gray-600 mb-4">Du kan ladda upp upp till 3 egna bakgrundsbilder som kan användas på dina whiteboards.</p>
                
                <?php
                // Get user's custom backgrounds
                $stmt = $pdo->prepare("SELECT id, name, image_path, created_at FROM user_backgrounds WHERE user_id = ?");
                $stmt->execute([$userId]);
                $userBackgrounds = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $backgroundCount = count($userBackgrounds);
                ?>

                <!-- Display existing backgrounds -->
                <?php if ($backgroundCount > 0): ?>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                        <?php foreach ($userBackgrounds as $background): ?>
                            <div class="bg-white border rounded-lg overflow-hidden">
                                <div class="h-32 bg-cover bg-center" style="background-image: url('<?= htmlspecialchars($background['image_path']) ?>')"></div>
                                <div class="p-3">
                                    <h4 class="font-medium text-gray-800 truncate"><?= htmlspecialchars($background['name']) ?></h4>
                                    <p class="text-xs text-gray-500 mb-2">Uppladdad: <?= date('Y-m-d', strtotime($background['created_at'])) ?></p>
                                    <form action="/api/user-backgrounds.php" method="POST" class="mt-2">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="background_id" value="<?= $background['id'] ?>">
                                        <button type="submit" 
                                                class="w-full text-sm px-3 py-1.5 bg-red-100 text-red-700 rounded hover:bg-red-200 flex items-center justify-center">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                            Ta bort
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Upload new background form -->
                <?php if ($backgroundCount < 3): ?>
                    <form action="/api/user-backgrounds.php" method="POST" enctype="multipart/form-data" class="border-t pt-4">
                        <input type="hidden" name="action" value="upload">
                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-1" for="background_name">Namn på bakgrund</label>
                            <input type="text" name="background_name" id="background_name" maxlength="100" required
                                   class="w-full border rounded-md p-2" placeholder="T.ex. 'Blå himmel'">
                        </div>
                        <div class="mb-4">
    <label class="block text-sm font-medium mb-1" for="background_image_upload">Välj bild</label>
    <div class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center relative" id="upload-container">
        <input type="file" name="background_image" id="background_image_upload" required accept="image/*" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
        <div class="mx-auto h-12 w-12 text-gray-400">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                      d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
            </svg>
        </div>
        <p class="mt-2 text-sm text-gray-600" id="file-text">Klicka för att välja bild</p>
        <p class="text-xs text-gray-500">(Max 3MB, JPEG, PNG, eller WEBP)</p>
        
        <!-- Förhandsvisning som visas när en fil är vald -->
        <div id="preview-container" class="hidden mt-4">
            <div id="image-preview" class="w-32 h-32 mx-auto bg-cover bg-center rounded-md border"></div>
            <p id="selected-filename" class="mt-2 text-sm font-medium text-blue-600"></p>
        </div>
    </div>
</div>
<script>
   // Förbättrad filuppladdningshantering med uppladdningsindikator
(function() {
    const fileInput = document.getElementById('background_image_upload');
    const fileText = document.getElementById('file-text');
    const previewContainer = document.getElementById('preview-container');
    const imagePreview = document.getElementById('image-preview');
    const selectedFilename = document.getElementById('selected-filename');
    const uploadContainer = document.getElementById('upload-container');
    
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                // Visa filens namn
                const fileName = this.files[0].name;
                fileText.textContent = 'Fil vald';
                fileText.classList.add('text-blue-600', 'font-medium');
                selectedFilename.textContent = fileName;
                
                // Visa förhandsvinsingscontainern
                previewContainer.classList.remove('hidden');
                
                // Ändra stil på uppladdningsområdet
                uploadContainer.classList.add('border-blue-400', 'bg-blue-50');
                
                // Läs filen och visa förhandsvisning
                const reader = new FileReader();
                reader.onload = function(e) {
                    imagePreview.style.backgroundImage = `url('${e.target.result}')`;
                };
                reader.readAsDataURL(this.files[0]);
                
                // Ta bort alert-prompten - vi använder bara visuell feedback
            } else {
                // Återställ till ursprungsläge
                fileText.textContent = 'Klicka för att välja bild';
                fileText.classList.remove('text-blue-600', 'font-medium');
                previewContainer.classList.add('hidden');
                uploadContainer.classList.remove('border-blue-400', 'bg-blue-50');
            }
        });
    }
})();
</script>
                        <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                            Ladda upp bakgrund
                        </button>
                    </form>
                <?php else: ?>
                    <div class="bg-yellow-50 text-yellow-700 p-4 rounded-lg">
                        <p class="font-medium">Du har nått maxgränsen för uppladdade bakgrunder.</p>
                        <p class="text-sm mt-1">Du kan ta bort befintliga bakgrunder för att ladda upp nya.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Account Management Tab -->
        <div id="accountTab" class="tab-content hidden">
            <div class="mb-6">
                <h3 class="text-lg font-medium text-gray-900 mb-2">Kontoinställningar</h3>
                
                <div class="border-t pt-4 mt-4">
                    <h4 class="text-md font-medium text-red-700 mb-2">Radera konto</h4>
                    <p class="text-sm text-gray-600 mb-4">Om du raderar ditt konto kommer alla dina whiteboards och uppladdade bakgrunder att tas bort permanent. Denna åtgärd kan inte ångras.</p>
                    
                    <button onclick="confirmDeleteAccount()" 
                            class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                  d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                        Radera mitt konto
                    </button>
                    
                    <!-- Delete account confirmation form (hidden by default) -->
                    <form id="deleteAccountForm" action="/api/delete-account.php" method="POST" class="hidden mt-4 p-4 border border-red-300 rounded-lg bg-red-50">
                        <p class="text-sm text-red-700 mb-4">För att bekräfta radering av ditt konto, ange ditt lösenord:</p>
                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-1" for="delete_password">Lösenord</label>
                            <input type="password" name="password" id="delete_password" required
                                   class="w-full border border-red-300 rounded-md p-2">
                        </div>
                        <div class="flex items-center">
                            <input type="checkbox" id="confirm_delete" name="confirm_delete" required
                                   class="h-4 w-4 text-red-600 border-red-300 rounded">
                            <label for="confirm_delete" class="ml-2 block text-sm text-red-700">
                                Jag förstår att all min data kommer att raderas permanent
                            </label>
                        </div>
                        <div class="mt-4 flex gap-2">
                            <button type="button" onclick="document.getElementById('deleteAccountForm').classList.add('hidden')"
                                    class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">
                                Avbryt
                            </button>
                            <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                                Radera konto permanent
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>



<!-- Toast Container -->
<div id="toastContainer" class="fixed bottom-4 right-4 z-50 flex flex-col space-y-2"></div>

<script>
/**
 * Visar en toast-notifikation
 * @param {string} message - Meddelandet som ska visas
 * @param {string} type - Typ av toast: 'success', 'error', 'warning', 'info'
 * @param {number} duration - Tid i millisekunder som toasten ska visas (standard: 3000)
 */
function showToast(message, type = 'info', duration = 3000) {
    const container = document.getElementById('toastContainer');
    
    // Skapa toast-element
    const toast = document.createElement('div');
    toast.className = 'transform transition-all duration-300 ease-in-out translate-x-full';
    
    // Sätt bakgrundsfärg och ikon baserat på typ
    let bgColor, icon;
    switch (type) {
        case 'success':
            bgColor = 'bg-green-500';
            icon = `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>`;
            break;
        case 'error':
            bgColor = 'bg-red-500';
            icon = `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>`;
            break;
        case 'warning':
            bgColor = 'bg-yellow-500';
            icon = `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>`;
            break;
        default: // info
            bgColor = 'bg-blue-500';
            icon = `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>`;
    }
    
    // Sätt innehåll för toast
    toast.innerHTML = `
        <div class="flex items-center justify-between p-3 ${bgColor} text-white rounded-lg shadow-lg min-w-[300px] max-w-md">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    ${icon}
                </div>
                <div class="ml-3 mr-7 font-medium">${message}</div>
            </div>
            <button class="p-1 ml-auto -mr-1 rounded-full hover:bg-white hover:bg-opacity-20 focus:outline-none"
                    onclick="this.parentElement.parentElement.remove()">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
    `;
    
    // Lägg till toast i containern
    container.appendChild(toast);
    
    // Animera in
    setTimeout(() => {
        toast.classList.remove('translate-x-full');
        toast.classList.add('translate-x-0');
    }, 10);
    
    // Sätt timer för automatisk borttagning
    setTimeout(() => {
        toast.classList.remove('translate-x-0');
        toast.classList.add('translate-x-full');
        
        // Ta bort från DOM efter animation
        setTimeout(() => {
            if (toast.parentElement) {
                toast.remove();
            }
        }, 300);
    }, duration);
    
    return toast;
}

</script>

<script>
    function updateBackgroundsList() {
    // Only run this function if we're on the dashboard page and the backgrounds tab exists
    const backgroundsTab = document.getElementById('backgroundsTab');
    if (!backgroundsTab) return;
    
    // Show loading indicator
    const listContainer = backgroundsTab.querySelector('.grid');
    if (listContainer) {
        listContainer.innerHTML = `
            <div class="col-span-full flex justify-center items-center py-8">
                <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>
        `;
        
        // Fetch updated backgrounds list
        fetch('/api/get-user-backgrounds.php', {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const backgrounds = data.backgrounds;
                
                if (backgrounds.length === 0) {
                    // No backgrounds
                    listContainer.innerHTML = `
                        <div class="col-span-full text-center py-4 text-gray-500">
                            Du har inga uppladdade bakgrunder ännu.
                        </div>
                    `;
                } else {
                    // Build backgrounds list
                    let html = '';
                    backgrounds.forEach(bg => {
                        html += `
                            <div class="bg-white border rounded-lg overflow-hidden">
                                <div class="h-32 bg-cover bg-center" style="background-image: url('${bg.image_path}')"></div>
                                <div class="p-3">
                                    <h4 class="font-medium text-gray-800 truncate">${bg.name}</h4>
                                    <p class="text-xs text-gray-500 mb-2">Uppladdad: ${new Date(bg.created_at).toLocaleDateString()}</p>
                                    <form action="/api/user-backgrounds.php" method="POST" class="mt-2">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="background_id" value="${bg.id}">
                                        <button type="submit" 
                                                class="w-full text-sm px-3 py-1.5 bg-red-100 text-red-700 rounded hover:bg-red-200 flex items-center justify-center">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                            Ta bort
                                        </button>
                                    </form>
                                </div>
                            </div>
                        `;
                    });
                    
                    listContainer.innerHTML = html;
                    
                    // Update delete buttons to use AJAX
                    listContainer.querySelectorAll('form').forEach(form => {
                        form.addEventListener('submit', function(e) {
                            e.preventDefault();
                            
                            const formData = new FormData(this);
                            
                            fetch('/api/user-backgrounds.php', {
                                method: 'POST',
                                body: formData,
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                }
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    // Show success toast
                                    if (typeof showToast === 'function') {
                                        showToast(data.toast.message, data.toast.type);
                                    }
                                    // Refresh backgrounds list
                                    updateBackgroundsList();
                                } else {
                                    // Show error toast
                                    if (typeof showToast === 'function') {
                                        showToast(data.toast.message, data.toast.type);
                                    }
                                }
                            })
                            .catch(error => {
                                console.error('Delete error:', error);
                                if (typeof showToast === 'function') {
                                    showToast('Ett fel uppstod vid borttagning av bakgrunden.', 'error');
                                }
                            });
                        });
                    });
                }
                
                // Update custom background options in defaults tab
                updateCustomBackgroundOptions(backgrounds);
            } else {
                // Error
                listContainer.innerHTML = `
                    <div class="col-span-full text-center py-4 text-red-500">
                        Ett fel uppstod vid hämtning av bakgrunder.
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            listContainer.innerHTML = `
                <div class="col-span-full text-center py-4 text-red-500">
                    Ett fel uppstod vid kommunikation med servern.
                </div>
            `;
        });
    }
}

/**
 * Update custom background options in the defaults tab
 */
function updateCustomBackgroundOptions(backgrounds) {
    const customOption = document.getElementById('customOption');
    if (!customOption) return;
    
    const container = customOption.querySelector('.grid');
    if (!container) return;
    
    if (backgrounds.length === 0) {
        container.innerHTML = `
            <p class="text-gray-500 text-sm">Du har inga uppladdade bakgrunder ännu. Gå till fliken "Mina bakgrunder" för att ladda upp.</p>
        `;
    } else {
        let html = '';
        backgrounds.forEach(bg => {
            html += `
                <div class="relative">
                    <input type="radio" 
                           name="custom_background_id" 
                           id="bg_${bg.id}" 
                           value="${bg.id}"
                           data-path="${bg.image_path}"
                           class="hidden peer">
                    <label for="bg_${bg.id}" 
                           class="block h-24 w-full rounded-lg border-2 overflow-hidden peer-checked:border-blue-500 peer-checked:ring-2 peer-checked:ring-blue-500">
                        <div class="h-full w-full bg-center bg-cover" 
                             style="background-image: url('${bg.image_path}')"></div>
                    </label>
                    <span class="block text-xs text-center mt-1 truncate">${bg.name}</span>
                </div>
            `;
        });
        
        container.innerHTML = html;
        
        // Add change event listeners
        container.querySelectorAll('input[type="radio"]').forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.checked) {
                    const imagePath = this.dataset.path;
                    const preview = document.getElementById('backgroundPreview');
                    if (preview) {
                        preview.style.background = `url(${imagePath}) center/cover`;
                    }
                    
                    const pathInput = document.getElementById('customBackgroundPath');
                    if (pathInput) {
                        pathInput.value = imagePath;
                    }
                }
            });
        });
    }
}

function toggleWhiteboardPassword(boardId) {
    document.getElementById('passwordBoardId').value = boardId;
    document.getElementById('passwordModal').classList.remove('hidden');
}
</script>

<!-- Modal för lösenordsinställningar -->
<div id="passwordModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Lösenordsskydd</h3>
        <form method="POST">
            <input type="hidden" name="action" value="update_password">
            <input type="hidden" name="board_id" id="passwordBoardId">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="boardPassword">
                    Lösenord (lämna tomt för att ta bort lösenordsskydd)
                </label>
                <input type="password" name="password" id="boardPassword" 
                       class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                       placeholder="Lämna tomt för att ta bort lösenord">
            </div>
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="document.getElementById('passwordModal').classList.add('hidden')"
                        class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300">
                    Avbryt
                </button>
                <button type="submit"
                        class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                    Spara
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'footer-dashboard.php'; ?>

</body>
</html>
