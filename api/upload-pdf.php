<?php
session_start();
require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/board-access.php';

header('Content-Type: application/json');

// Kontrollera att anroparen har åtkomst till widgetens whiteboard
$db = new Database();
$pdo = $db->getConnection();
require_widget_access($pdo, $_POST['widget_id'] ?? 0);

// Kontrollera att en fil har laddats upp
if (!isset($_FILES['pdf'])) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['pdf'];
$max_size = 25 * 1024 * 1024; // 25MB

// Validera filtyp (både MIME och filändelse)
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$is_pdf_mime = in_array($file['type'], ['application/pdf', 'application/x-pdf', 'application/octet-stream']);
if ($extension !== 'pdf' || !$is_pdf_mime) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type']);
    exit;
}

// Validera filstorlek
if ($file['size'] > $max_size) {
    echo json_encode(['success' => false, 'error' => 'File too large']);
    exit;
}

// Skapa uppladdningsmapp om den inte finns
$upload_dir = __DIR__ . '/../assets/uploads/pdfs/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Generera unikt filnamn
$filename = uniqid('pdf_') . '_' . time() . '.pdf';
$filepath = $upload_dir . $filename;

// Försök ladda upp filen
if (move_uploaded_file($file['tmp_name'], $filepath)) {
    $relative_path = '/assets/uploads/pdfs/' . $filename;
    echo json_encode([
        'success' => true,
        'url' => $relative_path
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
}
