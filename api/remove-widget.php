<?php
require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/board-access.php';

// Sätt rätt headers
header('Content-Type: application/json');

// Kontrollera att det är en POST-request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Läs in JSON-data från request body
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['widget_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing widget_id']);
    exit;
}

try {
    $db = new Database();
    $pdo = $db->getConnection();

    require_widget_access($pdo, $data['widget_id']);

    // Starta transaktion
    $pdo->beginTransaction();
    
    // Kontrollera om det finns en kopplad poll
    $stmt = $pdo->prepare("SELECT id FROM polls WHERE widget_id = ?");
    $stmt->execute([$data['widget_id']]);
    $poll = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($poll) {
        // Ta bort poll options först
        $stmt = $pdo->prepare("DELETE FROM poll_options WHERE poll_id = ?");
        $stmt->execute([$poll['id']]);
        
        // Ta bort pollen
        $stmt = $pdo->prepare("DELETE FROM polls WHERE id = ?");
        $stmt->execute([$poll['id']]);
    }
    
    // Ta bort widgeten
    $stmt = $pdo->prepare("DELETE FROM widgets WHERE id = ?");
    $result = $stmt->execute([$data['widget_id']]);
    
    if ($result) {
        $pdo->commit();
        echo json_encode(['success' => true]);
    } else {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to delete widget']);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('remove-widget: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Databasfel']);
}