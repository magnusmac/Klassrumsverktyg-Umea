<?php
session_start();
require_once __DIR__ . '/src/Config/Database.php';

$db = new Database();
$pdo = $db->getConnection();

$pollCode = $_GET['poll_code'] ?? '';
if (!$pollCode) {
    die("Ingen omröstningskod angavs.");
}

// Hämta poll och alternativ
$stmt = $pdo->prepare("
    SELECT p.*, p.show_results, o.id as option_id, o.text, o.votes 
    FROM polls p
    JOIN poll_options o ON p.id = o.poll_id
    WHERE p.poll_code = ?
");
$stmt->execute([$pollCode]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$results) {
    die("Omröstningen hittades inte.");
}

$poll = [
    'question' => $results[0]['question'],
    'is_active' => $results[0]['is_active'],
    'show_results' => $results[0]['show_results'], // Lägg till denna rad
    'options' => array_map(function($row) {
        return [
            'id' => $row['option_id'],
            'text' => $row['text'],
            'votes' => $row['votes']
        ];
    }, $results)
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $poll['is_active']) {
    $optionId = $_POST['vote'] ?? null;
    if ($optionId) {
        $stmt = $pdo->prepare("UPDATE poll_options SET votes = votes + 1 WHERE id = ?");
        $stmt->execute([$optionId]);
        header("Location: vote.php?poll_code=$pollCode&voted=1");
        exit;
    }
}

$voted = isset($_GET['voted']);
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rösta</title>
    <link rel="stylesheet" href="/assets/vendor/tailwind/tailwind.min.css">
</head>
<body class="bg-gray-100 min-h-screen py-12 px-4">
    <div class="max-w-lg mx-auto bg-white rounded-lg shadow-lg overflow-hidden">
        <div class="p-6">
            <h1 class="text-2xl font-bold mb-6"><?= htmlspecialchars($poll['question']) ?></h1>
            
            <?php if ($voted): ?>
                <div class="mb-6 p-3 bg-green-100 text-green-700 rounded">
                    Tack för din röst!
                </div>
            <?php endif; ?>

            <?php if ($poll['is_active'] && !$voted): ?>
                <form method="POST" class="mb-8">
                    <?php foreach ($poll['options'] as $option): ?>
                        <label class="block mb-3 p-3 border rounded hover:bg-gray-50 cursor-pointer">
                            <input type="radio" name="vote" value="<?= $option['id'] ?>" required 
                                   class="mr-3">
                            <?= htmlspecialchars($option['text']) ?>
                        </label>
                    <?php endforeach; ?>
                    <button type="submit" 
                            class="w-full mt-4 px-6 py-3 bg-blue-500 text-white rounded hover:bg-blue-600">
                        Rösta
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($poll['show_results']): ?>
                <div class="border-t pt-6">
                    <h2 class="text-xl font-semibold mb-4">Resultat</h2>
                    <?php 
                    $totalVotes = array_sum(array_column($poll['options'], 'votes'));
                    foreach ($poll['options'] as $option):
                        $percentage = $totalVotes > 0 ? round(($option['votes'] / $totalVotes) * 100) : 0;
                    ?>
                        <div class="mb-4">
                            <div class="flex justify-between mb-1">
                                <span><?= htmlspecialchars($option['text']) ?></span>
                                <span><?= $percentage ?>%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-4">
                                <div class="bg-blue-500 h-4 rounded-full transition-all" 
                                     style="width: <?= $percentage ?>%"></div>
                            </div>
                            <div class="text-sm text-gray-600 mt-1"><?= $option['votes'] ?> röster</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif (!$poll['show_results'] && $voted): ?>
                <div class="text-center p-4">
                    <p class="text-lg">Tack för din röst!</p>
                    <p class="text-gray-600">Resultatet visas när omröstningen är avslutad.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>