<?php
require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/board-access.php';

header('Content-Type: application/json');

$db = new Database();
$pdo = $db->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['widget_id']) || !isset($data['position_x']) || !isset($data['position_y'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }
    
    require_widget_access($pdo, $data['widget_id']);

    try {
        $stmt = $pdo->prepare("
            UPDATE widgets
            SET position_x = ?,
                position_y = ?, 
                updated_at = NOW() 
            WHERE id = ?
        ");
        
        $stmt->execute([
            $data['position_x'],
            $data['position_y'],
            $data['widget_id']
        ]);
        
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}