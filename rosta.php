<?php
session_start();
require_once __DIR__ . '/src/Config/Database.php';

$error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pollCode = $_POST['poll_code'] ?? '';

    if ($pollCode) {
        $db = new Database();
        $pdo = $db->getConnection();

        // Kontrollera om koden finns i databasen
        $stmt = $pdo->prepare("SELECT id FROM polls WHERE poll_code = ?");
        $stmt->execute([$pollCode]);
        $poll = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($poll) {
            // Koden är giltig, omdirigera till vote.php
            header("Location: vote.php?poll_code=$pollCode");
            exit;
        } else {
            // Ogiltig kod, visa felmeddelande
            $error = true;
        }
    } else {
        $error = true;
    }
}
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ange röstningskod</title>
    <link rel="stylesheet" href="/assets/vendor/tailwind/tailwind.min.css">
    <style>
        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-2xl p-8">
        <h1 class="text-3xl font-bold text-center text-gray-800 mb-6">Ange din röstningskod</h1>

        <?php if ($error): ?>
            <div class="fade-in mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg shadow-sm">
                <strong>Fel:</strong> Ogiltig eller saknad röstningskod. Vänligen försök igen.
            </div>
        <?php endif; ?>

        <form action="" method="POST" class="space-y-4">
            <div>
                <label for="poll_code" class="block text-sm font-medium text-gray-700 mb-1">Röstningskod</label>
                <input type="text" name="poll_code" id="poll_code" required 
                       class="w-full p-4 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-400 focus:border-blue-400 transition duration-200">
            </div>
            
            <button type="submit" 
                    class="w-full px-4 py-3 bg-blue-500 text-white text-lg font-semibold rounded-xl shadow hover:bg-blue-600 transition duration-200">
                Gå till omröstning
            </button>
        </form>
    </div>
</body>
</html>
