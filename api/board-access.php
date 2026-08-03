<?php
// Delad åtkomstkontroll för widget-API:erna.
//
// En besökare får läsa/ändra en whiteboard om:
//  1. Tavlan har öppnats i sessionen via whiteboard.php
//     (som registrerar den efter ev. lösenordskontroll), eller
//  2. Den inloggade användaren äger tavlan.
//
// Detta stoppar blind uppräkning av widget-ID:n utan att bryta
// det anonyma delningsflödet (alla med board-koden kan redigera).

if (session_status() === PHP_SESSION_NONE) { session_start(); }

function board_access_denied(): void {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Åtkomst nekad']);
    exit;
}

function require_board_access(PDO $pdo, $whiteboardId): void {
    $whiteboardId = (int) $whiteboardId;
    if ($whiteboardId <= 0) {
        board_access_denied();
    }

    if (!empty($_SESSION['opened_boards'][$whiteboardId])) {
        return;
    }

    $userId = $_SESSION['user_id'] ?? null;
    if ($userId !== null) {
        $stmt = $pdo->prepare("SELECT 1 FROM whiteboards WHERE id = ? AND user_id = ?");
        $stmt->execute([$whiteboardId, $userId]);
        if ($stmt->fetch()) {
            return;
        }
    }

    board_access_denied();
}

function require_widget_access(PDO $pdo, $widgetId): array {
    $stmt = $pdo->prepare("SELECT * FROM widgets WHERE id = ?");
    $stmt->execute([(int) $widgetId]);
    $widget = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$widget) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Widget hittades inte']);
        exit;
    }

    require_board_access($pdo, $widget['whiteboard_id']);
    return $widget;
}
