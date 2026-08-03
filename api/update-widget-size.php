<?php
require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/board-access.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['widget_id']) || !isset($data['size_w']) || !isset($data['size_h'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

try {
    $db = new Database();
    $pdo = $db->getConnection();

    require_widget_access($pdo, $data['widget_id']);

    $stmt = $pdo->prepare("UPDATE widgets SET size_w = ?, size_h = ?, updated_at = NOW() WHERE id = ?");
    $result = $stmt->execute([
        $data['size_w'],
        $data['size_h'],
        $data['widget_id']
    ]);
    
    if ($result) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to update widget size']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}