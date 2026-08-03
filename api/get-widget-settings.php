<?php
require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/board-access.php';

header('Content-Type: application/json; charset=utf-8');

$db = new Database();
$pdo = $db->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $widget = require_widget_access($pdo, $_GET['id'] ?? 0);

    echo json_encode(['settings' => json_decode($widget['settings'] ?? '{}', true) ?? []]);
    exit;
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
