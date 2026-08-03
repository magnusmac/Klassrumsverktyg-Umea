<?php
require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/board-access.php';

header('Content-Type: application/json');

$db = new Database();
$pdo = $db->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['widget_id']) || !isset($data['settings'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }
    
    // Hämta befintliga inställningar (kontrollerar även åtkomst)
    $widget = require_widget_access($pdo, $data['widget_id']);

    // Slå samman befintliga och nya inställningar
    $currentSettings = json_decode($widget['settings'] ?? '{}', true) ?? [];
    $newSettings = array_merge($currentSettings, $data['settings']);

    if (isset($newSettings['encodedNames'])) {
        // Redan base64-kodat från JavaScript
        $newSettings['encodedNames'] = $newSettings['encodedNames'];
    }
    
    // Uppdatera inställningar
    $stmt = $pdo->prepare("UPDATE widgets SET settings = ?, updated_at = NOW() WHERE id = ?");
    
    try {
        $stmt->execute([json_encode($newSettings), $data['widget_id']]);
        echo json_encode(['success' => true, 'settings' => $newSettings]);
    } catch (PDOException $e) {
        error_log('update-widget-settings: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Databasfel']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}