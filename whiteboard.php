<?php
session_start();
require_once __DIR__ . '/src/Config/Database.php';

// --- Policy helpers ---
function get_setting(PDO $pdo, string $key, $default = null) {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['setting_value'] ?? $default;
}

function get_client_ip(): string {
    // Använd X-Forwarded-For endast om du litar på din reverse proxy
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function ip_in_cidrs(string $ip, array $cidrs): bool {
    foreach ($cidrs as $cidr) {
        $cidr = trim($cidr);
        if ($cidr === '') continue;
        if (strpos($cidr, '/') === false) {
            if (strcasecmp($ip, $cidr) === 0) return true; // exakt IP
            continue;
        }
        // IPv4 CIDR
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($cidr, ':') === false) {
            list($subnet, $mask) = explode('/', $cidr, 2);
            if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
            $mask = (int)$mask;
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $maskLong = -1 << (32 - $mask);
            if (($ipLong & $maskLong) === ($subnetLong & $maskLong)) return true;
        }
        // IPv6 CIDR
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && strpos($cidr, ':') !== false) {
            list($subnet, $mask) = explode('/', $cidr, 2);
            $mask = (int)$mask;
            $ipBin     = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) continue;
            $bytes = intdiv($mask, 8);
            $bits  = $mask % 8;
            if (strncmp($ipBin, $subnetBin, $bytes) !== 0) continue;
            if ($bits === 0) return true;
            $ipByte     = ord($ipBin[$bytes]);
            $subnetByte = ord($subnetBin[$bytes]);
            $maskByte   = ~((1 << (8 - $bits)) - 1) & 0xFF;
            if (($ipByte & $maskByte) === ($subnetByte & $maskByte)) return true;
        }
    }
    return false;
}

// --- Bootstrap DB och läs policyer ---
$db = new Database();
$pdo = $db->getConnection();

$siteName = (string) get_setting($pdo, 'site_name', 'Klassrumsverktyg');
$requireLoginForCreation = get_setting($pdo, 'require_login_for_whiteboard_creation', '0') === '1';
$allowedCidrsRaw = (string) get_setting($pdo, 'allowed_whiteboard_creator_ip_ranges', '');
$allowedCidrs = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $allowedCidrsRaw)));

// --- Skapandeguard: om ingen board-parameter ges tolkar vi det som "försök att skapa/öppna ny" ---
if (!isset($_GET['board'])) {
    // 1) Kräv inloggning om policy säger det
    if ($requireLoginForCreation && (($_SESSION['user_id'] ?? null) === null)) {
        http_response_code(403);
        header('Location: /login?r=whiteboard_login_required');
        exit;
    }
    // 2) IP-begränsning om lista finns
    if (!empty($allowedCidrs)) {
        $ip = get_client_ip();
        if (!ip_in_cidrs($ip, $allowedCidrs)) {
            http_response_code(403);
            header('Location: /?error=whiteboard_ip_blocked');
            exit;
        }
    }
    // Om alla kontroller passerar, fortsätt befintligt flöde
    header('Location: /');
    exit;
}

$boardCode = htmlspecialchars($_GET['board']);
$db = new Database();
$pdo = $db->getConnection();

// Get whiteboard data
$stmt = $pdo->prepare("SELECT * FROM whiteboards WHERE board_code = ?");
$stmt->execute([$boardCode]);
$whiteboard = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$whiteboard) {
    header('Location: /?error=invalid_board');
    exit;
}

// Check password
if ($whiteboard['password'] && !isset($_SESSION['verified_boards'][$whiteboard['board_code']])) {
    include 'password_prompt.php';
    exit;
}

// Check expiration
if ($whiteboard['expires_at'] && strtotime($whiteboard['expires_at']) < time()) {
    header('Location: /?error=expired_board');
    exit;
}

// Registrera att tavlan öppnats i denna session – widget-API:erna
// (api/board-access.php) kräver detta för att tillåta ändringar.
$_SESSION['opened_boards'][$whiteboard['id']] = true;

// Get widgets
$stmt = $pdo->prepare("SELECT * FROM widgets WHERE whiteboard_id = ? ORDER BY created_at");
$stmt->execute([$whiteboard['id']]);
$widgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Update last_used
$stmt = $pdo->prepare("UPDATE whiteboards SET last_used = NOW() WHERE id = ?");
$stmt->execute([$whiteboard['id']]);
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Whiteboard - <?php echo htmlspecialchars($boardCode); ?></title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#111827">
    <link rel="apple-touch-icon" href="/assets/img/icon-192.png">
    <link href="/assets/vendor/tailwind/tailwind.min.css" rel="stylesheet">
    <script src="/assets/vendor/lucide/lucide.min.js"></script>
    <!-- Replace the old interact.js with the newer version -->
    <script src="/assets/vendor/interactjs/interact.min.js"></script>
    
    <script src="/assets/js/background-handler.js"></script>
    <script src="/assets/js/widgets/poll-editor.js"></script>
    <style>
        /* Base CSS styles from the original file */
        /* Sidebar and overlay styles */
        .sidebar {
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
        }

        .sidebar.open {
            transform: translateX(0);
        }

        .overlay {
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease-in-out, visibility 0.3s ease-in-out;
        }

        .overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* Base widget structure with interact.js modifications */
        .widget {
            position: absolute;
            background: white;
            border-radius: 0.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            min-width: 300px;
            min-height: 300px;
            cursor: move;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            z-index: 1;
            touch-action: none; /* Prevent browser touch actions inside widgets */
        }

        .widget.active {
            z-index: 10; /* Higher z-index when the widget is active/focused */
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .widget.dragging {
            opacity: 0.9;
        }
        
        .widget.resizing {
            opacity: 0.9;
        }

        /* Widget header */
        .widget-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 1rem;
            width: 100%;
            cursor: grab;
            touch-action: none; /* Prevent browser touch actions */
        }
        
        .widget-header:active {
            cursor: grabbing;
        }

        .widget-content-wrapper {
            position: relative;
            width: 100%;
            height: calc(100% - 2.5rem);
            flex-grow: 1;
            display: flex;
            padding: 1rem;
        }

        .widget-container {
            position: relative;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .widget-scaling-container {
            position: relative;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        /* Clock specific styling */
        .clock-display {
            font-weight: bold;
            line-height: 1;
            white-space: nowrap;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: calc(0.25 * min(var(--widget-width, 200px), var(--widget-height, 200px)));
        }

        /* Timer specific styling */
        .timer-display {
            width: 100%;
            height: 60%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
        }

        .timer-inputs {
            display: flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
        }

        .timer-separator {
            font-weight: bold;
            line-height: 1;
            margin: 0 -0.1em;
            font-size: calc(0.15 * min(var(--widget-width, 200px), var(--widget-height, 200px)));
        }

        /* Progress bar styles */
        .progress-container {
            width: 100%;
            padding: 0 1rem;
            margin: 0.5rem 0;
        }

        .progress-bar-wrapper {
            width: 100%;
            height: 0.5rem;
            background: #e2e8f0;
            border-radius: 9999px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: #3b82f6;
            transition: width 1s linear;
        }

        /* Circular timer styles */
        .circular-timer {
            position: relative;
            width: 92%;            /* use more of the widget area */
            max-width: none;       /* allow it to grow with widget */
            aspect-ratio: 1;
            margin: 0.25rem auto;  /* tighter vertical spacing */
        }
        .circular-timer svg {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            transform: rotate(-90deg); /* start from 12 o'clock */
            z-index: 1;                 /* put the ring behind the digits */
            pointer-events: none;       /* don't block clicks/selections */
        }
        .circular-timer .timer-display {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2;                 /* ensure digits render above the ring */
            text-align: center;
            height: 100% !important;    /* override the generic 60% height */
            padding: 0 !important;      /* remove padding that could offset centering */
            line-height: 1;
        }

        /* Scale timer digits based on ring size */
        .circular-timer .timer-input,
        .circular-timer .timer-separator {
            line-height: 1;
            font-size: clamp(24px, calc(var(--ct-diameter, 300px) * 0.18), 120px);
        }

        /* Ensure the inner inputs wrapper is centered with no offsets */
        .circular-timer .timer-container,
        .circular-timer .timer-inputs {
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 0;
        }
        .circular-track {
            fill: none;
            stroke: #e5e7eb; /* tailwind slate-200 */
            opacity: 1;
        }
        .circular-progress {
            fill: none;
            stroke: #3b82f6; /* tailwind blue-500 */
            transition: stroke-dashoffset 0.1s linear; /* <-- Tillfälligt borttagen */
        }
        .circular-track, .circular-progress {
            stroke-width: 6;
        }
        .widget.small .circular-track, 
        .widget.small .circular-progress { stroke-width: 5; }
        .widget.large .circular-track, 
        .widget.large .circular-progress { stroke-width: 8; }
        .widget.small .circular-timer { width: 88%; }
        .widget.large .circular-timer { width: 70%; }

        /* Timer controls */
        .timer-controls {
            width: 100%;
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
        }

        /* Tighter layout for timer widgets */
        .widget[data-type="timer"] .widget-content-wrapper { padding: 0.5rem; }
        .widget[data-type="timer"] .widget-scaling-container { padding: 0.25rem; }
        .widget[data-type="timer"] .timer-controls { padding: 0.25rem 0.5rem; }

        /* Full-yta för inbäddning och PDF (ingen inre padding) */
        .widget[data-type="embed"] .widget-content-wrapper,
        .widget[data-type="pdf"] .widget-content-wrapper { padding: 0; }
        .widget[data-type="embed"] .widget-scaling-container,
        .widget[data-type="pdf"] .widget-scaling-container { padding: 0; align-items: stretch; }

        /* ===== Tavelläge – större knappar och pekytor för smartboards ===== */
        body.board-mode .widget-header { padding: 0.75rem 1rem; }
        body.board-mode .widget-header svg { width: 1.75rem; height: 1.75rem; }
        body.board-mode .widget-header button { padding: 0.25rem; font-size: 1.5rem; line-height: 1; }
        body.board-mode .widget-header span { font-size: 1.15rem; }

        body.board-mode .pdf-toolbar { gap: 0.5rem; padding: 0.5rem; }
        body.board-mode .pdf-toolbar button { padding: 0.55rem; }
        body.board-mode .pdf-toolbar svg { width: 1.6rem; height: 1.6rem; }
        body.board-mode .pdf-color-btn { width: 2.4rem !important; height: 2.4rem !important; }
        body.board-mode .pdf-page-indicator { font-size: 1.05rem; min-width: 4rem; }

        body.board-mode .namewheel-widget button,
        body.board-mode .dice-widget button,
        body.board-mode .embed-container button {
            font-size: 1.3rem;
            padding: 0.8rem 2rem;
        }
        body.board-mode .stopwatch-widget button {
            font-size: 1.15rem;
            padding: 0.7rem 1.1rem;
        }
        body.board-mode .stopwatch-widget .flex { flex-wrap: wrap; justify-content: center; }
        body.board-mode .dice-widget select { font-size: 1.2rem; padding: 0.4rem 0.6rem; }
        body.board-mode .dice-widget label { font-size: 1.05rem; }
        body.board-mode .stopwatch-laps { font-size: 1rem; max-height: 8rem; }

        body.board-mode .timer-controls button { padding: 0.6rem 1rem; }
        body.board-mode #boardModeToggle { background-color: #2563eb; color: #ffffff; }

        .timer-digit-container {
            display: inline-block;
            text-align: center;
        }

        .timer-container {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .timer-input {
            font-weight: bold;
            text-align: center;
            background: transparent;
            border: none;
            padding: 0;
            margin: 0;
            width: 3ch;
            -moz-appearance: textfield;
            font-size: calc(0.15 * min(var(--widget-width, 200px), var(--widget-height, 200px)));
        }

        /* Hide number input spinners */
        .timer-input::-webkit-inner-spin-button,
        .timer-input::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        /* Control button styles */
        .control-button {
            font-size: calc(0.05 * min(var(--widget-width, 200px), var(--widget-height, 200px)));
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            transition: all 0.2s ease;
            white-space: nowrap;
            min-width: 80px; /* Set a minimum width for consistency */
            text-align: center;
            min-height: 44px; /* Minimum touch target size */
        }

        /* Widget size classes */
        .widget.small .timer-input,
        .widget.small .timer-separator {
            font-size: calc(0.12 * min(var(--widget-width, 200px), var(--widget-height, 200px)));
        }

        .widget.medium .timer-input,
        .widget.medium .timer-separator {
            font-size: calc(0.15 * min(var(--widget-width, 200px), var(--widget-height, 200px)));
        }

        .widget.large .timer-input,
        .widget.large .timer-separator {
            font-size: calc(0.18 * min(var(--widget-width, 200px), var(--widget-height, 200px)));
        }

        /* Improved resize handle for better touch interactions */
        .resize-handle {
            position: absolute;
            right: 0;
            bottom: 0;
            width: 24px;
            height: 24px;
            cursor: se-resize;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24'%3E%3Cpath fill='rgba(0,0,0,0.3)' d='M22 22H12L22 12V22Z'/%3E%3C/svg%3E");
            background-position: bottom right;
            background-repeat: no-repeat;
            transition: opacity 0.2s;
            opacity: 0.4;
            touch-action: none;
        }

        .widget:hover .resize-handle {
            opacity: 0.7;
        }

        /* Text widget - improved design */
        .text-widget-container {
            position: relative;
            overflow: hidden;
            width: 100%;
            height: 100%;
            background: white;
        }

        /* Display mode with maximum text size */
        .text-display-wrapper {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: text;
            padding: 2px; /* Minimal padding */
        }

        .text-display {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            word-wrap: break-word;
            overflow-wrap: break-word;
            white-space: pre-wrap;
            line-height: 1.2; /* More compact line height */
            font-weight: 500;
            font-size: 16px; /* Default value before JavaScript takes over */
            padding: 0;
            margin: 0;
        }

        /* Edit mode - full size with scrolling */
        .text-editor {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            padding: 4px;
            resize: none;
            border: none;
            outline: none;
            font-size: 16px;
            line-height: 1.2;
            text-align: center;
            background: white;
            z-index: 1;
            overflow: auto; /* Allow scrolling in edit mode */
        }

        .hidden {
            display: none !important;
        }

        /* Mobile optimizations */
        @media (max-width: 768px) {
            .widget {
                min-width: 250px;
                min-height: 250px;
            }
            
            .control-button, 
            .timer-controls button {
                min-height: 44px;
                min-width: 44px;
                padding: 0.4rem 0.5rem;
            }
            
            .widget-header {
                padding: 0.4rem 0.75rem;
            }
            
            .resize-handle {
                width: 32px;
                height: 32px;
            }
        }

        /* Additional widget-specific styles from the original code */

/* Analog Clock styles */
.analog-clock-container {
    position: relative;
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 5%;
}

.analog-clock {
    position: relative;
    /* Take the smaller of width and height to maintain the circle */
    width: min(100%, 100%);
    height: min(100%, 100%);
    /* Force aspect-ratio to 1 (perfect circle) */
    aspect-ratio: 1;
    border-radius: 50%;
    background: white;
    box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
}

.clock-face {
    position: absolute;
    inset: 6%;
    border-radius: 50%;
    background: #f8f8f8;
    z-index: 1;
}

.clock-numbers {
    position: absolute;
    inset: 0;
    z-index: 2;
}

.hour-number {
    position: absolute;
    font-size: 1.5rem; /* Larger numbers for readability */
    font-weight: bold;
    color: #333;
    text-align: center;
    transform: translate(-50%, -50%);
    z-index: 3;
}

.hand {
    position: absolute;
    transform-origin: bottom center;
    bottom: 50%;
    left: 50%;
    z-index: 3;
}

.hour-hand {
    width: 2.5%;
    height: 30%;
    background: #333;
    border-radius: 4px;
    transform: translateX(-50%);
}

.minute-hand {
    width: 1.5%;
    height: 40%;
    background: #666;
    border-radius: 4px;
    transform: translateX(-50%);
}

.second-hand {
    width: 1%;
    height: 45%;
    background: #f44336;
    border-radius: 2px;
    transform: translateX(-50%);
}

.center-dot {
    position: absolute;
    width: 6%;
    height: 6%;
    background: #f44336;
    border-radius: 50%;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 4;
}

/* Todo list styles */
.scalable-content {
    display: flex;
    flex-direction: column;
    height: 100%;
    padding: 4%;
    gap: 4%;
}

.todo-list-container {
    flex: 1;
    min-height: 0;
    overflow: hidden;
}

.todo-list {
    height: 100%;
    display: flex;
    flex-direction: column;
    gap: 2%;
}

.todo-item {
    flex: 1;
    min-height: 0;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
}

.todo-content {
    height: 100%;
    display: flex;
    align-items: center;
    padding: 2% 4%;
    gap: 3%;
}

.checkbox-wrapper {
    position: relative;
    flex: none;
    width: 8%;
    aspect-ratio: 1;
    min-width: 20px;
    max-width: 32px;
}

.custom-checkbox {
    position: absolute;
    inset: 0;
    border: 2px solid #cbd5e1;
    border-radius: 4px;
    transition: all 0.2s;
}

.custom-checkbox.checked {
    background: #22c55e;
    border-color: #22c55e;
}

.custom-checkbox.checked::after {
    content: '';
    position: absolute;
    left: 50%;
    top: 45%;
    width: 40%;
    height: 20%;
    border: solid white;
    border-width: 0 0 2px 2px;
    transform: translate(-50%, -50%) rotate(-45deg);
}

.todo-text {
    flex: 1;
    font-size: clamp(14px, 4vh, 24px);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.delete-btn {
    flex: none;
    width: 8%;
    aspect-ratio: 1;
    min-width: 20px;
    max-width: 32px;
    color: #ef4444;
    font-size: clamp(16px, 4vh, 24px);
    opacity: 0.5;
}

.delete-btn:hover {
    opacity: 1;
}

.button-container {
    display: flex;
    gap: 2%;
    height: clamp(32px, 10%, 48px);
}

.add-btn, .clear-btn {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: 8px;
    font-size: clamp(12px, 3vh, 16px);
    padding: 0 16px;
}

.add-btn {
    background: #3b82f6;
    color: white;
}

.clear-btn {
    background: #64748b;
    color: white;
}

.btn-icon {
    width: clamp(16px, 4vh, 24px);
    height: clamp(16px, 4vh, 24px);
    stroke: currentColor;
    stroke-width: 2;
    fill: none;
}

/* Completed todo styling */
.todo-item.completed .todo-text {
    text-decoration: line-through;
    color: #64748b;
}

/* Groups widget styles */
.grid-cols-2 {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    grid-gap: 0.75rem;
    width: 100%;
    align-items: stretch;
}

.bg-blue-50 {
    transition: all 0.2s ease;
    height: 100%;
    border: 1px solid rgba(59, 130, 246, 0.2);
}

.bg-blue-50:hover {
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    transform: translateY(-2px);
}

.font-bold.text-blue-700 {
    font-size: 1.25rem;
    border-bottom: 1px solid rgba(59, 130, 246, 0.2);
    padding-bottom: 0.25rem;
    margin-bottom: 0.5rem;
}

/* Group animation delay */
.bg-blue-50:nth-child(1) { animation-delay: 0.05s; }
.bg-blue-50:nth-child(2) { animation-delay: 0.1s; }
.bg-blue-50:nth-child(3) { animation-delay: 0.15s; }
.bg-blue-50:nth-child(4) { animation-delay: 0.2s; }
.bg-blue-50:nth-child(5) { animation-delay: 0.25s; }
.bg-blue-50:nth-child(6) { animation-delay: 0.3s; }
.bg-blue-50:nth-child(7) { animation-delay: 0.35s; }
.bg-blue-50:nth-child(8) { animation-delay: 0.4s; }

/* QR code container */
.qr-container {
    width: 80%;       
    height: 80%;     
    max-width: 400px; 
    max-height: 400px;
    margin: auto;     
}

.qr-code {
    width: 100%;
    height: auto;
    object-fit: contain;
}

/* Poll styles */
.poll-question {
    font-size: calc(min(var(--widget-width), var(--widget-height)) * 0.05);
    max-width: 90%;
    word-wrap: break-word;
}

.poll-code {
    font-size: clamp(1rem, 1.5vw + 0.5rem, 2rem);
    word-break: break-word;
}

/* Traffic light widget */
.traffic-light-widget {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    height: 100%;
    width: 100%;
}

.traffic-light-option {
    width: 100%;
    padding: 0.75rem;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    cursor: pointer;
    transition: all 0.2s;
}

/* Brain break widget */
.brain-break-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    padding: 1rem;
    height: 100%;
    width: 100%;
}

.break-result {
    word-wrap: break-word;
    overflow-wrap: break-word;
    max-width: 100%;
    padding: 0.5rem;
}

/* Animation keyframes */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.fade-in {
    animation: fadeIn 0.2s ease-in;
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* Responsive media queries */
@media (max-width: 480px) {
    .grid-cols-2 {
        grid-template-columns: 1fr;
    }
    
    .widget .clock-display {
        font-size: calc(0.2 * min(var(--widget-width, 200px), var(--widget-height, 200px)));
    }
    
    .hour-number {
        font-size: 1.2rem;
    }
}

@media (min-width: 500px) {
    .hour-number {
        font-size: 1.8rem;
    }
}

@media (min-width: 640px) {
    .widget textarea {
        font-size: calc(0.12 * min(var(--widget-width), var(--widget-height)));
    }
    
    .break-result {
        font-size: calc(0.18 * min(var(--widget-width), var(--widget-height)));
    }
    
    .groups-list {
        font-size: calc(0.12 * min(var(--widget-width), var(--widget-height)));
    }
}

/* Custom confirm dialog styling */
#custom-confirm-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}

#custom-confirm-modal .bg-white {
    max-width: 90%;
    width: 400px;
    border-radius: 0.5rem;
    padding: 1.5rem;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    animation: modal-appear 0.3s ease-out;
}

#custom-confirm-modal h3 {
    margin-bottom: 1rem;
    font-weight: 600;
    font-size: 1.125rem;
}

#custom-confirm-modal p {
    margin-bottom: 1.5rem;
    color: #4b5563;
}

#custom-confirm-modal button {
    padding: 0.5rem 1rem;
    border-radius: 0.375rem;
    font-weight: 500;
    transition: all 0.2s;
    outline: none;
}

#custom-confirm-modal button:focus {
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.5);
}

@keyframes modal-appear {
    from {
        opacity: 0;
        transform: scale(0.9);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}
    </style>
    <!-- CSS for widget-specific styles is kept from original (truncated for brevity) -->
    <style>
        /* Lägg till detta i dina befintliga styles */
        body {
            <?php
            // Applicera bakgrund baserat på sparade värden
            if ($whiteboard['background_type'] === 'color') {
                echo "background-color: " . htmlspecialchars($whiteboard['background_value']) . ";";
                echo "background-image: none;";
            } else if ($whiteboard['background_type'] === 'gradient') {
                echo "background-image: " . htmlspecialchars($whiteboard['background_value']) . ";";
                echo "background-color: transparent;";
            } else if ($whiteboard['background_type'] === 'image') {
                echo "background-image: url('" . htmlspecialchars($whiteboard['background_value']) . "');";
                echo "background-color: transparent;";
                echo "background-size: cover;";
                echo "background-position: center;";
                echo "background-repeat: no-repeat;";
            }
            ?>
        }

        /* Prevent text selection during drag operations */
.widget {
    user-select: none;
    -webkit-user-select: none;
}

/* Re-enable text selection in specific areas where needed */
.widget input,
.widget textarea,
.text-editor,
.text-display {
    user-select: text;
    -webkit-user-select: text;
}

/* Disable selection during active drag */
body.dragging .widget * {
    user-select: none !important;
    -webkit-user-select: none !important;
    pointer-events: none;
}

/* Z-index hierarki för widgets och modaler */

/* Bas widget z-index */
.widget {
    z-index: 10;
}

/* Aktiv widget (när den har fokus eller dras) */
.widget.active, 
.widget.dragging, 
.widget.resizing {
    z-index: 100;
}

/* Modaler och dialoger för widgets */
#group-modal,
#group-settings-modal,
#custom-prompt-modal,
#custom-confirm-modal,
#breakLibraryModal,
#pollEditorModal,
#pollResultsModal,
#addBreakModal,
#editBreakModal,
#shareModal,
#break-settings-modal,
#helpModal {
    z-index: 10000 !important; /* Använd !important för att säkerställa att detta värde inte skrivs över */
}

/* Innehållsdelen av modaler - se till att de också har högt z-index */
#group-modal > div,
#group-settings-modal > div,
#custom-prompt-modal > div,
#custom-confirm-modal > div,
#breakLibraryModal > div,
#pollEditorModal > div,
#pollResultsModal > div,
#addBreakModal > div,
#editBreakModal > div,
#shareModal > div,
#helpModal > div {
    z-index: 10001 !important;
}

/* Notifieringar ska alltid vara överst */
#groups-notification,
.notification {
    z-index: 20000 !important;
}

/* Förhindra att interaktiva element i widgets får lägre z-index */
.widget button,
.widget input,
.widget select,
.widget textarea {
    z-index: auto; /* Låt dem ärva z-index från sin widget */
}

.image-container img {
    max-width: 100%;
    max-height: 100%;
    transition: all 0.3s ease;
}

.image-container {
    position: relative;
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
}
    </style>
    <script>
        function loadYouTubeAPI() {
            const tag = document.createElement('script');
            tag.src = "https://www.youtube.com/iframe_api";
            const firstScriptTag = document.getElementsByTagName('script')[0];
            firstScriptTag.parentNode.insertBefore(tag, firstScriptTag);
        }

        loadYouTubeAPI();
    </script>
    <script>
    const whiteboardUserId = <?php
        if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
            echo json_encode(intval($_SESSION['user_id'])); // Force integer and JSON encoding
        } else {
            echo 'null'; // Output JavaScript null
        }
    ?>;
</script>
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js');
        }
    </script>
</head>
<script>
    const whiteboardId = <?php echo $whiteboard['id']; ?>;
    const backgroundHandler = new BackgroundHandler(whiteboardId);
</script>

<body class="bg-gray-100 min-h-screen">
    <!-- Lägg till ljudelement för timer -->
<audio id="timer-sound" preload="auto">
  <source src="/assets/sounds/timer-end.mp3" type="audio/mpeg">
</audio>
    <!-- Toggle Buttons -->
    <button 
        id="sidebarToggle"
        class="fixed top-4 left-4 z-50 bg-white p-2 rounded-lg shadow-md hover:bg-gray-50 transition-colors"
        aria-label="Toggle Sidebar">
        <i data-lucide="menu" class="h-6 w-6"></i>
    </button>
    <button
        id="fullscreenToggle"
        class="fixed top-4 right-4 z-50 bg-white p-2 rounded-lg shadow-md hover:bg-gray-50 transition-colors"
        aria-label="Toggle Fullscreen">
        <i data-lucide="maximize" class="h-6 w-6"></i>
    </button>
    <button
        id="boardModeToggle"
        onclick="toggleBoardMode()"
        class="fixed top-4 right-16 z-50 bg-white p-2 rounded-lg shadow-md hover:bg-gray-50 transition-colors"
        aria-label="Tavelläge (större knappar för pekskärm)"
        title="Tavelläge – större knappar för pekskärm/smartboard">
        <i data-lucide="hand" class="h-6 w-6"></i>
    </button>

    <!-- Overlay -->
    <div id="overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 overlay"></div>

    <!-- Off-Canvas Sidebar -->
<div id="sidebar" class="sidebar fixed left-0 top-0 h-full w-64 bg-gradient-to-b from-blue-50 to-white shadow-lg z-50 overflow-y-auto">
    <div class="h-full flex flex-col p-4">
        <!-- Header -->
        <div class="text-center mb-6 mt-8">
            <h2 class="text-xl font-bold text-blue-600"><?= htmlspecialchars($siteName) ?></h2>
        </div>
        
        <!-- Back to Home -->
        <a href="<?php echo isset($_SESSION['user_id']) ? '/dashboard.php' : '/'; ?>" 
           class="flex items-center space-x-3 mb-6 p-2 rounded-lg bg-blue-100 text-blue-700 hover:bg-blue-200 transition-colors">
            <i data-lucide="home" class="h-5 w-5"></i>
            <span class="font-medium">Mina whiteboards</span>
        </a>

        <!-- Widget Categories -->
        <div class="mb-3">
            <h3 class="font-semibold text-gray-700 mb-2 px-1 text-sm">WIDGETS</h3>
        </div>

        <!-- Widget Buttons - Keep same order and functionality -->
        <div class="grid grid-cols-2 gap-2 mb-4">
            <button onclick="addWidget('clock')" 
                    class="flex flex-col items-center justify-center p-2 rounded-lg bg-white shadow-sm hover:bg-blue-50 hover:text-blue-600 transition-colors">
                <i data-lucide="clock" class="h-5 w-5 mb-1 text-blue-500"></i>
                <span class="text-xs">Klocka</span>
            </button>

            <button onclick="addWidget('analog_clock')" 
                    class="flex flex-col items-center justify-center p-2 rounded-lg bg-white shadow-sm hover:bg-blue-50 hover:text-blue-600 transition-colors">
                <i data-lucide="clock-5" class="h-5 w-5 mb-1 text-blue-500"></i>
                <span class="text-xs">Analog klocka</span>
            </button>

            <button onclick="addWidget('timer')" 
                    class="flex flex-col items-center justify-center p-2 rounded-lg bg-white shadow-sm hover:bg-blue-50 hover:text-blue-600 transition-colors">
                <i data-lucide="timer" class="h-5 w-5 mb-1 text-blue-500"></i>
                <span class="text-xs">Timer</span>
            </button>

            <button onclick="addWidget('text')" 
                    class="flex flex-col items-center justify-center p-2 rounded-lg bg-white shadow-sm hover:bg-blue-50 hover:text-blue-600 transition-colors">
                <i data-lucide="type" class="h-5 w-5 mb-1 text-blue-500"></i>
                <span class="text-xs">Text</span>
            </button>

            <button onclick="addWidget('todo')" 
                    class="flex flex-col items-center justify-center p-2 rounded-lg bg-white shadow-sm hover:bg-blue-50 hover:text-blue-600 transition-colors">
                <i data-lucide="check-square" class="h-5 w-5 mb-1 text-blue-500"></i>
                <span class="text-xs">Att göra</span>
            </button>

            <button onclick="addWidget('groups')" 
                    class="flex flex-col items-center justify-center p-2 rounded-lg bg-white shadow-sm hover:bg-blue-50 hover:text-blue-600 transition-colors">
                <i data-lucide="users" class="h-5 w-5 mb-1 text-blue-500"></i>
                <span class="text-xs">Grupper</span>
            </button>
        </div>
        
        <div class="grid grid-cols-2 gap-2 mb-4">
            <button onclick="addWidget('qrcode')" 
                    class="flex flex-col items-center justify-center p-2 rounded-lg bg-white shadow-sm hover:bg-blue-50 hover:text-blue-600 transition-colors">
                <i data-lucide="qr-code" class="h-5 w-5 mb-1 text-blue-500"></i>
                <span class="text-xs">QR-kod</span>
            </button>

            <button onclick="addWidget('youtube')" 
                    class="flex flex-col items-center justify-center p-2 rounded-lg bg-white shadow-sm hover:bg-blue-50 hover:text-blue-600 transition-colors">
                <i data-lucide="video" class="h-5 w-5 mb-1 text-blue-500"></i>
                <span class="text-xs">YouTube</span>
            </button>

            <button onclick="addWidget('brainbreak')" 
                    class="flex flex-col items-center justify-center p-2 rounded-lg bg-white shadow-sm hover:bg-blue-50 hover:text-blue-600 transition-colors">
                <i data-lucide="brain" class="h-5 w-5 mb-1 text-blue-500"></i>
                <span class="text-xs">Brain Break</span>
            </button>

            <button onclick="addWidget('trafficlight')" 
                    class="flex flex-col items-center justify-center p-2 rounded-lg bg-white shadow-sm hover:bg-blue-50 hover:text-blue-600 transition-colors">
                <i data-lucide="ellipsis-vertical" class="h-5 w-5 mb-1 text-blue-500"></i>
                <span class="text-xs">Trafikljus</span>
            </button>

            <button onclick="addWidget('poll')" 
                    class="flex flex-col items-center justify-center p-2 rounded-lg bg-white shadow-sm hover:bg-blue-50 hover:text-blue-600 transition-colors">
                <i data-lucide="bar-chart" class="h-5 w-5 mb-1 text-blue-500"></i>
                <span class="text-xs">Omröstning</span>
            </button>

            <button onclick="addWidget('image')"
        class="flex flex-col items-center justify-center p-2 rounded-lg bg-white shadow-sm hover:bg-blue-50 hover:text-blue-600 transition-colors">
    <i data-lucide="image" class="h-5 w-5 mb-1 text-blue-500"></i>
    <span class="text-xs">Bild</span>
</button>

            <button onclick="addWidget('embed')"
                    class="flex flex-col items-center justify-center p-2 rounded-lg bg-white shadow-sm hover:bg-blue-50 hover:text-blue-600 transition-colors">
                <i data-lucide="presentation" class="h-5 w-5 mb-1 text-blue-500"></i>
                <span class="text-xs">Inbäddning</span>
            </button>

            <button onclick="addWidget('pdf')"
                    class="flex flex-col items-center justify-center p-2 rounded-lg bg-white shadow-sm hover:bg-blue-50 hover:text-blue-600 transition-colors">
                <i data-lucide="file-text" class="h-5 w-5 mb-1 text-blue-500"></i>
                <span class="text-xs">PDF</span>
            </button>

            <button onclick="addWidget('namewheel')"
                    class="flex flex-col items-center justify-center p-2 rounded-lg bg-white shadow-sm hover:bg-blue-50 hover:text-blue-600 transition-colors">
                <i data-lucide="shuffle" class="h-5 w-5 mb-1 text-blue-500"></i>
                <span class="text-xs">Namnsnurra</span>
            </button>

            <button onclick="addWidget('dice')"
                    class="flex flex-col items-center justify-center p-2 rounded-lg bg-white shadow-sm hover:bg-blue-50 hover:text-blue-600 transition-colors">
                <i data-lucide="dices" class="h-5 w-5 mb-1 text-blue-500"></i>
                <span class="text-xs">Tärning</span>
            </button>

            <button onclick="addWidget('stopwatch')"
                    class="flex flex-col items-center justify-center p-2 rounded-lg bg-white shadow-sm hover:bg-blue-50 hover:text-blue-600 transition-colors">
                <i data-lucide="watch" class="h-5 w-5 mb-1 text-blue-500"></i>
                <span class="text-xs">Stoppur</span>
            </button>

        </div>

        

        <!-- Divider -->
        <div class="my-4 border-t border-gray-200"></div>

        <!-- Settings - Keep same functionality -->
        <div class="space-y-2 mb-6">
            <button onclick="backgroundHandler.openModal()" 
                    class="flex items-center w-full p-2 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                <i data-lucide="image" class="h-5 w-5 text-gray-600 mr-3"></i>
                <span class="text-gray-700">Ändra bakgrund</span>
            </button>
            <button onclick="helpHandler.openModal()" 
                    class="flex items-center w-full p-2 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                <i data-lucide="help-circle" class="h-5 w-5 text-gray-600 mr-3"></i>
                <span class="text-gray-700">Hjälp</span>
            </button>
        </div>

        <!-- Bottom Actions - Keep same functionality -->
        <div class="mt-auto space-y-2">
        <?php
if (!isset($_SESSION['user_id'])) {
    // Beräkna borttagningsdatum (3 dagar efter skapande)
    $created_date = new DateTime($whiteboard['created_at']);
    $delete_date = clone $created_date;
    $delete_date->modify('+3 days');
    
    // Justera till nästa 06:00 efter 3-dagarsgränsen
    // Först sätter vi tiden till 06:00
    $delete_date->setTime(6, 0, 0);
    
    // Om vi redan har passerat 06:00 på 3-dagarsdatumet, lägg till en dag
    $now = new DateTime();
    if ($delete_date < $now) {
        $delete_date->modify('+1 day');
    }
    
    // Formatera datumet för visning
    $formatted_date = $delete_date->format('Y-m-d H:i');
    
    // Visa knappen med borttagningsdatum
    echo '
    <button 
        class="flex items-center w-full p-2 rounded-lg bg-red-50 hover:bg-red-100 transition-colors"
        disabled
    >
        <i data-lucide="trash-2" class="h-5 w-5 text-red-600 mr-3"></i>
        <span class="text-red-700">Raderas: ' . $formatted_date . '</span>
    </button>';
}
?>

            <button onclick="window.open('static/contact.php', '_blank')" 
        class="flex items-center w-full p-2 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
    <i data-lucide="message-square" class="h-5 w-5 text-gray-600 mr-3"></i>
    <span class="text-gray-700">Feedback</span>
</button>

            <button onclick="shareWhiteboard()" 
                    class="flex items-center w-full p-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition-colors">
                <i data-lucide="share-2" class="h-5 w-5 mr-3"></i>
                <span>Dela</span>
            </button>
        </div>
    </div>
</div>

    <!-- Whiteboard Area -->
    <div id="whiteboard" class="pt-16 min-h-screen relative">
    <?php foreach ($widgets as $widget): ?>
    <div class="widget" 
         id="widget-<?php echo $widget['id']; ?>"
         data-type="<?php echo htmlspecialchars($widget['type']); ?>"
         style="left: <?php echo $widget['position_x']; ?>px; 
                top: <?php echo $widget['position_y']; ?>px;
                width: <?php echo $widget['size_w'] ?? 200; ?>px;
                height: <?php echo $widget['size_h'] ?? 200; ?>px;">
        <div class="widget-container"></div>
        <div class="resize-handle"></div> <!-- Resize handle for interact.js -->
    </div>
    <?php endforeach; ?>
    </div>

    <!-- Share Modal -->
    <div id="shareModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center">
        <div class="bg-white p-6 rounded-lg max-w-md w-full">
            <h3 class="text-lg font-bold mb-4">Dela whiteboard</h3>
            <input type="text" 
                   id="shareUrl" 
                   value="<?php echo 'https://' . $_SERVER['HTTP_HOST'] . '/whiteboard.php?board=' . $boardCode; ?>" 
                   class="w-full p-2 border rounded mb-4"
                   readonly
                   onclick="this.select()">
            <div class="flex justify-end">
                <button onclick="closeShareModal()" 
                        class="px-4 py-2 bg-gray-500 text-white rounded hover:bg-gray-600">
                    Stäng
                </button>
            </div>
        </div>
    </div>
    
    <?php include 'includes/background-modal.php'; ?>
    <?php include 'help/help-modal.php'; ?>

    <script>
// Initialize Lucide icons
lucide.createIcons();

// Global variables for widget management
let lastWidgetX = 100;
let lastWidgetY = 100;
const timerIntervals = {};
const clockIntervals = {};
const timerMeta = {}; // { [id]: { startTime, endTime, totalSeconds } }

// Global variables for drag and resize state tracking
let draggedWidget = null;
let resizingWidget = null;

// Initialize Interact.js functionality
document.addEventListener('DOMContentLoaded', function() {
    // Initialize sidebar toggle
    setupSidebar();
    
    // Initialize fullscreen toggle
    setupFullscreen();
    
    // Initialize help handler
    helpHandler.init();
    
    // Initialize widgets that already exist
    initializeWidgets();
    
    // Setup interact.js for all widgets
    initializeInteractions();
});

// Setup sidebar functionality
function setupSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');
    const toggleButton = document.getElementById('sidebarToggle');
    let isOpen = false;

    function toggleSidebar() {
        isOpen = !isOpen;
        sidebar.classList.toggle('open');
        overlay.classList.toggle('active');
        
        // Update toggle icon
        const icon = toggleButton.querySelector('i');
        if (icon) {
            icon.setAttribute('data-lucide', isOpen ? 'x' : 'menu');
            lucide.createIcons();
        } else {
            toggleButton.innerHTML = `<i data-lucide="${isOpen ? 'x' : 'menu'}" class="h-6 w-6"></i>`;
            lucide.createIcons();
        }

        // Save state
        localStorage.setItem('sidebarOpen', isOpen);
    }

    // Event listeners
    if (toggleButton && overlay) {
        toggleButton.addEventListener('click', toggleSidebar);
        overlay.addEventListener('click', toggleSidebar);
    }

    // Close sidebar with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && isOpen) {
            toggleSidebar();
        }
    });
}

// Setup fullscreen functionality
function setupFullscreen() {
    const fullscreenToggle = document.getElementById('fullscreenToggle');
    let isFullscreen = false;

    function toggleFullscreen() {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen();
            fullscreenToggle.querySelector('i').setAttribute('data-lucide', 'minimize');
        } else {
            document.exitFullscreen();
            fullscreenToggle.querySelector('i').setAttribute('data-lucide', 'maximize');
        }
        lucide.createIcons();
    }

    fullscreenToggle.addEventListener('click', toggleFullscreen);

    // Update icon when fullscreen changes
    document.addEventListener('fullscreenchange', () => {
        isFullscreen = !!document.fullscreenElement;
        fullscreenToggle.querySelector('i').setAttribute('data-lucide', isFullscreen ? 'minimize' : 'maximize');
        lucide.createIcons();
    });
}

// Initialize help handler
const helpHandler = {
    modal: null,
    
    init() {
        this.modal = document.getElementById('helpModal');
        if (!this.modal) {
            console.error('Help modal element not found');
            return;
        }
    },
    
    openModal() {
        if (this.modal) {
            this.modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
    },
    
    closeModal() {
        if (this.modal) {
            this.modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }
    }
};

// Initialize widgets that already exist
function initializeWidgets() {
    document.querySelectorAll('.widget').forEach(widget => {
        // Set initial data attributes for position tracking
        const left = parseInt(widget.style.left) || 0;
        const top = parseInt(widget.style.top) || 0;
        widget.setAttribute('data-x', left);
        widget.setAttribute('data-y', top);
        
        // Initialize the widget's content
        const widgetId = widget.id.replace('widget-', '');
        const widgetType = widget.getAttribute('data-type');
        
        if (widgetId) {
            loadWidgetContent({
                id: widgetId,
                type: widgetType
            });
        }
        
        // Apply initial sizing variables
        updateWidgetScaling(widget);
   
        // VIKTIGT: Om det är en todo-widget, ställ upp en observer för att initialisera drag-listenern
        // varje gång innehållet uppdateras
        if (widgetType === 'todo') {
            // Skapa en observer för att detektera när widget-innehåll laddas
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                        // Kontrollera om det är todo-listelementet som har ändrats
                        const todoList = widget.querySelector('#todo-list-' + widgetId + ' ul');
                        if (todoList) {
                            console.log('Todo list updated, setting up drag listeners for widget', widgetId);
                            setupDragListeners(widgetId);
                        }
                    }
                });
            });
            
            // Observera ändringar i widget-containern
            const container = widget.querySelector('.widget-container');
            if (container) {
                observer.observe(container, { childList: true, subtree: true });
            }
        }
    });
}

// Load widget content from the server
function loadWidgetContent(widget) {
    fetch(`/api/widget-content.php?type=${widget.type}&id=${widget.id}`)
        .then(response => response.text())
        .then(content => {
            const widgetElement = document.getElementById(`widget-${widget.id}`);
            if (widgetElement) {
                const container = widgetElement.querySelector('.widget-container');
                if (container) {
                    container.innerHTML = content;
                }
                
                // Initialize specific widget types
                if (widget.type === 'clock') {
                    initClock(widget.id);
                } else if (widget.type === 'analog_clock') {
                    initAnalogClock(widget.id);
                } else if (widget.type === 'text') {
                    setTimeout(() => initTextWidget(widget.id), 50);
                }
                else if (widget.type === 'timer') {
                    // Initialize circular progress UI for timers
                    setTimeout(() => initTimerCircularUI(widget.id), 50);
                }
                else if (widget.type === 'pdf') {
                    setTimeout(() => initPdfWidget(widget.id), 50);
                }
                else if (widget.type === 'namewheel') {
                    setTimeout(() => initNameWheel(widget.id), 50);
                }
                else if (widget.type === 'dice') {
                    setTimeout(() => initDice(widget.id), 50);
                }

                // Update widget scaling after content is loaded
                setTimeout(() => updateWidgetScaling(widgetElement), 50);
            }
        })
        .catch(error => {
            console.error('Error loading widget content:', error);
        });
}

// Transform timer widget to circular UI and hide horizontal bar if present
function initTimerCircularUI(id) {
    const widget = document.getElementById(`widget-${id}`);
    if (!widget) return;
    const display = widget.querySelector('.timer-display');
    if (!display) return;

    // Hide existing horizontal progress bar if present
    const progressBar = document.getElementById(`timer-progress-${id}`);
    if (progressBar && progressBar.parentElement) {
        const barWrapper = progressBar.closest('.progress-bar-wrapper');
        if (barWrapper) {
            barWrapper.style.display = 'none';            // hide only the bar
        } else {
            // fallback: hide just the bar element
            progressBar.style.display = 'none';
        }
    }

    // Wrap the timer display with a circular container if not already wrapped
    if (!display.parentElement.classList.contains('circular-timer')) {
        const wrapper = document.createElement('div');
        wrapper.className = 'circular-timer';

        // Insert wrapper where display currently is
        const parent = display.parentElement;
        parent.insertBefore(wrapper, display);
        wrapper.appendChild(display);

        // Create SVG circles
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 100 100');

        const center = 50;
        const radius = 34; // increased breathing room so digits don't touch the ring
        const circumference = 2 * Math.PI * radius;

        const track = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        track.setAttribute('cx', center);
        track.setAttribute('cy', center);
        track.setAttribute('r', radius);
        track.setAttribute('class', 'circular-track');
        // stroke-width is now controlled by CSS

        const progress = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        progress.setAttribute('cx', center);
        progress.setAttribute('cy', center);
        progress.setAttribute('r', radius);
        progress.setAttribute('class', 'circular-progress');
        // stroke-width is now controlled by CSS
        progress.setAttribute('id', `timer-circle-${id}`);
        progress.style.strokeDasharray = `${circumference}`;
        progress.style.strokeDashoffset = `${circumference}`; // start empty

        svg.appendChild(track);
        svg.appendChild(progress);
        wrapper.appendChild(svg);

        // Store circumference for quick access
        progress.dataset.circumference = `${circumference}`;

        // Initial sizing and responsive updates
        applyCircularTimerSizing(id);
        const ro = new ResizeObserver(() => applyCircularTimerSizing(id));
        ro.observe(wrapper);
    }
}

// Utility to size/center circular timer digits responsively
function applyCircularTimerSizing(id) {
    const widget = document.getElementById(`widget-${id}`);
    if (!widget) return;
    const wrapper = widget.querySelector('.circular-timer');
    const display = widget.querySelector('.timer-display');
    if (!wrapper || !display) return;

    const diameter = wrapper.clientWidth || 0;
    // Expose diameter to CSS for calc()
    wrapper.style.setProperty('--ct-diameter', `${diameter}px`);

    // Also set inline font-size as a robust fallback
    const sizePx = Math.max(24, Math.min(120, Math.floor(diameter * 0.18)));
    widget.querySelectorAll('.timer-input, .timer-separator').forEach(el => {
        el.style.fontSize = `${sizePx}px`;
    });
}

// New - Initialize interactions using Interact.js for dragging and resizing
function initializeInteractions() {
    // Avregistrera eventuella tidigare interact-instanser för att undvika dubbletter
    interact('.widget').unset();
    // Prevent text selection during drag operations
    document.addEventListener('selectstart', function(e) {
        if (document.body.classList.contains('dragging') || document.body.classList.contains('resizing')) {
            e.preventDefault();
            return false;
        }
    });

    // Först, avregistrera alla tidigare interact-instanser
    document.querySelectorAll('.widget').forEach(widget => {
        setupWidgetInteractions(widget);
    });
    
    // Make widgets draggable
    interact('.widget')
        .draggable({
            // Only allow dragging from the header
            allowFrom: '.widget-header',
            inertia: false, // Stäng av inertia för mer exakt positionering
            modifiers: [
                // Keep the dragged position within the viewport
                interact.modifiers.restrictRect({
                    restriction: 'parent',
                    endOnly: true
                })
            ],
            autoScroll: true,
            onstart: function(event) {
                // Widget starts being dragged
                const widget = event.target;
                // Raise z-index temporarily during drag
                const maxZIndex = Math.max(...Array.from(document.querySelectorAll('.widget')).map(w => 
                    parseInt(window.getComputedStyle(w).zIndex) || 1
                ));
                widget.style.zIndex = maxZIndex + 1;
                widget.classList.add('active');
                widget.classList.add('dragging');
                document.body.classList.add('dragging');
                
                // Reset position tracking to avoid accumulated offset
                widget.setAttribute('data-x', parseInt(widget.style.left) || 0);
                widget.setAttribute('data-y', parseInt(widget.style.top) || 0);
                
                // Set a data attribute to track dragging state
                widget.setAttribute('data-dragging', 'true');
                
                // Disable text selection across the entire document during drag
                document.body.style.webkitUserSelect = 'none';
                document.body.style.userSelect = 'none';
            },
            onmove: function(event) {
                const widget = event.target;
                
                // Get current position from data attributes
                const x = (parseFloat(widget.getAttribute('data-x')) || 0) + event.dx;
                const y = (parseFloat(widget.getAttribute('data-y')) || 0) + event.dy;
                
                // Update widget position directly
                widget.style.left = `${x}px`;
                widget.style.top = `${y}px`;
                
                // Store the position as data attributes
                widget.setAttribute('data-x', x);
                widget.setAttribute('data-y', y);
            },
            onend: function(event) {
                // Widget drag ends
                const widget = event.target;
                widget.classList.remove('dragging');
                document.body.classList.remove('dragging');
                
                // Remove the dragging state attribute
                widget.removeAttribute('data-dragging');
                
                // Re-enable text selection
                document.body.style.webkitUserSelect = '';
                document.body.style.userSelect = '';
                
                // Update widget position in database
                const widgetId = widget.id.replace('widget-', '');
                const x = parseFloat(widget.getAttribute('data-x')) || 0;
                const y = parseFloat(widget.getAttribute('data-y')) || 0;
                
                updateWidgetPosition(widgetId, x, y);
            }
        })
        .resizable({
            // Allow resizing only from the bottom-right corner
            edges: { top: false, left: false, bottom: true, right: true },
            margin: 5,
            inertia: false, // Stäng av inertia för mer exakt storleksändring
            modifiers: [
                // Maintain minimum size
                interact.modifiers.restrictSize({
                    min: { width: 200, height: 200 }
                })
            ],
            onstart: function(event) {
                // Widget starts being resized
                const widget = event.target;
                widget.classList.add('resizing');
                resizingWidget = widget;
                
                // Disable text selection during resize
                document.body.classList.add('resizing');
                document.body.style.webkitUserSelect = 'none';
                document.body.style.userSelect = 'none';
            },
            onmove: function(event) {
                const widget = event.target;
                
                // Update the element's size
                let width = event.rect.width;
                let height = event.rect.height;
                
                // Apply the new size
                widget.style.width = `${width}px`;
                widget.style.height = `${height}px`;
                
                // Update CSS variables for responsive scaling
                widget.style.setProperty('--widget-width', `${width}px`);
                widget.style.setProperty('--widget-height', `${height}px`);
                
                // Update widget scaling during resize
                updateWidgetScaling(widget);
            },
            onend: function(event) {
                // Widget resize ends
                const widget = event.target;
                widget.classList.remove('resizing');
                document.body.classList.remove('resizing');
                
                // Re-enable text selection
                document.body.style.webkitUserSelect = '';
                document.body.style.userSelect = '';
                
                // Update widget size in database
                const widgetId = widget.id.replace('widget-', '');
                const width = parseFloat(widget.style.width);
                const height = parseFloat(widget.style.height);
                
                updateWidgetSize(widgetId, width, height);
                
                // Final update of widget scaling
                updateWidgetScaling(widget);
                
                // Handle special cases for text widgets
                const textId = widgetId;
                const editor = document.getElementById(`text-editor-${textId}`);
                if (editor && editor.classList.contains('hidden')) {
                    setTimeout(() => maximizeTextSize(textId), 100);
                }
                
                resizingWidget = null;
            }
        })
        .on('tap', function(event) {
            // Find the widget containing the tapped element
            const widget = event.target.closest('.widget');
            if (widget) {
                bringToFront(widget);
            }
        });
        
    // Add handler for preventing unwanted drag behavior
    document.addEventListener('dragstart', function(e) {
        // Allow dragging of elements with draggable attribute but prevent default browser drag
        // behavior for other elements during widget interaction
        if (document.body.classList.contains('dragging') || document.body.classList.contains('resizing')) {
            if (!e.target.getAttribute('draggable')) {
                e.preventDefault();
            }
        }
    });
}

// Funktion för att sätta upp drag och resize för en specifik widget
function setupWidgetInteractions(widget) {
    // Reset position tracking
    const left = parseInt(widget.style.left) || 0;
    const top = parseInt(widget.style.top) || 0;
    widget.setAttribute('data-x', left);
    widget.setAttribute('data-y', top);
}

// Funktion för att anropa när nya widgets läggs till
function reinitializeWidgetInteractions() {
    // Anropa initializeInteractions igen för att sätta upp interaktioner för nya widgets
    initializeInteractions();
    
    // Gå igenom alla widgets och sätt upp deras positionsspårning
    document.querySelectorAll('.widget').forEach(widget => {
        setupWidgetInteractions(widget);
    });
}

// Add this right here - the click event listener for bringing widgets to front
document.addEventListener('click', function(e) {
        // If we're not dragging or resizing
        if (!document.body.classList.contains('dragging') && 
            !document.body.classList.contains('resizing')) {
            
            // Find if a widget was clicked
            const widget = e.target.closest('.widget');
            
            if (widget) {
                // Only bring to front if the click wasn't on an interactive element
                const isInteractive = e.target.tagName.toLowerCase() === 'button' || 
                                    e.target.tagName.toLowerCase() === 'input' ||
                                    e.target.tagName.toLowerCase() === 'select' ||
                                    e.target.tagName.toLowerCase() === 'textarea' ||
                                    e.target.tagName.toLowerCase() === 'a';
                
                if (!isInteractive) {
                    bringToFront(widget);
                }
            }
        }
    });

    function setupFullscreenProtection() {
    // Store fullscreen state in an application-level variable
    window.isAppFullscreen = false;
    // Lägg till flagga för avsiktlig avslutning av fullskärm
    window.intentionalExitFullscreen = false;
    
    // Track when fullscreen changes
    document.addEventListener('fullscreenchange', () => {
        // Om fullskärmsläget avslutas av användaren via knappen, gör ingenting
        if (window.intentionalExitFullscreen) {
            window.intentionalExitFullscreen = false;
            window.isAppFullscreen = false;
            return;
        }
        
        // Endast försök återställa fullskärm om det avslutas oavsiktligt OCH modaldialog inte är aktiv
        if (window.isAppFullscreen && !document.fullscreenElement && !window.modalDialogActive) {
            // Small delay to let any pending DOM operations complete
            setTimeout(() => {
                document.documentElement.requestFullscreen().catch(err => {
                    console.log('Could not restore fullscreen:', err);
                    // If we can't restore, update our internal state
                    window.isAppFullscreen = false;
                });
            }, 100);
        } else {
            // Update our fullscreen tracking state
            window.isAppFullscreen = !!document.fullscreenElement;
        }
    });
    
    // Update the fullscreen toggle function
    const fullscreenToggle = document.getElementById('fullscreenToggle');
    if (fullscreenToggle) {
        // Ersätt hela hanteraren för att säkerställa konsekvent beteende
        fullscreenToggle.onclick = function() {
            if (!document.fullscreenElement) {
                // Gå in i fullskärmsläge
                document.documentElement.requestFullscreen().then(() => {
                    fullscreenToggle.querySelector('i').setAttribute('data-lucide', 'minimize');
                    lucide.createIcons();
                    window.isAppFullscreen = true;
                }).catch(err => {
                    console.log('Could not enter fullscreen:', err);
                });
            } else {
                // Lämna fullskärmsläge - markera att det är avsiktligt
                window.intentionalExitFullscreen = true;
                window.isAppFullscreen = false;
                
                document.exitFullscreen().then(() => {
                    fullscreenToggle.querySelector('i').setAttribute('data-lucide', 'maximize');
                    lucide.createIcons();
                }).catch(err => {
                    console.log('Error exiting fullscreen:', err);
                    window.intentionalExitFullscreen = false;
                });
            }
        };
    }
    
    // Lägg även till lyssning på Escape-tangenten för att hantera system-exitfullscreen
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && window.isAppFullscreen) {
            // Om Escape trycks när vi är i fullskärmsläge, markera det som avsiktligt
            window.intentionalExitFullscreen = true;
            // Uppdatera ikonen efter en kort fördröjning
            setTimeout(() => {
                if (!document.fullscreenElement && fullscreenToggle) {
                    fullscreenToggle.querySelector('i').setAttribute('data-lucide', 'maximize');
                    lucide.createIcons();
                }
            }, 100);
        }
    });
}

// Call this from your document ready function
document.addEventListener('DOMContentLoaded', function() {
    // Other initialization code...
    setupFullscreenProtection();
});

// Create a widget element
function createWidgetElement(widget) {
    const div = document.createElement('div');
    div.id = `widget-${widget.id}`;
    div.className = 'widget';
    div.setAttribute('data-type', widget.type);
    div.style.left = `${widget.position_x}px`;
    div.style.top = `${widget.position_y}px`;
    
    // Set initial size
    const initialWidth = widget.size_w || 200;
    const initialHeight = widget.size_h || 200;
    div.style.width = `${initialWidth}px`;
    div.style.height = `${initialHeight}px`;
    
    // Initialize data attributes for interact.js
    div.setAttribute('data-x', widget.position_x);
    div.setAttribute('data-y', widget.position_y);
    
    // Set CSS variables for responsive sizing
    div.style.setProperty('--widget-width', `${initialWidth}px`);
    div.style.setProperty('--widget-height', `${initialHeight}px`);
    
    // Create container for widget content
    const contentContainer = document.createElement('div');
    contentContainer.className = 'widget-container';
    div.appendChild(contentContainer);

    // Add resize handle
    const resizeHandle = document.createElement('div');
    resizeHandle.className = 'resize-handle';
    div.appendChild(resizeHandle);

    return div;
}

// Add a new widget
function addWidget(type) {
    lastWidgetX += 30;
    lastWidgetY += 30;
    
    if (lastWidgetX > window.innerWidth - 300) lastWidgetX = 100;
    if (lastWidgetY > window.innerHeight - 300) lastWidgetY = 100;

    fetch('/api/widgets.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            type: type,
            whiteboard_id: whiteboardId,
            position_x: lastWidgetX,
            position_y: lastWidgetY
        })
    })
    .then(response => response.json())
    .then(widget => {
        const widgetElement = createWidgetElement(widget);
        document.getElementById('whiteboard').appendChild(widgetElement);
        
        // Refresh interactions when a new widget is added
        // This ensures the new widget gets proper interact.js behavior
        initializeInteractions();
        
        // Load the widget content
        setTimeout(() => {
            loadWidgetContent(widget);
        }, 50);
    });
}

// Update widget scaling based on size
function updateWidgetScaling(widget) {
    if (!widget) return;

    const width = widget.offsetWidth;
    const height = widget.offsetHeight;
    
    // Update CSS variables for dynamic scaling
    widget.style.setProperty('--widget-width', `${width}px`);
    widget.style.setProperty('--widget-height', `${height}px`);

    // Apply size classes for responsive design
    widget.classList.remove('small', 'medium', 'large');
    if (width < 250 || height < 250) {
        widget.classList.add('small');
    } else if (width > 400 || height > 400) {
        widget.classList.add('large');
    } else {
        widget.classList.add('medium');
    }

    // Keep circular timer text sized/centered when widget changes
    if (widget.getAttribute('data-type') === 'timer') {
        const wid = widget.id.replace('widget-', '');
        applyCircularTimerSizing(wid);
    }
}

// Bring a widget to the front
function bringToFront(widget) {
    // Find the highest z-index currently in use
    const widgets = document.querySelectorAll('.widget');
    let maxZ = 0;
    
    widgets.forEach(w => {
        const zIndex = parseInt(window.getComputedStyle(w).zIndex) || 0;
        maxZ = Math.max(maxZ, zIndex);
    });
    
    // Set the widget's z-index to highest + 1
    widget.style.zIndex = maxZ + 1;
    
    // Add active class for visual feedback
    widgets.forEach(w => w.classList.remove('active'));
    widget.classList.add('active');
    
    // Log for debugging
    console.log(`Bringing widget to front: ${widget.id}, setting z-index: ${maxZ + 1}`);
}

// Update widget position in database
function updateWidgetPosition(widgetId, x, y) {
    fetch('/api/update-widget-position.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            widget_id: widgetId,
            position_x: Math.round(x),
            position_y: Math.round(y)
        })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            console.error('Error updating widget position:', data.error);
        }
    })
    .catch(error => {
        console.error('Error updating widget position:', error);
    });
}

// Update widget size in database
function updateWidgetSize(widgetId, width, height) {
    fetch('/api/update-widget-size.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            widget_id: widgetId,
            size_w: Math.round(width),
            size_h: Math.round(height)
        })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            console.error('Error updating widget size:', data.error);
        }
    })
    .catch(error => {
        console.error('Error updating widget size:', error);
    });
}

// Widget settings functions
function updateWidgetSettings(id, settings) {
    fetch('/api/update-widget-settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            widget_id: id,
            settings: settings
        })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            console.error('Error updating widget settings:', data.error);
        }
    })
    .catch(error => {
        console.error('Error updating widget settings:', error);
    });
}

function getWidgetSettings(id, callback) {
    fetch(`/api/get-widget-settings.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            callback(data.settings || {});
        })
        .catch(error => {
            console.error('Error fetching widget settings:', error);
            callback({});
        });
}

// Custom confirm dialog that won't disrupt fullscreen
function showConfirmDialog(message, onConfirm, onCancel) {
    // Create the modal element if it doesn't exist yet
    let modal = document.getElementById('custom-confirm-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'custom-confirm-modal';
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[9999]';
        document.body.appendChild(modal);
    }
    
    // Set the content of the modal
    modal.innerHTML = `
        <div class="bg-white p-6 rounded-lg max-w-md w-full shadow-xl">
            <h3 class="text-lg font-bold mb-4">Bekräfta</h3>
            <p class="mb-6">${message}</p>
            <div class="flex justify-end space-x-4">
                <button id="confirm-cancel-btn" class="px-4 py-2 bg-gray-400 text-white rounded hover:bg-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400">
                    Avbryt
                </button>
                <button id="confirm-ok-btn" class="px-4 py-2 bg-red-500 text-white rounded hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-red-500">
                    Ta bort
                </button>
            </div>
        </div>
    `;
    
    // Show the modal
    modal.style.display = 'flex';
    
    // Set up button event handlers
    document.getElementById('confirm-cancel-btn').onclick = function() {
        modal.style.display = 'none';
        if (onCancel) onCancel();
    };
    
    document.getElementById('confirm-ok-btn').onclick = function() {
        modal.style.display = 'none';
        if (onConfirm) onConfirm();
    };
    
    // Add escape key handler
    const escHandler = function(e) {
        if (e.key === 'Escape') {
            modal.style.display = 'none';
            document.removeEventListener('keydown', escHandler);
            if (onCancel) onCancel();
        }
    };
    document.addEventListener('keydown', escHandler);
    
    // Focus the cancel button for better keyboard accessibility
    document.getElementById('confirm-cancel-btn').focus();
    
    return modal;
}

// Improved removeWidget function with custom confirmation
function removeWidget(widgetId) {
    showConfirmDialog('Är du säker på att du vill ta bort denna widget?', 
        // On confirm
        function() {
            fetch('/api/remove-widget.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    widget_id: widgetId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const widget = document.getElementById('widget-' + widgetId);
                    if (widget) {
                        // Clear any intervals associated with the widget
                        if (clockIntervals[widgetId]) {
                            clearInterval(clockIntervals[widgetId]);
                            delete clockIntervals[widgetId];
                        }
                        if (timerIntervals[widgetId]) {
                            clearInterval(timerIntervals[widgetId]);
                            delete timerIntervals[widgetId];
                        }
                        
                        // Remove the widget from DOM
                        widget.remove();
                    }
                } else {
                    console.error('Error removing widget:', data.error);
                }
            })
            .catch(error => {
                console.error('Error removing widget:', error);
            });
        },
        // On cancel - do nothing
        function() {}
    );
}

// Clock widget functions
function initClock(id) {
    if (clockIntervals[id]) {
        clearInterval(clockIntervals[id]);
    }
    
    function updateClock() {
        const clockElement = document.getElementById(`clock-${id}`);
        if (clockElement) {
            const now = new Date();
            clockElement.textContent = now.toLocaleTimeString('sv-SE', { hour12: false });
        } else {
            clearInterval(clockIntervals[id]);
            delete clockIntervals[id];
        }
    }
    
    updateClock();
    clockIntervals[id] = setInterval(updateClock, 1000);
}

// Analog clock functions
function initAnalogClock(id) {
    if (clockIntervals[id]) {
        clearInterval(clockIntervals[id]);
    }
    
    // Create the clock face with only numbers
    createClockFace(id);
    
    // Ensure clock is a perfect circle
    ensureClockCircular(id);
    
    // Configure ResizeObserver for adjusting the clock when widget size changes
    const container = document.getElementById(`analog-clock-container-${id}`);
    if (container) {
        const resizeObserver = new ResizeObserver(() => {
            ensureClockCircular(id);
        });
        resizeObserver.observe(container);
    }
    
    function updateAnalogClock() {
        const hourHand = document.getElementById(`hour-hand-${id}`);
        const minuteHand = document.getElementById(`minute-hand-${id}`);
        const secondHand = document.getElementById(`second-hand-${id}`);
        
        if (!hourHand || !minuteHand || !secondHand) {
            clearInterval(clockIntervals[id]);
            delete clockIntervals[id];
            return;
        }
        
        const now = new Date();
        const hours = now.getHours() % 12;
        const minutes = now.getMinutes();
        const seconds = now.getSeconds();
        
        // Calculate degrees for each hand
        const hourDeg = (hours * 30) + (minutes * 0.5); // 30 degrees per hour + adjustment for minutes
        const minuteDeg = minutes * 6; // 6 degrees per minute
        const secondDeg = seconds * 6; // 6 degrees per second
        
        // Apply rotations
        hourHand.style.transform = `translateX(-50%) rotate(${hourDeg}deg)`;
        minuteHand.style.transform = `translateX(-50%) rotate(${minuteDeg}deg)`;
        secondHand.style.transform = `translateX(-50%) rotate(${secondDeg}deg)`;
    }
    
    updateAnalogClock(); // Update immediately
    clockIntervals[id] = setInterval(updateAnalogClock, 1000); // Update every second
}

// Function to create clock face dynamically
function createClockFace(id) {
    const hourMarksContainer = document.getElementById(`hour-marks-${id}`);
    if (!hourMarksContainer) return;
    
    hourMarksContainer.innerHTML = ""; // Clear existing marks
    
    // Create only numbers, not hour marks
    for (let i = 1; i <= 12; i++) {
        const angle = (i * 30) - 90; // 30 degrees per hour, -90 to start at 12 o'clock
        const radians = angle * (Math.PI / 180);
        
        // Create only the hour number
        const numberEl = document.createElement('div');
        numberEl.className = 'hour-number';
        numberEl.textContent = i;
        
        // Position number with percentage from center
        const radius = 40; // 40% of the clock
        const x = 50 + radius * Math.cos(radians);
        const y = 50 + radius * Math.sin(radians);
        numberEl.style.left = `${x}%`;
        numberEl.style.top = `${y}%`;
        
        hourMarksContainer.appendChild(numberEl);
    }
}

// Function to ensure clock is a perfect circle
function ensureClockCircular(id) {
    const container = document.getElementById(`analog-clock-container-${id}`);
    if (!container) return;
    
    const clock = container.querySelector('.analog-clock');
    if (!clock) return;
    
    // Calculate container dimensions
    const containerWidth = container.offsetWidth;
    const containerHeight = container.offsetHeight;
    
    // Use the smaller of width and height to determine clock size
    const size = Math.min(containerWidth, containerHeight);
    
    // Apply size and center the clock
    clock.style.width = `${size}px`;
    clock.style.height = `${size}px`;
    clock.style.margin = 'auto';
}

// Timer functions

// NY, SEPARAT UPPDATERINGSFUNKTION
function updateDisplay(id) {
    const minutesInput = document.getElementById(`timer-minutes-${id}`);
    const secondsInput = document.getElementById(`timer-seconds-${id}`);
    const progressBar = document.getElementById(`timer-progress-${id}`);
    const circle = document.getElementById(`timer-circle-${id}`);
    const button = document.getElementById(`timer-toggle-${id}`);

    if (!timerMeta[id] || !timerMeta[id].endTime) {
        return; // Avsluta om timern har raderats
    }

    const now = Date.now();
    const remainingMs = Math.max(0, timerMeta[id].endTime - now);
    const remainingFloat = remainingMs / 1000;
    const remainingInt = Math.ceil(remainingFloat); // Använd Math.ceil för visning

    // Uppdatera siffror
    minutesInput.value = Math.floor(remainingInt / 60).toString().padStart(2, '0');
    secondsInput.value = (remainingInt % 60).toString().padStart(2, '0');

    // Beräkna förlopp baserat på den *ursprungliga* totala tiden
    const originalTotal = timerMeta[id].originalTotalSeconds;
    const elapsedSeconds = originalTotal - remainingFloat;
    const progressPercentage = (elapsedSeconds / originalTotal) * 100;


    // Linjär bar
    if (progressBar) {
        progressBar.style.width = `${progressPercentage}%`;
    }

    // Cirkel
    if (circle && originalTotal > 0) {
        const C2 = ensureCircleCircumference(circle);
        // Offset baseras på återstående tid i förhållande till originaltiden
        const offset = C2 * (1 - (elapsedSeconds / originalTotal));
        circle.style.strokeDashoffset = `${offset}`;
    }

    // När timern är klar
    if (remainingMs === 0) {
        clearInterval(timerIntervals[id]);
        delete timerIntervals[id];
        delete timerMeta[id];

        button.textContent = 'Starta';
        button.classList.remove('bg-red-500', 'hover:bg-red-600');
        button.classList.add('bg-blue-500', 'hover:bg-blue-600');
        minutesInput.disabled = false;
        secondsInput.disabled = false;

        if (progressBar) progressBar.style.width = '100%';
        if (circle) circle.style.strokeDashoffset = '0';

        const audio = document.getElementById('timer-sound');
        if (audio) { 
            audio.currentTime = 0; 
            audio.play().catch(() => {}); 
        }
        if (typeof showTimerEndNotification === 'function') {
            showTimerEndNotification(id);
        }
    }
}


// NY, KORRIGERAD STARTTIMER-FUNKTION
function startTimer(id) {
    const button = document.getElementById(`timer-toggle-${id}`);
    const minutesInput = document.getElementById(`timer-minutes-${id}`);
    const secondsInput = document.getElementById(`timer-seconds-${id}`);

    const isRunning = timerIntervals[id];

    if (isRunning) {
        // --- PAUSA LOGIK ---
        clearInterval(timerIntervals[id]);
        delete timerIntervals[id];

        // Spara återstående tid vid paus
        if (timerMeta[id]) {
            const remainingMs = timerMeta[id].endTime - Date.now();
            timerMeta[id].remainingOnPause = remainingMs > 0 ? remainingMs : 0;
        }

        button.textContent = 'Fortsätt';
        button.classList.remove('bg-red-500', 'hover:bg-red-600');
        button.classList.add('bg-blue-500', 'hover:bg-blue-600');

    } else {
        // --- STARTA ELLER ÅTERUPPTA LOGIK ---
        let durationMs;

        if (timerMeta[id] && typeof timerMeta[id].remainingOnPause !== 'undefined') {
            // --- ÅTERUPPTA ---
            durationMs = timerMeta[id].remainingOnPause;
            delete timerMeta[id].remainingOnPause; // Rensa paus-tillstånd
        } else {
            // --- STARTA NY TIMER ---
            const minutes = parseInt(minutesInput.value, 10) || 0;
            const seconds = parseInt(secondsInput.value, 10) || 0;
            const totalSeconds = (minutes * 60) + seconds;

            if (totalSeconds <= 0) return; // Gör inget om tiden är noll

            durationMs = totalSeconds * 1000;
            // Spara den ursprungliga totala tiden för progress-beräkningen
            timerMeta[id] = { originalTotalSeconds: totalSeconds };

            // Nollställ cirkeln visuellt innan start
            const circle = document.getElementById(`timer-circle-${id}`);
            if (circle) {
                const C = ensureCircleCircumference(circle);
                circle.style.strokeDasharray = `${C}`;
                circle.style.strokeDashoffset = `${C}`;
            }
        }

        const endTime = Date.now() + durationMs;
        timerMeta[id].endTime = endTime;

        // Uppdatera UI till "kör"-läge
        button.textContent = 'Pausa';
        button.classList.remove('bg-blue-500', 'hover:bg-blue-600');
        button.classList.add('bg-red-500', 'hover:bg-red-600');
        minutesInput.disabled = true;
        secondsInput.disabled = true;

        // Starta intervallet
        timerIntervals[id] = setInterval(() => updateDisplay(id), 100);
        updateDisplay(id); // Kör en gång direkt för omedelbar respons
    }
}


function ensureCircleCircumference(circle) {
    let C = parseFloat(circle.dataset.circumference || '0');
    if (!C) {
        const r = parseFloat(circle.getAttribute('r') || '0');
        C = 2 * Math.PI * r;
        circle.dataset.circumference = `${C}`;
    }
    return C;
}

function resetTimer(id) {
    const button = document.getElementById(`timer-toggle-${id}`);
    const minutesInput = document.getElementById(`timer-minutes-${id}`);
    const secondsInput = document.getElementById(`timer-seconds-${id}`);
    const progressBar = document.getElementById(`timer-progress-${id}`);
    const circle = document.getElementById(`timer-circle-${id}`);

    if (timerIntervals[id]) {
        clearInterval(timerIntervals[id]);
        delete timerIntervals[id];
    }
    delete timerMeta[id];

    if (button) {
        button.textContent = 'Starta';
        button.classList.remove('bg-red-500', 'hover:bg-red-600');
        button.classList.add('bg-blue-500', 'hover:bg-blue-600');
    }
    if (minutesInput) {
        minutesInput.disabled = false;
        minutesInput.value = (parseInt(minutesInput.getAttribute('value') || '5', 10) || 0).toString().padStart(2, '0');
    }
    if (secondsInput) {
        secondsInput.disabled = false;
        secondsInput.value = (parseInt(secondsInput.getAttribute('value') || '0', 10) || 0).toString().padStart(2, '0');
    }
    if (progressBar) progressBar.style.width = '0%';
    if (circle) {
        const C = ensureCircleCircumference(circle);
        circle.style.strokeDasharray = `${C}`;
        circle.style.strokeDashoffset = `${C}`;
    }
}

function handleTimeInput(event, input, type) {
    if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
        event.preventDefault();
        
        const currentValue = parseInt(input.value) || 0;
        const change = event.key === 'ArrowUp' ? 1 : -1;
        let newValue;
        
        if (type === 'minutes') {
            newValue = Math.min(99, Math.max(0, currentValue + change));
            input.value = newValue.toString().padStart(2, '0');
        } else {
            newValue = Math.min(59, Math.max(0, currentValue + change));
            input.value = newValue.toString().padStart(2, '0');
        }
        
        const id = input.id.split('-')[2];
        saveTimerSettings(id);
    }
    
    if (!/[0-9\b]/.test(event.key) && 
        !['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab'].includes(event.key)) {
        event.preventDefault();
    }
}

function saveTimerSettings(id) {
    const minutesInput = document.getElementById(`timer-minutes-${id}`);
    const secondsInput = document.getElementById(`timer-seconds-${id}`);
    
    let minutes = parseInt(minutesInput.value, 10);
    let seconds = parseInt(secondsInput.value, 10);
    
    if (isNaN(minutes)) minutes = 0;
    if (isNaN(seconds)) seconds = 0;
    
    minutes = Math.min(99, Math.max(0, minutes));
    seconds = Math.min(59, Math.max(0, seconds));
    
    const minutesWasEmpty = minutesInput.value === "";
    const secondsWasEmpty = secondsInput.value === "";
    if (minutesWasEmpty && secondsWasEmpty) {
        minutes = 5;
        seconds = 0;
    }
    
    minutesInput.value = minutes.toString().padStart(2, '0');
    secondsInput.value = seconds.toString().padStart(2, '0');
    
    updateWidgetSettings(id, { 
        minutes: minutes,
        seconds: seconds
    });
}

function showTimerEndNotification(id) {
    // Create the modal element if it doesn't exist yet
    let modal = document.getElementById('custom-confirm-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'custom-confirm-modal';
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[9999]';
        document.body.appendChild(modal);
    }
    
    // Sätt vår flagga för att förhindra fullscreen exit
    window.modalDialogActive = true;
    
    // Set the content of the modal with timer-specific styling
    modal.innerHTML = `
        <div class="bg-white p-6 rounded-lg max-w-md w-full shadow-xl">
            <h3 class="text-lg font-bold mb-3 text-center">Tiden är ute!</h3>
            <div class="mb-6 text-center">
                <svg class="w-16 h-16 text-red-500 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                          d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p class="text-gray-700">Timern har räknat ned till noll.</p>
            </div>
            <div class="flex justify-center">
                <button id="timer-ok-btn" class="px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    OK
                </button>
            </div>
        </div>
    `;
    
    // Show the modal
    modal.style.display = 'flex';
    
    // Försök spela upp ljudet (om vi har implementerat ljudfunktionen)
    if (typeof playTimerSound === 'function') {
        playTimerSound();
    }
    
    // Reset the timer visually if needed
    const progressBar = document.getElementById(`timer-progress-${id}`);
    if (progressBar) {
        progressBar.style.width = '0%';
    }
    
    // Ladda konfetti-skriptet om det inte redan är laddat
    let confettiLoaded = false;
    
    function startConfetti() {
        if (window.TimerConfetti) {
            window.TimerConfetti.start();
            confettiLoaded = true;
        }
    }
    
    function loadConfetti() {
        // Om objektet redan finns, starta konfetti direkt
        if (window.TimerConfetti) {
            startConfetti();
            return;
        }
        
        // Annars, ladda in skriptet
        const script = document.createElement('script');
        script.src = '/assets/js/timer-confetti.js';
        script.onload = function() {
            startConfetti();
        };
        script.onerror = function() {
            console.error('Kunde inte ladda konfetti-skriptet');
        };
        document.head.appendChild(script);
    }
    
    // Ladda och starta konfetti
    loadConfetti();
    
    // Funktion för att städa upp allt när modalen stängs
    function cleanupAll() {
        modal.style.display = 'none';
        window.modalDialogActive = false;
        
        // Stoppa konfetti om det laddats
        if (confettiLoaded && window.TimerConfetti) {
            window.TimerConfetti.stop();
        }
    }
    
    // Fix för att säkerställa att klick fungerar på modalen
    // Använd ett helt nytt OK-knappelement för att garantera att händelselyssnare fungerar
    const okButton = document.getElementById('timer-ok-btn');
    if (okButton) {
        const newOkButton = okButton.cloneNode(true);
        okButton.parentNode.replaceChild(newOkButton, okButton);
        newOkButton.addEventListener('click', cleanupAll);
    }
    
    // Klick utanför modalen för att stänga den
    function handleOutsideClick(event) {
        if (event.target === modal) {
            cleanupAll();
            modal.removeEventListener('click', handleOutsideClick);
        }
    }
    
    // Ta bort och återanslut för att undvika duplicering
    modal.removeEventListener('click', handleOutsideClick);
    modal.addEventListener('click', handleOutsideClick);
    
    // Escape-tangent för att stänga
    function escHandler(e) {
        if (e.key === 'Escape') {
            cleanupAll();
            document.removeEventListener('keydown', escHandler);
        }
    }
    
    // Ta bort och återanslut för att undvika duplicering
    document.removeEventListener('keydown', escHandler);
    document.addEventListener('keydown', escHandler);
    
    return modal;
}

function playTimerSound() {
    try {
        const sound = document.getElementById('timer-sound');
        if (sound) {
            // Återställ ljudet till början om det redan spelats
            sound.currentTime = 0;
            
            // Spela upp ljudet
            sound.play().catch(error => {
                // Moderna webbläsare kräver användarinteraktion innan ljud kan spelas
                console.log('Kunde inte spela ljud automatiskt:', error);
            });
        }
    } catch (error) {
        console.error('Fel vid uppspelning av ljud:', error);
    }
}

// Text widget functions
function initTextWidget(id) {
    const container = document.getElementById(`text-container-${id}`);
    const displayWrapper = document.getElementById(`text-display-wrapper-${id}`);
    
    if (!container || !displayWrapper) return;
    
    // Optimize text size on load
    setTimeout(() => {
        maximizeTextSize(id);
    }, 50);
    
    // Add click event to activate editing
    displayWrapper.addEventListener('click', () => {
        toggleTextEdit(id);
    });
    
    // Set up resize observer
    setupTextResizeObserver(id);
}

function toggleTextEdit(id) {
    const displayWrapper = document.getElementById(`text-display-wrapper-${id}`);
    const editor = document.getElementById(`text-editor-${id}`);
    
    if (!displayWrapper || !editor) return;
    
    const isEditing = !editor.classList.contains('hidden');
    
    if (isEditing) {
        // End editing
        finishEditing(id);
    } else {
        // Start editing
        displayWrapper.classList.add('hidden');
        editor.classList.remove('hidden');
        editor.focus();
        
        // Place cursor at the end of the text
        editor.selectionStart = editor.value.length;
        editor.selectionEnd = editor.value.length;
    }
}

function updateTextPreview(id) {
    const displayElement = document.getElementById(`text-display-${id}`);
    const editor = document.getElementById(`text-editor-${id}`);
    
    if (!displayElement || !editor) return;
    
    // Update content in display element
    displayElement.innerHTML = editor.value.replace(/\n/g, '<br>');
}

function finishEditing(id) {
    const displayWrapper = document.getElementById(`text-display-wrapper-${id}`);
    const editor = document.getElementById(`text-editor-${id}`);
    
    if (!displayWrapper || !editor) return;
    
    // Get the new content
    const newContent = editor.value;
    
    // Hide editor and show display
    editor.classList.add('hidden');
    displayWrapper.classList.remove('hidden');
    
    // Update display with new content
    const displayElement = document.getElementById(`text-display-${id}`);
    if (displayElement) {
        displayElement.innerHTML = newContent.replace(/\n/g, '<br>');
    }
    
    // Save to database
    updateWidgetSettings(id, { content: newContent });
    
    // Optimize text size with new content
    setTimeout(() => {
        maximizeTextSize(id);
    }, 50);
}

function maximizeTextSize(id) {
    const displayElement = document.getElementById(`text-display-${id}`);
    const container = document.getElementById(`text-container-${id}`);
    
    if (!displayElement || !container) return;
    
    // Measure available space
    const containerWidth = container.offsetWidth - 4; // 2px padding on each side
    const containerHeight = container.offsetHeight - 4; // 2px padding top and bottom
    
    // If container is too small, abort
    if (containerWidth < 10 || containerHeight < 10) return;
    
    // Get text and check if empty
    const text = displayElement.innerText || '';
    if (!text.trim()) return;
    
    // Create or reuse a shadow div for measurement
    let measurer = document.getElementById(`text-measurer-${id}`);
    if (!measurer) {
        measurer = document.createElement('div');
        measurer.id = `text-measurer-${id}`;
        measurer.style.position = 'absolute';
        measurer.style.left = '-9999px';
        measurer.style.visibility = 'hidden';
        measurer.style.width = containerWidth + 'px';
        measurer.style.whiteSpace = 'pre-wrap';
        measurer.style.wordWrap = 'break-word';
        measurer.style.overflow = 'hidden';
        measurer.style.textAlign = 'center';
        measurer.style.lineHeight = '1.2';
        measurer.style.fontWeight = '500';
        measurer.style.display = 'flex';
        measurer.style.alignItems = 'center';
        measurer.style.justifyContent = 'center';
        measurer.innerHTML = text;
        document.body.appendChild(measurer);
    } else {
        measurer.style.width = containerWidth + 'px';
        measurer.innerHTML = text;
    }
    
    // Implement binary search for optimal text size
    let minSize = 8;  // Minimum readable size
    let maxSize = 500; // Start with a very large size
    let bestSize = 16; // Default size if nothing else fits
    
    // Maximum binary search: 12 iterations is enough for precision to 0.1px
    for (let i = 0; i < 12; i++) {
        const mid = (minSize + maxSize) / 2;
        measurer.style.fontSize = mid + 'px';
        
        // Check if text fits within container
        if (measurer.scrollHeight <= containerHeight && measurer.scrollWidth <= containerWidth) {
            // Text fits, try larger size
            bestSize = mid;
            minSize = mid + 0.1;
        } else {
            // Text too large, try smaller size
            maxSize = mid - 0.1;
        }
    }
    
    // Apply final text size with a small safety margin
    if (!displayElement.dataset.fontSize || 
        Math.abs(parseFloat(displayElement.dataset.fontSize) - (bestSize - 0.5)) > 0.5) {
        displayElement.style.fontSize = (bestSize - 0.5) + 'px';
        displayElement.dataset.fontSize = (bestSize - 0.5);
    }
    
    // Remove measurement element to reduce DOM size
    if (measurer && measurer.parentNode) {
        measurer.parentNode.removeChild(measurer);
    }
}

function setupTextResizeObserver(id) {
    const container = document.getElementById(`text-container-${id}`);
    if (!container) return;
    
    // Create data attribute to track dimensions
    container.dataset.lastWidth = container.offsetWidth;
    container.dataset.lastHeight = container.offsetHeight;
    
    // Observer for size changes
    const resizeObserver = new ResizeObserver((entries) => {
        for (const entry of entries) {
            const target = entry.target;
            
            // Check if widget has actually changed size
            const newWidth = Math.round(entry.contentRect.width);
            const newHeight = Math.round(entry.contentRect.height);
            const oldWidth = parseInt(target.dataset.lastWidth || 0);
            const oldHeight = parseInt(target.dataset.lastHeight || 0);
            
            // Only update if size has changed significantly (at least 5px)
            if (Math.abs(newWidth - oldWidth) > 5 || Math.abs(newHeight - oldHeight) > 5) {
                target.dataset.lastWidth = newWidth;
                target.dataset.lastHeight = newHeight;
                
                // If not in edit mode, update text size
                const editor = document.getElementById(`text-editor-${id}`);
                if (editor && editor.classList.contains('hidden')) {
                    maximizeTextSize(id);
                }
            }
        }
    });
    
    // Observe container for size changes
    resizeObserver.observe(container);
    
    // Also observe parent element for changes
    const parentElement = container.parentElement;
    if (parentElement) {
        resizeObserver.observe(parentElement);
    }
    
    // Save observer in a global variable to prevent garbage collection
    window.textResizeObservers = window.textResizeObservers || {};
    window.textResizeObservers[id] = resizeObserver;
}

// Utility Functions
function shareWhiteboard() {
    document.getElementById('shareModal').classList.remove('hidden');
}

function closeShareModal() {
    document.getElementById('shareModal').classList.add('hidden');
}

function saveWhiteboard() {
    fetch('/api/save-whiteboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            board_code: '<?php echo $boardCode; ?>'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Ett fel uppstod när whiteboarden skulle sparas.');
        }
    });
}

// Todo List Functions
function renderTodoList(id, tasks) {
    const todoList = document.querySelector(`#todo-list-${id} ul`);
    if (!todoList) return;
    
    todoList.innerHTML = ""; // Clear list before updating

    if (tasks.length === 0) {
        // Visa ett meddelande om det inte finns några uppgifter
        const emptyMessage = document.createElement('li');
        emptyMessage.className = 'text-gray-500 text-center p-4 italic';
        emptyMessage.style.fontSize = 'calc(0.08 * min(var(--widget-width), var(--widget-height)))';
        emptyMessage.textContent = 'Inga uppgifter än. Lägg till en uppgift nedan.';
        todoList.appendChild(emptyMessage);
        return;
    }

    tasks.forEach((task, index) => {
        const listItem = document.createElement("li");
        listItem.className = `flex items-center p-2 rounded-lg transition-all ${
            task.completed ? 'bg-gray-50' : 'bg-white'
        } border border-gray-200 hover:bg-gray-50 group cursor-move`;
        listItem.setAttribute("draggable", "true");
        listItem.dataset.index = index;

        // Dynamisk storlek för uppgifter baserat på widget-storlek
        listItem.innerHTML = `
            <div class="flex items-center flex-1 min-w-0 h-full">
                <div onclick="toggleTask(${id}, ${index})" 
                   class="flex-shrink-0 mr-2 cursor-pointer border-2 rounded ${
                       task.completed 
                       ? 'border-blue-500 bg-blue-500 relative after:content-[\'\'] after:block after:absolute after:left-1/2 after:top-1/2 after:-translate-x-1/2 after:-translate-y-1/2 after:w-3 after:h-2 after:border-white after:border-b-2 after:border-r-2 after:rotate-45' 
                       : 'border-gray-300 hover:border-gray-400'
                   }"
                   style="width: calc(0.07 * min(var(--widget-width), var(--widget-height)) + 10px); 
                          height: calc(0.07 * min(var(--widget-width), var(--widget-height)) + 10px);
                          min-width: 16px; min-height: 16px; max-width: 24px; max-height: 24px;">
                </div>
                <span class="flex-grow truncate ${
                    task.completed ? 'line-through text-gray-500' : 'text-gray-800'
                }" style="font-size: clamp(12px, calc(0.07 * min(var(--widget-width), var(--widget-height)) + 6px), 18px);
                           word-break: break-word; display: block; width: 100%;">
                    ${task.text}
                </span>
            </div>
            <button onclick="deleteTask(${id}, ${index})" 
                class="ml-2 text-gray-400 hover:text-red-500 opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity touch-manipulation flex-shrink-0"
                style="width: calc(0.07 * min(var(--widget-width), var(--widget-height)) + 6px); 
                       height: calc(0.07 * min(var(--widget-width), var(--widget-height)) + 6px);
                       min-width: 16px; min-height: 16px; max-width: 22px; max-height: 22px;">
                <svg class="w-full h-full" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        `;

        todoList.appendChild(listItem);
    });

    // VIKTIGT: Vi behöver lägga till drag-and-drop event listeners EFTER att alla element är tillagda
    // men FÖRE den efterföljande koden som uppdaterar widget och layouten
    // Detta säkerställer att händelsehanterarna aktiveras korrekt
    setTimeout(() => {
        setupDragListeners(id);
    }, 0);

    // Make sure parent container has proper styling
    const todoListContainer = document.querySelector(`#todo-list-${id}`);
    if (todoListContainer) {
        todoListContainer.classList.add('flex-grow', 'overflow-auto');
        const ul = todoListContainer.querySelector('ul');
        if (ul) {
            ul.classList.add('space-y-2', 'todo-list');
        }
    }

    // Update widget scaling
    const widget = document.getElementById(`widget-${id}`);
    if (widget) {
        updateWidgetScaling(widget);
    }
    
    // Kontrollera om inmatningsfältet redan finns, annars skapa det
    setupTaskInput(id);
}

// Uppdatera setupDragListeners funktionen för bättre debugging och felhantering
function setupDragListeners(id) {
    console.log(`Setting up drag listeners for todo list ${id}`);
    const todoList = document.querySelector(`#todo-list-${id} ul`);
    if (!todoList) {
        console.warn(`Todo list #todo-list-${id} ul not found`);
        return;
    }
    
    const items = todoList.querySelectorAll('li[draggable="true"]');
    console.log(`Found ${items.length} draggable items in todo list ${id}`);
    
    items.forEach((item, idx) => {
        // Ta bort befintliga händelsehanterare först för att undvika dubbletter
        item.removeEventListener('dragstart', handleDragStart);
        item.removeEventListener('dragover', handleDragOver);
        item.removeEventListener('dragenter', handleDragEnter);
        item.removeEventListener('dragleave', handleDragLeave);
        item.removeEventListener('drop', handleDrop);
        item.removeEventListener('dragend', handleDragEnd);
        
        // Lägg till händelsehanterare igen
        // Använd bindning för att säkerställa korrekt kontext
        item.addEventListener('dragstart', handleDragStart.bind(item));
        item.addEventListener('dragover', handleDragOver.bind(item));
        item.addEventListener('dragenter', handleDragEnter.bind(item));
        item.addEventListener('dragleave', handleDragLeave.bind(item));
        item.addEventListener('drop', handleDrop.bind(item));
        item.addEventListener('dragend', handleDragEnd.bind(item));
        
        // Loggning för debug
        console.log(`Set up drag listeners for item ${idx} in todo list ${id}`);
    });
}

// Setup input field for adding tasks directly
function setupTaskInput(id) {
    const container = document.querySelector(`#widget-${id} .mt-3`);
    if (!container) return;
    
    // Kontrollera om det redan finns en input
    const existingInput = document.getElementById(`new-task-${id}`);
    if (existingInput) return;
    
    // Skapa input-fält med knapp
    const inputContainer = document.createElement('div');
    inputContainer.className = 'w-full flex';
    inputContainer.innerHTML = `
        <input type="text" id="new-task-${id}" placeholder="Ny uppgift..." 
            class="flex-grow border border-gray-300 rounded-l-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            style="font-size: clamp(12px, calc(0.05 * min(var(--widget-width), var(--widget-height)) + 6px), 16px);">
        <button onclick="addNewTask(${id})" 
            class="bg-blue-500 text-white px-3 py-2 rounded-r-lg hover:bg-blue-600 flex items-center justify-center"
            style="min-width: calc(0.15 * min(var(--widget-width), var(--widget-height)));">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
        </button>
    `;
    
    // Om första barnet är en knapp, lägg till input före den
    const firstButton = container.querySelector('button');
    if (firstButton) {
        container.insertBefore(inputContainer, firstButton);
    } else {
        container.appendChild(inputContainer);
    }
    
    // Lägg till event listener för Enter-tangent
    const input = document.getElementById(`new-task-${id}`);
    if (input) {
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addNewTask(id);
            }
        });
    }
}

// New function to add tasks directly from input field
function addNewTask(id) {
    const input = document.getElementById(`new-task-${id}`);
    if (!input) return;
    
    const taskText = input.value.trim();
    if (!taskText) return;
    
    getWidgetSettings(id, function(settings) {
        let tasks = settings.tasks || [];
        const completedTexts = new Set(settings.completedTexts || []);

        const taskId = `task-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;

        const newTask = {
            id: taskId,
            text: taskText,
            completed: completedTexts.has(taskText)
        };
        tasks.push(newTask);

        updateWidgetSettings(id, { 
            tasks: tasks, 
            completedTexts: Array.from(completedTexts)
        });

        // Render list directly instead of waiting for server response
        renderTodoList(id, tasks);
        
        // Clear input
        input.value = '';
        input.focus();
    });
}

// Variables for drag and drop
let draggedItem = null;

function handleDragStart(e) {
    console.log('Drag start', this);
    
    // Spara referens till draget element
    draggedItem = this;
    e.dataTransfer.effectAllowed = 'move';
    
    // Spara widget-ID och index i dataTransfer för att kunna komma åt dem i drop-handlaren
    const widgetId = this.closest('.widget').id.replace('widget-', '');
    const index = this.dataset.index;
    e.dataTransfer.setData('text/plain', JSON.stringify({widgetId, index}));
    
    // Lägg till visuell feedback
    this.classList.add('opacity-50');
    
    // Förhindra textmarkering under drag
    this.querySelectorAll('*').forEach(el => {
        el.style.userSelect = 'none';
    });
}

function handleDragOver(e) {
    if (!draggedItem) return;
    e.preventDefault();
    
    const item = this;
    if (!item || item === draggedItem) return;
    
    const rect = item.getBoundingClientRect();
    const mouseY = e.clientY;
    const threshold = rect.top + (rect.height / 2);
    
    if (mouseY < threshold) {
        item.parentNode.insertBefore(draggedItem, item);
    } else {
        item.parentNode.insertBefore(draggedItem, item.nextSibling);
    }
    
    return false;
}

function handleDragEnter(e) {
    e.preventDefault();
    
    // Lägg till highlight-effekt vid drag over
    const item = this;
    if (item && item !== draggedItem) {
        item.classList.add('bg-blue-50');
    }
}

function handleDragLeave(e) {
    // Ta bort highlight-effekt när vi lämnar elementet
    const item = this;
    if (item && item !== draggedItem) {
        item.classList.remove('bg-blue-50');
    }
}

function handleDragEnd(e) {
    console.log('Drag end', this);
    
    if (draggedItem) {
        // Återställ visuell stil efter drag
        draggedItem.classList.remove('opacity-50');
        draggedItem.querySelectorAll('*').forEach(el => {
            el.style.userSelect = '';
        });
    }
    
    // Ta bort alla highlight-effekter
    document.querySelectorAll('.todo-list li').forEach(item => {
        item.classList.remove('bg-blue-50');
    });
    
    draggedItem = null;
}

function handleDrop(e) {
    e.preventDefault();
    console.log('Drop event', this);
    
    if (!draggedItem) {
        console.warn('No draggedItem in drop event');
        return;
    }
    
    // Ta bort alla highlight-effekter
    document.querySelectorAll('.todo-list li').forEach(item => {
        item.classList.remove('bg-blue-50');
    });
    
    try {
        // Hämta data från dataTransfer
        const dataTransferText = e.dataTransfer.getData('text/plain');
        console.log('Data from dataTransfer:', dataTransferText);
        
        if (!dataTransferText) {
            console.warn('No data in dataTransfer');
            return;
        }
        
        const data = JSON.parse(dataTransferText);
        const widgetId = data.widgetId;
        const dragIndex = parseInt(data.index);
        
        console.log('Processed drop data:', {widgetId, dragIndex});
        
        // Skapa array av alla items i listan för att bestämma ny ordning
        const list = this.parentNode;
        const items = [...list.children];
        
        getWidgetSettings(widgetId, function(settings) {
            let tasks = settings.tasks || [];
            const newTasks = [];
            
            // Bygg den nya listan baserat på DOM-ordningen
            items.forEach(listItem => {
                const originalIndex = parseInt(listItem.dataset.index);
                if (!isNaN(originalIndex) && tasks[originalIndex]) {
                    newTasks.push({...tasks[originalIndex]});
                }
            });
            
            // Kontrollera om vi har rätt antal uppgifter
            if (newTasks.length === tasks.length) {
                // Uppdatera completedTexts baserat på nya uppgiftsordningen
                const completedTexts = new Set(
                    newTasks.filter(task => task.completed).map(task => task.text)
                );
                
                console.log('Saving new task order:', newTasks);
                
                // Spara den nya ordningen till servern
                updateWidgetSettings(widgetId, { 
                    tasks: newTasks, 
                    completedTexts: Array.from(completedTexts)
                }, function() {
                    // Optional callback efter att inställningarna har sparats
                    // Uppdatera DOM för att återställa korrekta index
                    console.log('Settings updated, rendering todo list');
                    renderTodoList(widgetId, newTasks);
                });
            } else {
                console.warn(`Task count mismatch: ${newTasks.length} vs ${tasks.length}`);
            }
        });
    } catch (error) {
        console.error('Error during drop:', error);
    }
    
    // Återställ visuell stil
    draggedItem.classList.remove('opacity-50');
    draggedItem = null;
    
    return false;
}

window.addEventListener('load', function() {
setTimeout(() => {
        document.querySelectorAll('.widget[data-type="todo"]').forEach(widget => {
            const widgetId = widget.id.replace('widget-', '');
            console.log('Setting up todo widget', widgetId);
            
            // Hämta inställningar och rendera todo-listan
            getWidgetSettings(widgetId, function(settings) {
                const tasks = settings.tasks || [];
                const todoList = document.querySelector(`#todo-list-${widgetId} ul`);
                
                if (todoList) {
                    console.log(`Todo list for widget ${widgetId} found with ${tasks.length} tasks`);
                    setupDragListeners(widgetId);
                } else {
                    console.warn(`Todo list for widget ${widgetId} not found`);
                }
            });
        });
    }, 500);
});

// Funktion för att sätta upp alla drag-and-drop event listeners
function setupDragListeners(id) {
    const todoList = document.querySelector(`#todo-list-${id} ul`);
    if (!todoList) return;
    
    const items = todoList.querySelectorAll('li[draggable="true"]');
    
    items.forEach(item => {
        // Ta bort befintliga händelsehanterare först för att undvika dubbletter
        item.removeEventListener('dragstart', handleDragStart);
        item.removeEventListener('dragover', handleDragOver);
        item.removeEventListener('dragenter', handleDragEnter);
        item.removeEventListener('dragleave', handleDragLeave);
        item.removeEventListener('drop', handleDrop);
        item.removeEventListener('dragend', handleDragEnd);
        
        // Lägg till händelsehanterare igen
        item.addEventListener('dragstart', handleDragStart);
        item.addEventListener('dragover', handleDragOver);
        item.addEventListener('dragenter', handleDragEnter);
        item.addEventListener('dragleave', handleDragLeave);
        item.addEventListener('drop', handleDrop);
        item.addEventListener('dragend', handleDragEnd);
    });
}

// Original addTask with prompt, keep for backward compatibility
function addTask(id) {
    // Check if we have the new input field
    const input = document.getElementById(`new-task-${id}`);
    if (input) {
        // Focus the input field instead of showing a prompt
        input.focus();
        return;
    }
    
    // Fall back to prompt dialog
    showPromptDialog("Ange moment:", "", function(taskText) {
        if (taskText && taskText.trim()) {
            getWidgetSettings(id, function(settings) {
                let tasks = settings.tasks || [];
                const completedTexts = new Set(settings.completedTexts || []);

                const taskId = `task-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;

                const newTask = {
                    id: taskId,
                    text: taskText.trim(),
                    completed: completedTexts.has(taskText.trim())
                };
                tasks.push(newTask);

                updateWidgetSettings(id, { 
                    tasks: tasks, 
                    completedTexts: Array.from(completedTexts)
                });

                // Render list directly instead of waiting for server response
                renderTodoList(id, tasks);
            });
        }
    });
}

function toggleTask(id, taskIndex) {
    getWidgetSettings(id, function(settings) {
        let tasks = settings.tasks || [];
        let completedTexts = new Set(settings.completedTexts || []);

        if (tasks[taskIndex]) {
            tasks[taskIndex].completed = !tasks[taskIndex].completed;

            if (tasks[taskIndex].completed) {
                completedTexts.add(tasks[taskIndex].text);
            } else {
                completedTexts.delete(tasks[taskIndex].text);
            }

            updateWidgetSettings(id, { 
                tasks: tasks,
                completedTexts: Array.from(completedTexts)
            });

            // Update just this item without full re-render
            const listItem = document.querySelector(`#todo-list-${id} li[data-index="${taskIndex}"]`);
            if (listItem) {
                if (tasks[taskIndex].completed) {
                    listItem.classList.add('bg-gray-50');
                    listItem.classList.remove('bg-white');
                    
                    const checkbox = listItem.querySelector('div.flex-shrink-0');
                    checkbox.classList.add('border-blue-500', 'bg-blue-500', 'relative', 'after:content-[\'\']', 'after:block', 'after:absolute', 'after:left-1/2', 'after:top-1/2', 'after:-translate-x-1/2', 'after:-translate-y-1/2', 'after:w-3', 'after:h-2', 'after:border-white', 'after:border-b-2', 'after:border-r-2', 'after:rotate-45');
                    checkbox.classList.remove('border-gray-300', 'hover:border-gray-400');
                    
                    const text = listItem.querySelector('span.flex-grow');
                    text.classList.add('line-through', 'text-gray-500');
                    text.classList.remove('text-gray-800');
                } else {
                    listItem.classList.remove('bg-gray-50');
                    listItem.classList.add('bg-white');
                    
                    const checkbox = listItem.querySelector('div.flex-shrink-0');
                    checkbox.classList.remove('border-blue-500', 'bg-blue-500', 'relative', 'after:content-[\'\']', 'after:block', 'after:absolute', 'after:left-1/2', 'after:top-1/2', 'after:-translate-x-1/2', 'after:-translate-y-1/2', 'after:w-3', 'after:h-2', 'after:border-white', 'after:border-b-2', 'after:border-r-2', 'after:rotate-45');
                    checkbox.classList.add('border-gray-300', 'hover:border-gray-400');
                    
                    const text = listItem.querySelector('span.flex-grow');
                    text.classList.remove('line-through', 'text-gray-500');
                    text.classList.add('text-gray-800');
                }
                return; // Skip full re-render
            }
            
            // If item not found in DOM, do full re-render
            renderTodoList(id, tasks);
        }
    });
}

// Uppdaterad deleteTask-funktion för att använda showConfirmDialog istället för confirm
function deleteTask(id, taskIndex) {
    showConfirmDialog('Är du säker på att du vill ta bort detta moment?', 
        // On confirm
        function() {
            getWidgetSettings(id, function(settings) {
                let tasks = settings.tasks || [];
                const completedTexts = new Set(settings.completedTexts || []);
                
                if (tasks[taskIndex] && tasks[taskIndex].completed) {
                    completedTexts.delete(tasks[taskIndex].text);
                }
                
                // Remove with animation
                const listItem = document.querySelector(`#todo-list-${id} li[data-index="${taskIndex}"]`);
                if (listItem) {
                    listItem.style.transition = 'transform 0.2s, opacity 0.2s';
                    listItem.style.transform = 'translateX(30px)';
                    listItem.style.opacity = '0';
                    
                    setTimeout(() => {
                        tasks.splice(taskIndex, 1);
                
                        updateWidgetSettings(id, { 
                            tasks: tasks,
                            completedTexts: Array.from(completedTexts)
                        });
                        
                        renderTodoList(id, tasks);
                    }, 200);
                } else {
                    tasks.splice(taskIndex, 1);
                
                    updateWidgetSettings(id, { 
                        tasks: tasks,
                        completedTexts: Array.from(completedTexts)
                    });
                    
                    renderTodoList(id, tasks);
                }
            });
        },
        // On cancel - do nothing
        function() {}
    );
}

// Uppdaterad clearCompletedTasks-funktion
function clearCompletedTasks(id) {
    getWidgetSettings(id, function(settings) {
        let tasks = settings.tasks || [];
        
        // Check if there are any completed tasks
        if (!tasks.some(task => task.completed)) {
            showInfoDialog('Det finns inga avklarade uppgifter att rensa.');
            return;
        }
        
        showConfirmDialog('Vill du rensa alla avklarade moment?',
            // On confirm
            function() {
                const completedItems = document.querySelectorAll(`#todo-list-${id} li span.line-through`);
                let animationCount = 0;
                const totalAnimations = completedItems.length;
                
                if (completedItems.length > 0) {
                    // Animate each item before removal
                    completedItems.forEach(item => {
                        const li = item.closest('li');
                        if (li) {
                            li.style.transition = 'transform 0.15s, opacity 0.15s';
                            li.style.transform = 'translateX(30px)';
                            li.style.opacity = '0';
                            
                            setTimeout(() => {
                                animationCount++;
                                if (animationCount === totalAnimations) {
                                    // All animations complete, update settings
                                    tasks = tasks.filter(task => !task.completed);
                                    
                                    updateWidgetSettings(id, { 
                                        tasks: tasks, 
                                        completedTexts: [] 
                                    });
                                    
                                    renderTodoList(id, tasks);
                                }
                            }, 150);
                        }
                    });
                } else {
                    // No animations needed
                    tasks = tasks.filter(task => !task.completed);
                    
                    updateWidgetSettings(id, { 
                        tasks: tasks, 
                        completedTexts: [] 
                    });
                    
                    renderTodoList(id, tasks);
                }
            },
            // On cancel - do nothing
            function() {}
        );
    });
}

// Helper function for responsive widget styling
function updateWidgetScaling(widget) {
    if (!widget) return;
    
    // Set CSS variables based on current widget size
    const width = widget.offsetWidth;
    const height = widget.offsetHeight;
    
    widget.style.setProperty('--widget-width', `${width}px`);
    widget.style.setProperty('--widget-height', `${height}px`);
    
    // Apply dynamic font sizes based on widget size
    const minDimension = Math.min(width, height);
    const todoItems = widget.querySelectorAll('.todo-list li');
    
    todoItems.forEach(item => {
        const textElement = item.querySelector('span');
        if (textElement) {
            // Base font size on widget dimensions with limits
            const fontSize = Math.max(12, Math.min(18, 0.07 * minDimension + 6));
            textElement.style.fontSize = `${fontSize}px`;
        }
        
        // Adjust checkbox size
        const checkbox = item.querySelector('div.flex-shrink-0');
        if (checkbox) {
            const checkboxSize = Math.max(16, Math.min(24, 0.07 * minDimension + 10));
            checkbox.style.width = `${checkboxSize}px`;
            checkbox.style.height = `${checkboxSize}px`;
        }
    });
    
    // Adjust input field sizing
    const inputField = widget.querySelector('input[type="text"]');
    if (inputField) {
        const fontSize = Math.max(12, Math.min(16, 0.05 * minDimension + 6));
        inputField.style.fontSize = `${fontSize}px`;
    }
}

// Add resize observer to update scaling
document.addEventListener('DOMContentLoaded', function() {
    const todoWidgets = document.querySelectorAll('.widget[data-type="todo"]');
    
    todoWidgets.forEach(widget => {
        const widgetId = widget.id.replace('widget-', '');
        
        // Sätt upp drag-and-drop för varje widget
        setupDragListeners(widgetId);
        
        // Lägg till resize observer
        const observer = new ResizeObserver(entries => {
            for (let entry of entries) {
                updateWidgetScaling(entry.target);
            }
        });
        
        observer.observe(widget);
    });
});

// Brain Break hantering
/**
 * Opens settings modal for Brain Breaks with improved styling
 * @param {number} id - Widget ID
 */
function openBreakSettings(id) {
    // Create the modal element with higher z-index
    let modal = document.getElementById('break-settings-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'break-settings-modal';
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[10000]';
        document.body.appendChild(modal);
    }

    // Set our flag to prevent fullscreen exit attempts during dialog
    window.modalDialogActive = true;
    
    // Get current settings
    getWidgetSettings(id, function(settings) {
        // Create modal content with consistent styling to other modals
        modal.innerHTML = `
            <div class="bg-white p-6 rounded-lg shadow-xl w-96 max-w-[90%]" onclick="event.stopPropagation();">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold">Inställningar</h3>
                    <button id="close-settings-btn" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Kategori</label>
                        <select id="category-filter-settings-${id}" class="w-full p-2 border rounded">
                            <option value="">Alla aktiviteter</option>
                            <option value="rörelse" ${settings?.categoryFilter === 'rörelse' ? 'selected' : ''}>Rörelse</option>
                            <option value="mindfulness" ${settings?.categoryFilter === 'mindfulness' ? 'selected' : ''}>Mindfulness</option>
                            <option value="dans" ${settings?.categoryFilter === 'dans' ? 'selected' : ''}>Dans</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium mb-1">Längd</label>
                        <select id="duration-filter-settings-${id}" class="w-full p-2 border rounded">
                            <option value="">Alla längder</option>
                            <option value="short" ${settings?.durationFilter === 'short' ? 'selected' : ''}>Kort (< 2 min)</option>
                            <option value="medium" ${settings?.durationFilter === 'medium' ? 'selected' : ''}>Medium (2-5 min)</option>
                            <option value="long" ${settings?.durationFilter === 'long' ? 'selected' : ''}>Lång (> 5 min)</option>
                        </select>
                    </div>
                    
                    <div class="flex items-center">
                        <input type="checkbox" id="own-breaks-filter-settings-${id}" class="mr-2" ${settings?.ownBreaksOnly ? 'checked' : ''}>
                        <label for="own-breaks-filter-settings-${id}" class="text-sm">Visa endast mina aktiviteter</label>
                    </div>
                    
                    <div class="pt-4 flex justify-end">
                        <button id="save-settings-btn" class="px-4 py-2 bg-pink-500 text-white rounded hover:bg-pink-600">
                            Spara
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        // Make modal visible
        modal.style.display = 'flex';
        
        // Function to safely close the modal
        const safeCloseModal = function(save = false) {
            if (save) {
                // Get form values
                const categoryFilter = document.getElementById(`category-filter-settings-${id}`).value;
                const durationFilter = document.getElementById(`duration-filter-settings-${id}`).value;
                const ownBreaksOnly = document.getElementById(`own-breaks-filter-settings-${id}`).checked;
                
                // Save settings
                updateWidgetSettings(id, {
                    categoryFilter: categoryFilter,
                    durationFilter: durationFilter,
                    ownBreaksOnly: ownBreaksOnly
                });
                
                showNotification('Inställningar sparade!', 'success');
            }
            
            // Remove modal and reset flag
            modal.remove();
            window.modalDialogActive = false;
        };
        
        // Event handlers
        document.getElementById('close-settings-btn').addEventListener('click', () => safeCloseModal(false));
        document.getElementById('save-settings-btn').addEventListener('click', () => safeCloseModal(true));
        
        // Close when clicking outside
        modal.addEventListener('click', function(event) {
            if (event.target === this) {
                safeCloseModal(false);
            }
        });
        
        // Handle escape key
        const escHandler = function(e) {
            if (e.key === 'Escape') {
                safeCloseModal(false);
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);
    });
}

/**
 * Toggle visibility of the break settings dropdown
 * @param {number} id - Widget ID
 */
function toggleBreakSettings(id) {
    const settings = document.getElementById(`break-settings-${id}`);
    settings.classList.toggle("hidden");
    
    // Close dropdown when clicking outside
    document.addEventListener("click", function closeSettings(e) {
        if (!settings.contains(e.target) && 
            !e.target.closest(`button[onclick="toggleBreakSettings(${id})"]`)) {
            settings.classList.add("hidden");
            document.removeEventListener("click", closeSettings);
        }
    });
}

/**
 * Randomizes and displays a Brain Break activity
 * @param {number} id - Widget ID
 */
async function randomBreak(id) {
    // Show loading animation
    const display = document.getElementById(`brainbreak-display-${id}`);
    if (display) {
        display.innerHTML = `
            <div class="w-full h-full flex flex-col items-center justify-center">
                <div class="animate-spin rounded-full h-16 w-16 border-b-4 border-pink-500 mb-4"></div>
                <p class="text-gray-500">Hämtar aktivitet...</p>
            </div>
        `;
    }

    try {
        // Hämta inställningar från databas istället för från UI-element
        const settings = await new Promise(resolve => {
            getWidgetSettings(id, settings => resolve(settings || {}));
        });
        
        // Använd inställningarna som är sparade
        const categoryFilter = settings.categoryFilter || '';
        const durationFilter = settings.durationFilter || '';
        const ownBreaksOnly = settings.ownBreaksOnly || false;
        
        // Skapa parametrar för API-anropet
        const params = new URLSearchParams();
        const whiteboardUserId = window.whiteboardUserId;
        
        if (categoryFilter) params.append('category', categoryFilter);
        if (durationFilter) params.append('duration', durationFilter);
        if (whiteboardUserId) params.append('user_id', whiteboardUserId);
        if (ownBreaksOnly) params.append('own_only', 'true');
        
        const response = await fetch(`api/get-random-break.php?${params.toString()}`);
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        
        const breakData = await response.json();
        
        if (!breakData || breakData.error) {
            throw new Error(breakData?.error || 'No activities found');
        }
        
        let content = `
            <div class="w-full h-full flex flex-col items-center">
                <h3 class="text-lg font-bold mb-2" style="font-size: calc(0.06 * min(var(--widget-width), var(--widget-height)));">
                    ${breakData.title}
                </h3>
        `;
        
        if (breakData.youtube_id) {
            content += `
                <div class="w-full flex-grow mb-4" style="min-height: 60%;">
                    <div class="relative w-full h-full">
                        <iframe 
                            src="https://www.youtube.com/embed/${breakData.youtube_id}?autoplay=1" 
                            class="w-full h-full"
                            frameborder="0" 
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                            allowfullscreen>
                        </iframe>
                    </div>
                </div>
            `;
        }
        
        if (breakData.text_content) {
            content += `
                <div class="mt-2 flex-grow text-center px-4" style="font-size: calc(0.05 * min(var(--widget-width), var(--widget-height)));">
                    ${breakData.text_content}
                </div>
            `;
        }
        
        if (breakData.duration) {
            content += `
                <p class="text-gray-600 mb-2" style="font-size: calc(0.04 * min(var(--widget-width), var(--widget-height)));">
                    ${breakData.duration} sekunder
                </p>
            `;
        }
        
        content += `
                <div class="w-full flex justify-center mt-auto">
                    <button onclick="randomBreak(${id})" 
                            class="bg-pink-500 text-white rounded-full px-4 py-2 hover:bg-pink-600 transform hover:scale-105 transition-all shadow-lg"
                            style="font-size: calc(0.04 * min(var(--widget-width), var(--widget-height)));">
                        Slumpa ny aktivitet
                    </button>
                </div>
            </div>
        `;
        
        if (display) {
            display.innerHTML = content;
        }
        
        if (breakData.id) {
            updateWidgetSettings(id, { 
                lastBreakId: breakData.id,
                categoryFilter: categoryFilter,
                durationFilter: durationFilter,
                ownBreaksOnly: ownBreaksOnly
            });
        }
        
    } catch (error) {
        console.error('Fel vid hämtning av brain break:', error);
        
        if (display) {
            display.innerHTML = `
                <div class="w-full h-full flex flex-col items-center justify-center">
                    <div class="text-red-500 mb-4" style="font-size: calc(0.05 * min(var(--widget-width), var(--widget-height)));">
                        ${error.message === 'No activities found' 
                            ? 'Inga aktiviteter hittades med valda filter. Ändra filter eller lägg till nya aktiviteter.' 
                            : 'Kunde inte ladda brain break. Försök igen.'}
                    </div>
                    <button onclick="randomBreak(${id})" 
                            class="bg-pink-500 text-white rounded-full px-6 py-3 hover:bg-pink-600 transform hover:scale-105 transition-all shadow-lg"
                            style="font-size: calc(0.04 * min(var(--widget-width), var(--widget-height)));">
                        Försök igen
                    </button>
                    <button onclick="openBreakSettings(${id})" 
                            class="mt-2 text-blue-500 hover:text-blue-600"
                            style="font-size: calc(0.03 * min(var(--widget-width), var(--widget-height)));">
                        Ändra filter
                    </button>
                </div>
            `;
        }
    }
}

/**
 * Opens the library of Brain Break activities
 * @param {number} id - Widget ID
 */
async function openBreakLibrary(id) {
    // Check if user is logged in
    

    let modal = document.getElementById('breakLibraryModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'breakLibraryModal';
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[10000]';
        document.body.appendChild(modal);
    }

    // Set our flag to prevent fullscreen exit attempts during dialog
    window.modalDialogActive = true;

    modal.innerHTML = `
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
            <div class="sticky top-0 bg-white p-4 border-b z-10">
                <div class="flex justify-between items-center">
                    <h2 class="text-xl font-bold">Hantera Brain Breaks</h2>
                    <button id="close-library-btn" class="text-gray-500 hover:text-gray-700">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <div class="mt-4 flex justify-between items-center">
                    <div class="flex-1">
                        <select id="category-filter-list" onchange="loadBreaksList(${id})" 
                                class="rounded border border-gray-200 px-2 py-1 bg-white w-full">
                            <option value="">Alla kategorier</option>
                            <option value="rörelse">Rörelse</option>
                            <option value="mindfulness">Mindfulness</option>
                            <option value="dans">Dans</option>
                        </select>
                    </div>
                    <button onclick="addNewBreak(${id})" 
                            class="ml-4 flex items-center bg-pink-500 text-white px-3 py-1 rounded-full hover:bg-pink-600">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Ny aktivitet
                    </button>
                </div>
            </div>
            
            <div class="p-4">
                <div id="breaks-items-list" class="space-y-2">
                    <div class="flex justify-center py-8">
                        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-pink-500"></div>
                    </div>
                </div>
            </div>
        </div>
    `;
    modal.style.display = 'flex';
    
    // Close button handler
    document.getElementById('close-library-btn').addEventListener('click', function() {
        modal.remove();
        window.modalDialogActive = false;
    });
    
    // Click outside to close
    modal.addEventListener('click', function(event) {
        if (event.target === this) {
            modal.remove();
            window.modalDialogActive = false;
        }
    });
    
    // Escape key to close
    const escHandler = function(e) {
        if (e.key === 'Escape') {
            modal.remove();
            window.modalDialogActive = false;
            document.removeEventListener('keydown', escHandler);
        }
    };
    document.addEventListener('keydown', escHandler);
    
    // Load the breaks list
    await loadBreaksList(id);
}

/**
 * Close the Break Library modal (for backward compatibility)
 */
function closeBreakLibrary() {
    const modal = document.getElementById('breakLibraryModal');
    if (modal) {
        modal.remove();
        window.modalDialogActive = false;
    }
}

/**
 * Loads the list of Brain Break activities
 * @param {number} id - Widget ID
 */
async function loadBreaksList(id) {
    try {
        const whiteboardUserId = window.whiteboardUserId;
        
        // Get filter value
        const categoryFilter = document.getElementById('category-filter-list')?.value || '';
        
        // Build query parameters
        const params = new URLSearchParams();
        // Always filter by user ID as we only want to see own breaks
        if (whiteboardUserId) params.append('user_id', whiteboardUserId);
        if (categoryFilter) params.append('category', categoryFilter);
        
        // Fetch breaks with filter
        const response = await fetch(`api/get-breaks.php?${params.toString()}`);
        const breaks = await response.json();
        
        // Update the list
        const itemsList = document.getElementById('breaks-items-list');
        if (!itemsList) return;
        
        if (breaks.length === 0) {
            itemsList.innerHTML = `
                <div class="text-center p-12 bg-gray-50 rounded-lg border border-gray-200">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                              d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                    <h3 class="mt-2 text-lg font-medium text-gray-900">Inga Brain Breaks</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Du har inte skapat några brain breaks i denna kategori än.
                    </p>
                    <div class="mt-6">
                        <button type="button" onclick="addNewBreak(${id})" 
                                class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-pink-500 hover:bg-pink-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-pink-500">
                            <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Lägg till ny aktivitet
                        </button>
                    </div>
                </div>
            `;
            return;
        }

        // Render each break with improved card design
        itemsList.innerHTML = breaks.map(breakItem => `
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden shadow-sm hover:shadow-md transition-shadow">
                <div class="p-4">
                    <div class="flex justify-between items-start">
                        <div>
                            <h3 class="font-bold text-gray-900 text-lg">${breakItem.title || ''}</h3>
                            <div class="mt-1 flex items-center text-sm text-gray-600">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                    breakItem.category === 'rörelse' ? 'bg-blue-100 text-blue-800' : 
                                    breakItem.category === 'mindfulness' ? 'bg-green-100 text-green-800' : 
                                    'bg-purple-100 text-purple-800'
                                }">
                                    ${breakItem.category || ''}
                                </span>
                                ${breakItem.duration ? `
                                <span class="ml-2 inline-flex items-center text-xs">
                                    <svg class="mr-1 h-3 w-3 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                              d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    ${breakItem.duration}s
                                </span>
                                ` : ''}
                                
                                <span class="ml-2 inline-flex items-center text-xs">
                                    ${breakItem.youtube_id ? `
                                    <svg class="mr-1 h-3 w-3 text-red-500" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M19.615 3.184c-3.604-.246-11.631-.245-15.23 0-3.897.266-4.356 2.62-4.385 8.816.029 6.185.484 8.549 4.385 8.816 3.6.245 11.626.246 15.23 0 3.897-.266 4.356-2.62 4.385-8.816-.029-6.185-.484-8.549-4.385-8.816zm-10.615 12.816v-8l8 3.993-8 4.007z"/>
                                    </svg>
                                    ` : `
                                    <svg class="mr-1 h-3 w-3 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                              d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                    `}
                                    ${breakItem.text_content ? 'Text' : (breakItem.youtube_id ? 'Video' : '')}
                                </span>
                            </div>
                        </div>
                        <div class="flex space-x-2">
                            <button onclick="editBreak(${id}, ${breakItem.id})" 
                                    class="text-blue-500 hover:text-blue-700 p-1">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                          d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                            </button>
                            <button onclick="deleteBreak(${id}, ${breakItem.id})" 
                                    class="text-red-500 hover:text-red-700 p-1">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                          d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `).join('');
        
    } catch (error) {
        console.error('Fel vid laddning av breaks:', error);
        const itemsList = document.getElementById('breaks-items-list');
        if (itemsList) {
            itemsList.innerHTML = `
                <div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-lg">
                    <div class="flex">
                        <svg class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                  d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>Ett fel uppstod vid laddning av brain breaks. Försök igen.</span>
                    </div>
                </div>
            `;
        }
    }
}

/**
 * Opens the modal to add a new Brain Break
 * @param {number} id - Widget ID
 */
async function addNewBreak(id) {
    // Check if user is logged in


    let modal = document.getElementById('addBreakModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'addBreakModal';
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[10000]';
        document.body.appendChild(modal);
    }

    // Set our flag to prevent fullscreen exit attempts during dialog
    window.modalDialogActive = true;

    modal.innerHTML = `
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
            <div class="relative p-4">
                <button onclick="closeAddBreak()" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
                <h2 class="text-xl font-bold mb-4">Lägg till ny Brain Break</h2>
                <form id="add-break-form" onsubmit="submitNewBreak(event, ${id})" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Titel</label>
                        <input type="text" name="title" required 
                               class="w-full rounded border p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium mb-1">Typ av innehåll</label>
                        <select name="content_type" id="content-type-select" onchange="toggleContentFields()" class="w-full rounded border p-2">
                            <option value="text">Text</option>
                            <option value="youtube">YouTube Video</option>
                        </select>
                    </div>

                    <div id="text-content-field">
                        <label class="block text-sm font-medium mb-1">Text innehåll</label>
                        <textarea name="text_content" rows="3" 
                               class="w-full rounded border p-2"></textarea>
                    </div>
                    
                    <div id="youtube-content-field" style="display: none;">
                        <label class="block text-sm font-medium mb-1">YouTube ID</label>
                        <input type="text" name="youtube_id" 
                               class="w-full rounded border p-2">
                        <p class="text-xs text-gray-500 mt-1">T.ex. "dQw4w9WgXcQ" från URL:en</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium mb-1">Kategori</label>
                        <select name="category" required class="w-full rounded border p-2">
                            <option value="rörelse">Rörelse</option>
                            <option value="mindfulness">Mindfulness</option>
                            <option value="dans">Dans</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium mb-1">Längd (sekunder)</label>
                        <input type="number" name="duration" min="0" 
                               class="w-full rounded border p-2">
                    </div>
                    
                    <div class="flex items-center">
                    <input type="checkbox" name="is_public" id="is_public" class="mr-2">
                        <label for="is_public" class="text-sm">Gör tillgänglig för andra lärare</label>
                    </div>
                    
                    <div class="flex justify-end space-x-2 mt-4">
                        <button type="button" onclick="closeAddBreak()" 
                                class="px-4 py-2 text-gray-600 hover:text-gray-800">
                            Avbryt
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 bg-pink-500 text-white rounded hover:bg-pink-600">
                            Spara
                        </button>
                    </div>
                </form>
            </div>
        </div>
    `;
    modal.style.display = 'flex';
    
    // Add escape key handler
    const escHandler = function(e) {
        if (e.key === 'Escape') {
            closeAddBreak();
            document.removeEventListener('keydown', escHandler);
        }
    };
    document.addEventListener('keydown', escHandler);
    
    // Add click outside handler
    modal.addEventListener('click', function(event) {
        if (event.target === this) {
            closeAddBreak();
        }
    });
}

/**
 * Toggles between text and YouTube fields in the add/edit forms
 */
function toggleContentFields() {
    const contentType = document.getElementById('content-type-select').value;
    const textField = document.getElementById('text-content-field');
    const youtubeField = document.getElementById('youtube-content-field');
    
    if (contentType === 'text') {
        textField.style.display = 'block';
        youtubeField.style.display = 'none';
    } else {
        textField.style.display = 'none';
        youtubeField.style.display = 'block';
    }
}

/**
 * Toggles between text and YouTube fields in the edit form
 */
function toggleEditContentFields() {
    const contentType = document.getElementById('edit-content-type-select').value;
    const textField = document.getElementById('edit-text-content-field');
    const youtubeField = document.getElementById('edit-youtube-content-field');
    
    if (contentType === 'text') {
        textField.style.display = 'block';
        youtubeField.style.display = 'none';
    } else {
        textField.style.display = 'none';
        youtubeField.style.display = 'block';
    }
}

/**
 * Closes the add Brain Break modal
 */
function closeAddBreak() {
    const modal = document.getElementById('addBreakModal');
    if (modal) {
        modal.remove();
        window.modalDialogActive = false;
    }
}

/**
 * Submits the form to create a new Brain Break
 * @param {Event} event - The form submit event
 * @param {number} id - Widget ID
 */
async function submitNewBreak(event, id) {
    event.preventDefault();
    const form = event.target;
    
    // Disable submit button to prevent double submission
    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.innerHTML = `
            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Sparar...
        `;
    }
    
    try {
        const contentType = form.content_type.value;
        const formData = {
            title: form.title.value,
            category: form.category.value,
            duration: form.duration.value ? parseInt(form.duration.value) : null,
            content_type: contentType,
            youtube_id: contentType === 'youtube' ? form.youtube_id.value : null,
            text_content: contentType === 'text' ? form.text_content.value : null,
            is_public: form.is_public.checked
        };

        const response = await fetch('api/add-break.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(formData)
        });

        const result = await response.json();
        
        if (result.success) {
            // Close add modal and open library modal
            closeAddBreak();
            showNotification('Brain break skapad!', 'success');
            
            // If library modal is open, refresh the list
            const libraryModal = document.getElementById('breakLibraryModal');
            if (libraryModal) {
                await loadBreaksList(id);
            } else {
                // Otherwise, open the library
                openBreakLibrary(id);
            }
        } else {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = 'Spara';
            }
            showNotification(result.error || 'Ett fel uppstod vid skapande', 'error');
        }
    } catch (error) {
        console.error('Fel vid skapande av break:', error);
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = 'Spara';
        }
        showNotification('Ett fel uppstod. Försök igen.', 'error');
    }
}

/**
 * Deletes a Brain Break with confirmation
 * @param {number} widgetId - Widget ID
 * @param {number} breakId - Break ID to delete
 */
async function deleteBreak(widgetId, breakId) {
    showConfirmDialog('Är du säker på att du vill ta bort denna brain break?',
        // On confirm
        async function() {
            try {
                const formData = new FormData();
                formData.append('id', breakId);

                const response = await fetch('api/delete-break.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                
                if (result.success) {
                    await loadBreaksList(widgetId);
                    showNotification('Brain break borttagen!', 'success');
                } else {
                    showNotification(result.error || 'Ett fel uppstod vid borttagning', 'error');
                }
            } catch (error) {
                console.error('Fel vid borttagning av break:', error);
                showNotification('Ett fel uppstod. Försök igen.', 'error');
            }
        }
    );
}

/**
 * Opens the edit modal for a Brain Break
 * @param {number} widgetId - Widget ID
 * @param {number} breakId - Break ID to edit
 */
async function editBreak(widgetId, breakId) {
    try {
        // Show loading state
        const libraryModal = document.getElementById('breakLibraryModal');
        const loadingDiv = document.createElement('div');
        loadingDiv.id = 'edit-loading-overlay';
        loadingDiv.className = 'fixed inset-0 bg-black bg-opacity-20 flex items-center justify-center z-[10001]';
        loadingDiv.innerHTML = `
            <div class="bg-white rounded-lg p-4 shadow-lg">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-pink-500"></div>
            </div>
        `;
        document.body.appendChild(loadingDiv);

        // Fetch break data
        const response = await fetch(`api/get-break.php?id=${breakId}`);
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        const breakData = await response.json();

        if (breakData.error) {
            throw new Error(breakData.error);
        }

        // Remove loading overlay
        loadingDiv.remove();

        // Create edit modal
        let modal = document.getElementById('editBreakModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'editBreakModal';
            modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[10001]';
            document.body.appendChild(modal);
        }

        // Set our flag to prevent fullscreen exit attempts during dialog
        window.modalDialogActive = true;

        modal.innerHTML = `
            <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="relative p-4">
                    <button onclick="closeEditModal()" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                    <h2 class="text-xl font-bold mb-4">Redigera Brain Break</h2>
                    <form id="edit-break-form" onsubmit="submitEditBreak(event, ${widgetId}, ${breakId})" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Titel</label>
                            <input type="text" name="title" required value="${breakData.title || ''}"
                                   class="w-full rounded border p-2">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium mb-1">Typ av innehåll</label>
                            <select name="content_type" id="edit-content-type-select" onchange="toggleEditContentFields()" class="w-full rounded border p-2">
                                <option value="text" ${!breakData.youtube_id ? 'selected' : ''}>Text</option>
                                <option value="youtube" ${breakData.youtube_id ? 'selected' : ''}>YouTube Video</option>
                            </select>
                        </div>

                        <div id="edit-text-content-field" ${breakData.youtube_id ? 'style="display: none;"' : ''}>
                            <label class="block text-sm font-medium mb-1">Text innehåll</label>
                            <textarea name="text_content" rows="3" 
                                   class="w-full rounded border p-2">${breakData.text_content || ''}</textarea>
                        </div>
                        
                        <div id="edit-youtube-content-field" ${!breakData.youtube_id ? 'style="display: none;"' : ''}>
                            <label class="block text-sm font-medium mb-1">YouTube ID</label>
                            <input type="text" name="youtube_id" 
                                   value="${breakData.youtube_id || ''}"
                                   class="w-full rounded border p-2">
                            <p class="text-xs text-gray-500 mt-1">T.ex. "dQw4w9WgXcQ" från URL:en</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium mb-1">Kategori</label>
                            <select name="category" required class="w-full rounded border p-2">
                                <option value="rörelse" ${breakData.category === 'rörelse' ? 'selected' : ''}>Rörelse</option>
                                <option value="mindfulness" ${breakData.category === 'mindfulness' ? 'selected' : ''}>Mindfulness</option>
                                <option value="dans" ${breakData.category === 'dans' ? 'selected' : ''}>Dans</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium mb-1">Längd (sekunder)</label>
                            <input type="number" name="duration" min="0" 
                                   value="${breakData.duration || ''}"
                                   class="w-full rounded border p-2">
                        </div>
                        
                        <div class="flex items-center">
                            <input type="checkbox" name="is_public" id="edit_is_public" 
                                   ${breakData.is_public ? 'checked' : ''} class="mr-2">
                            <label for="edit_is_public" class="text-sm">Gör tillgänglig för andra lärare</label>
                        </div>
                        
                        <div class="flex justify-end space-x-2 mt-4">
                            <button type="button" onclick="closeEditModal()" 
                                    class="px-4 py-2 text-gray-600 hover:text-gray-800">
                                Avbryt
                            </button>
                            <button type="submit" id="edit-submit-btn"
                                    class="px-4 py-2 bg-pink-500 text-white rounded hover:bg-pink-600">
                                Spara ändringar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        `;
        modal.style.display = 'flex';
        
        // Add escape key handler
        const escHandler = function(e) {
            if (e.key === 'Escape') {
                closeEditModal();
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);
        
        // Add click outside handler
        modal.addEventListener('click', function(event) {
            if (event.target === this) {
                closeEditModal();
            }
        });
    } catch (error) {
        console.error('Fel vid laddning av break:', error);
        // Remove loading overlay if it exists
        const loadingDiv = document.getElementById('edit-loading-overlay');
        if (loadingDiv) loadingDiv.remove();
        
        showNotification('Ett fel uppstod. Försök igen.', 'error');
    }
}

/**
 * Closes the edit modal
 */
function closeEditModal() {
    const modal = document.getElementById('editBreakModal');
    if (modal) {
        modal.remove();
        window.modalDialogActive = false;
    }
}

/**
 * Submits edited Brain Break data
 * @param {Event} event - The form submit event
 * @param {number} widgetId - Widget ID
 * @param {number} breakId - Break ID being edited
 */
async function submitEditBreak(event, widgetId, breakId) {
    event.preventDefault();
    const form = event.target;
    
    // Disable submit button to prevent double submission
    const submitButton = document.getElementById('edit-submit-btn');
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.innerHTML = `
            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Sparar...
        `;
    }
    
    try {
        const contentType = form.content_type.value;
        const formData = {
            id: breakId,
            title: form.title.value,
            category: form.category.value,
            duration: form.duration.value ? parseInt(form.duration.value) : null,
            content_type: contentType,
            youtube_id: contentType === 'youtube' ? form.youtube_id.value : null,
            text_content: contentType === 'text' ? form.text_content.value : null,
            is_public: form.is_public.checked
        };

        const response = await fetch('api/update-break.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(formData)
        });

        const result = await response.json();
        
        if (result.success) {
            closeEditModal();
            await loadBreaksList(widgetId);
            showNotification('Brain break uppdaterad!', 'success');
        } else {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = 'Spara ändringar';
            }
            showNotification(result.error || 'Ett fel uppstod vid uppdatering', 'error');
        }
    } catch (error) {
        console.error('Fel vid uppdatering av break:', error);
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = 'Spara ändringar';
        }
        showNotification('Ett fel uppstod. Försök igen.', 'error');
    }
}

/**
 * Creates or updates a notification message
 * @param {string} message - The notification message
 * @param {string} type - The notification type ('success' or 'error')
 */
function showNotification(message, type = 'success') {
    // Remove any existing notification first
    const existingNotification = document.querySelector('.brain-break-notification');
    if (existingNotification) {
        existingNotification.remove();
    }
    
    // Create a new notification
    const notification = document.createElement('div');
    notification.className = `fixed bottom-4 right-4 p-4 rounded shadow-lg ${
        type === 'success' ? 'bg-green-500' : 'bg-red-500'
    } text-white brain-break-notification z-[20000] flex items-center`;
    
    // Add an icon based on the notification type
    notification.innerHTML = `
        <div class="mr-2">
            ${type === 'success' ? `
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
            ` : `
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                          d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            `}
        </div>
        <div>${message}</div>
    `;
    
    document.body.appendChild(notification);
    
    // Add a simple fade-in animation
    notification.style.opacity = '0';
    notification.style.transform = 'translateY(20px)';
    notification.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
    
    // Trigger animation
    setTimeout(() => {
        notification.style.opacity = '1';
        notification.style.transform = 'translateY(0)';
    }, 10);
    
    // Auto-remove after a delay
    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transform = 'translateY(20px)';
        
        // Remove from DOM after animation completes
        setTimeout(() => {
            notification.remove();
        }, 300);
    }, 3000);
}

// Trafikljus
function setTrafficLight(widgetId, state) {
    fetch('/api/update-widget-settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            widget_id: widgetId,
            settings: {
                state: state
            }
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Använd loadWidgetContent för att uppdatera widget
            loadWidgetContent({
                id: widgetId,
                type: 'trafficlight'
            });
        } else {
            console.error('Failed to update traffic light state:', data.error);
        }
    })
    .catch(error => console.error('Error:', error));
}

// Poll
function openPollEditor(widgetId) {
    const urlParams = new URLSearchParams(window.location.search);
    const boardCode = urlParams.get('board');

    let modal = document.getElementById('pollEditorModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'pollEditorModal';
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
        document.body.appendChild(modal);
    }

    // Hämta innehållet från poll-editor.php och lägg in i modalen
    fetch(`/poll-editor.php?widget_id=${widgetId}&board=${boardCode}`)
        .then(response => response.text())
        .then(html => {
            modal.innerHTML = html;
            modal.style.display = 'flex';

            // Lägg till event listener för formuläret efter att innehållet har laddats
            const form = modal.querySelector('form');
            if (form) {
                form.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    console.log('Form submitted');
                    const formData = new FormData(this);
                    
                    try {
                        const response = await fetch('/poll-editor.php?widget_id=' + widgetId + '&board=' + boardCode, {
                            method: 'POST',
                            body: formData
                        });

                        if (response.ok) {
                            console.log('Poll saved successfully');
                            location.reload(); // Ladda om sidan för att visa den nya pollen
                        } else {
                            console.error('Failed to save poll');
                            alert('Ett fel uppstod när omröstningen skulle sparas');
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        alert('Ett fel uppstod när omröstningen skulle sparas');
                    }
                });
            }
        })
        .catch(err => console.error('Error loading poll editor:', err));
}

// Funktion för att öppna modalen och initialisera poll results
function openPollResults(widgetId) {
    let modal = document.getElementById('pollResultsModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'pollResultsModal';
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
        document.body.appendChild(modal);
    }

    // Använd poll-results.php för HTML-vyn
    fetch(`/poll-results.php?widget_id=${widgetId}`)
        .then(response => response.text())
        .then(html => {
            modal.innerHTML = html;
            modal.style.display = 'flex';
            initializePollResults();
            startPollUpdates(widgetId);
        })
        .catch(err => console.error('Error loading poll results:', err));
}

// Funktion för att stänga modalen och städa upp
function closePollResults() {
    const modal = document.getElementById('pollResultsModal');
    if (modal) {
        clearInterval(window.pollUpdateInterval); // Rensa uppdateringsintervallet
        modal.style.display = 'none';
        modal.innerHTML = '';
    }
}

// Funktion för att initiera animationerna
function initializePollResults() {
    // Fade in alternativen
    document.querySelectorAll('.poll-option').forEach((option, index) => {
        setTimeout(() => {
            option.classList.remove('opacity-0');
        }, index * 150);
    });

    // Animera staplarna efter en kort fördröjning
    setTimeout(() => {
        document.querySelectorAll('.poll-bar').forEach(bar => {
            const targetWidth = bar.getAttribute('data-target-width');
            bar.style.width = targetWidth + '%';
        });
    }, 300);
}

// Funktion för att uppdatera resultaten
function updatePollResults(widgetId) {
    // Använd get-poll-results.php för JSON-data
    fetch(`/get-poll-results.php?widget_id=${widgetId}`)
        .then(response => response.json())
        .then(data => {
            const totalVotes = data.reduce((sum, option) => sum + parseInt(option.votes), 0);
            
            data.forEach((option, index) => {
                const percentage = totalVotes > 0 ? Math.round((option.votes / totalVotes) * 100) : 0;
                const optionElement = document.querySelectorAll('.poll-option')[index];
                
                if (optionElement) {
                    optionElement.querySelector('.percentage').textContent = `${percentage}%`;
                    optionElement.querySelector('.votes').textContent = option.votes;
                    
                    const bar = optionElement.querySelector('.poll-bar');
                    bar.style.width = `${percentage}%`;
                }
            });
            
            document.getElementById('totalVotes').textContent = totalVotes;
        })
        .catch(error => console.error('Error:', error));
}

function startPollUpdates(widgetId) {
    // Rensa eventuellt existerande intervall
    if (window.pollUpdateInterval) {
        clearInterval(window.pollUpdateInterval);
    }
    
    // Starta nytt intervall
    window.pollUpdateInterval = setInterval(() => {
        updatePollResults(widgetId);
    }, 10000); // Uppdatera var 10:e sekund
}

function closePollEditor() {
    const modal = document.getElementById('pollEditorModal');
    if (modal) {
        modal.style.display = 'none';
        modal.innerHTML = '';
    }
}

function renderPollCode(id, settings) {
    const widget = document.getElementById(`widget-${id}`);
    if (!widget) return;
    
    const container = widget.querySelector('.widget-container');
    if (!container) return;
    
    // Get existing content to check if we have a poll
    const currentContent = container.innerHTML;
    const hasPoll = currentContent.includes('poll-question');
    const isHidden = settings.isHidden || false;

    if (hasPoll) {
        // Preserve the poll question from current content
        const questionMatch = currentContent.match(/<h2[^>]*>(.*?)<\/h2>/);
        const pollQuestion = questionMatch ? questionMatch[1] : '';
        const pollCodeMatch = currentContent.match(/koden:<\/p>\s*<p[^>]*>(.*?)<\/p>/);
        const pollCode = pollCodeMatch ? pollCodeMatch[1] : '';
        const voteUrl = `https://${window.location.host}/vote.php?poll_code=${encodeURIComponent(pollCode)}`;

        container.innerHTML = `
            <div class="widget-header bg-blue-500 text-white p-2 rounded-t-lg flex justify-between items-center">
                <span class="text-sm font-medium">Omröstning</span>
                <div class="flex space-x-2">
                    <button onclick="openPollResults(${id})" class="text-white hover:text-blue-200">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 20V10M12 20V4M6 20v-6" />
                        </svg>
                    </button>
                    <button onclick="openPollEditor(${id})" class="text-white hover:text-blue-200">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                  d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                        </svg>
                    </button>
                    <button onclick="togglePollCodeVisibility(${id})" class="text-white hover:text-blue-200">
                        ${isHidden ? `
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        ` : `
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                            <line x1="1" y1="1" x2="23" y2="23"/>
                        </svg>
                        `}
                    </button>
                </div>
            </div>
            <div class="w-full h-full flex flex-col">
                <h2 class="poll-question text-center font-bold mb-2 px-4 pt-2">${pollQuestion}</h2>
                <div class="poll-content flex-1 ${isHidden ? 'hidden' : ''}">
                    <div class="w-full h-full flex flex-col items-center justify-center p-2">
                        <div class="qr-wrapper flex-1 w-full flex items-center justify-center">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(voteUrl)}" 
                                 alt="QR-kod" class="qr-code max-w-full max-h-full object-contain">
                        </div>
                        <div class="code-info mt-2 text-center">
                            <p class="text-xs">Använd koden:</p>
                            <p class="poll-code font-bold text-sm">${pollCode}</p>
                        </div>
                    </div>
                </div>
            </div>`;
    }
}

function togglePollCodeVisibility(id) {
    getWidgetSettings(id, function(settings) {
        // Förenkla settings-hanteringen
        settings = settings || {};
        const isHidden = !settings.isHidden; // Vänd på det nuvarande värdet
        settings.isHidden = isHidden;
        
        // Uppdatera databasen och UI
        updateWidgetSettings(id, settings);
        
        // Uppdatera UI direkt
        const widget = document.getElementById('widget-' + id);
        if (widget) {
            const pollContent = widget.querySelector('.poll-content');
            if (pollContent) {
                pollContent.classList.toggle('hidden');
            }
            
            // Uppdatera ikonen
            const button = widget.querySelector('button[onclick*="togglePollCodeVisibility"]');
            if (button) {
                if (isHidden) {
                    button.innerHTML = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
                } else {
                    button.innerHTML = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
                }
            }
        }
    });
}

/**
 * Uppdaterar inmatningsmetod för grupper när användaren byter metod
 */
function updateGroupMethod(id) {
    const methodSelect = document.getElementById(`group-method-${id}`);
    const groupCountInput = document.getElementById(`group-count-${id}`);
    const groupSizeInput = document.getElementById(`group-size-${id}`);
    const inputLabel = document.getElementById(`group-input-label-${id}`);
    
    if (!methodSelect || !groupCountInput || !groupSizeInput || !inputLabel) return;
    
    const method = methodSelect.value;
    
    if (method === 'count') {
        groupCountInput.classList.remove('hidden');
        groupSizeInput.classList.add('hidden');
        inputLabel.textContent = 'Antal grupper:';
    } else {
        groupCountInput.classList.add('hidden');
        groupSizeInput.classList.remove('hidden');
        inputLabel.textContent = 'Antal per grupp:';
    }
    
    // Spara inställningen
    saveGroupSettings(id);
}

/**
 * Sparar inställningarna för en grupper-widget
 */
function saveGroupSettings(id) {
    // Hämta värdet från widgetens menyfält
    const menuGroupCountInput = document.getElementById(`group-count-${id}`);
    if (!menuGroupCountInput) return;
    
    const groupCount = parseInt(menuGroupCountInput.value) || 2;
    
    // Hämta aktuella inställningar från databasen först
    fetch(`/api/get-widget-settings.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            const settings = data.settings || {};
            
            // Uppdatera inställningarna och behåll övriga värden
            const updatedSettings = {
                ...settings,
                groupCount: groupCount
            };
            
            // Spara uppdaterade inställningar
            return fetch('/api/update-widget-settings.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    widget_id: id,
                    settings: updatedSettings
                })
            });
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Inställningar uppdaterade');
                
                // Uppdatera eventuellt värde i inställningsmodalen om den är öppen
                const settingsGroupCountInput = document.getElementById('settings-group-count');
                if (settingsGroupCountInput) {
                    settingsGroupCountInput.value = groupCount;
                }
            } else {
                console.error('Fel vid uppdatering av inställningar:', data.error);
            }
        })
        .catch(error => {
            console.error('Fel vid uppdatering av inställningar:', error);
        });
}

// Uppdaterad generateGroups-funktion med förbättrad storlekshantering
function generateGroups(id) {
    // Visa laddningsindikator
    const resultContainer = document.getElementById(`groups-result-${id}`);
    if (resultContainer) {
        resultContainer.innerHTML = '<div class="w-full h-full flex items-center justify-center"><div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div></div>';
    }
    
    // Memorera widgetens befintliga storlek innan gruppgenerering
    const widget = document.getElementById(`widget-${id}`);
    let originalWidth = 0;
    let originalHeight = 0;
    
    if (widget) {
        originalWidth = parseInt(widget.style.width) || widget.offsetWidth;
        originalHeight = parseInt(widget.style.height) || widget.offsetHeight;
    }
    
    // Hämta inställningar från databasen
    fetch(`/api/get-widget-settings.php?id=${id}`)
        .then(response => response.json())
        .then(settingsData => {
            const settings = settingsData.settings || {};
            
            // Använd inställningarna för att generera grupperna
            fetch('/api/generate-groups.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    widget_id: id,
                    groupMethod: settings.groupMethod || 'count',
                    groupCount: settings.groupCount || 2,
                    groupSize: settings.groupSize || 2,
                    shuffle: settings.shuffle !== false,
                    balance: settings.balance !== false
                })
            })
            .then(response => response.json())
            .then(data => {
                if (!resultContainer) return;
                
                // Visa genererade grupper med ny design
                let html = '';
                
                if (data.groups && data.groups.length > 0) {
                    // Dynamiskt antal kolumner baserat på antal grupper
                    const columnClass = data.groups.length <= 2 ? 'grid-cols-2' : 
                                       data.groups.length <= 4 ? 'grid-cols-2' : 'grid-cols-3';
                    
                    html += `<div class="grid ${columnClass} gap-4 w-full h-full p-3">`;
                    
                    data.groups.forEach((group, index) => {
                        html += `
                            <div class="bg-blue-50 rounded-lg p-4 flex flex-col fade-in">
                                <div class="text-blue-600 font-bold text-xl mb-2">
                                    Grupp ${index + 1} <span class="text-gray-500 font-normal text-base">(${group.length} pers)</span>
                                </div>
                                <div class="border-t border-blue-100 pt-2">
                                    <div class="text-gray-700 text-base">
                                        ${group.join(', ')}
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    html += '</div>';
                } else {
                    html = '<div class="text-center text-gray-500 p-4 w-full">Inga grupper genererade. Lägg till namn och försök igen.</div>';
                }
                
                resultContainer.innerHTML = html;
                
                // Anpassa widgeten till innehållet, men respektera originalstorlek
                setTimeout(() => {
                    const widget = document.getElementById(`widget-${id}`);
                    
                    // Återställ först till originalstorlek (för att förhindra krympning)
                    if (widget && originalWidth > 0 && originalHeight > 0) {
                        widget.style.width = `${originalWidth}px`;
                        widget.style.height = `${originalHeight}px`;
                    }
                    
                    // Anropa sedan den förbättrade storleksfunktionen som respekterar befintlig storlek
                    resizeWidgetToFitContent(id);
                }, 100);
            })
            .catch(error => {
                console.error('Error generating groups:', error);
                if (resultContainer) {
                    resultContainer.innerHTML = '<div class="text-center text-red-500 p-4 w-full">Ett fel uppstod vid generering av grupper. Försök igen.</div>';
                }
                // Återställ till originalstorlek vid fel
                if (widget && originalWidth > 0 && originalHeight > 0) {
                    widget.style.width = `${originalWidth}px`;
                    widget.style.height = `${originalHeight}px`;
                }
            });
        })
        .catch(error => {
            console.error('Error fetching settings:', error);
            if (resultContainer) {
                resultContainer.innerHTML = '<div class="text-center text-red-500 p-4 w-full">Ett fel uppstod. Kunde inte hämta inställningar.</div>';
            }
            // Återställ till originalstorlek vid fel
            if (widget && originalWidth > 0 && originalHeight > 0) {
                widget.style.width = `${originalWidth}px`;
                widget.style.height = `${originalHeight}px`;
            }
        });
}

/**
 * Förbättrad funktion för att anpassa widgetens storlek till innehållet
 */
function resizeWidgetToFitContent(id) {
    const widget = document.getElementById(`widget-${id}`);
    const resultContainer = document.getElementById(`groups-result-${id}`);
    
    if (!widget || !resultContainer) return;
    
    // Hämta aktuell widgetstorlek
    const currentWidth = parseInt(widget.style.width) || widget.offsetWidth;
    const currentHeight = parseInt(widget.style.height) || widget.offsetHeight;
    
    // Hämta innehållsstorlek
    const contentHeight = resultContainer.scrollHeight;
    const contentWidth = resultContainer.scrollWidth;
    
    // Beräkna extra utrymme som behövs (header, knapp, padding)
    const headerHeight = 40; // Uppskattad höjd på headern
    const buttonArea = 80; // Område för "Skapa grupper"-knappen
    const padding = 30; // Total padding
    
    // Beräkna minsta nödvändiga storlek för innehållet
    const minContentWidth = contentWidth + padding;
    const minContentHeight = contentHeight + headerHeight + buttonArea + padding;
    
    // Behåll den större av befintlig eller minsta nödvändiga storlek
    // Detta förhindrar att widgeten krymper om användaren manuellt har förstorat den
    const newWidth = Math.max(currentWidth, minContentWidth, 350); // Minst 350px bredd
    const newHeight = Math.max(currentHeight, minContentHeight, 250); // Minst 250px höjd
    
    console.log(`Widget ${id} size: Current ${currentWidth}x${currentHeight}, Required ${minContentWidth}x${minContentHeight}, New ${newWidth}x${newHeight}`);
    
    // Endast uppdatera om storleken faktiskt behöver ändras
    if (newWidth !== currentWidth || newHeight !== currentHeight) {
        // Uppdatera widget och spara i databasen
        widget.style.width = `${newWidth}px`;
        widget.style.height = `${newHeight}px`;
        
        // Uppdatera i databasen
        updateWidgetSize(id, newWidth, newHeight);
    }
}

/**
 * Direktkoppling för "Redigera namn"-knappen
 */
function setupEditNameButtonDirect() {
    const buttons = document.querySelectorAll('[id^="edit-names-btn-"]');
    
    buttons.forEach(button => {
        const id = button.id.replace('edit-names-btn-', '');
        
        // Ta bort den befintliga onclick-hanteraren och lägg till en direktkoppling
        button.removeAttribute('onclick');
        
        button.addEventListener('click', function(e) {
            e.preventDefault();
            if (typeof window.openGroupEditModal === 'function') {
                window.openGroupEditModal(id);
            } else {
                console.error('openGroupEditModal function not found');
            }
        });
    });
}

// Kör setup när DOM är redo
document.addEventListener('DOMContentLoaded', function() {
    setupEditNameButtonDirect();
});

/**
 * Rensar gruppresultat
 */
function clearGroupResults(id) {
    const resultContainer = document.getElementById(`groups-result-${id}`);
    if (resultContainer) {
        resultContainer.innerHTML = '<div class="text-center text-gray-500 p-4 w-full">Klicka på "Skapa grupper" för att generera nya grupper.</div>';
    }
}

/**
 * Kopierar grupper till urklipp
 */
function saveGroupsToClipboard(id) {
    const resultContainer = document.getElementById(`groups-result-${id}`);
    if (!resultContainer) return;
    
    // Hämta alla grupper från DOM
    const groups = [];
    const groupElements = resultContainer.querySelectorAll('.bg-blue-50');
    
    groupElements.forEach(groupEl => {
        const titleEl = groupEl.querySelector('.font-bold');
        const namesEl = groupEl.querySelector('.text-gray-700');
        
        if (titleEl && namesEl) {
            const title = titleEl.textContent.split('(')[0].trim();
            const names = namesEl.textContent.trim();
            groups.push(`${title}:\n${names}`);
        }
    });
    
    if (groups.length === 0) {
        showNotification('Inga grupper att kopiera', 'error');
        return;
    }
    
    // Skapa text för urklipp
    const clipboardText = groups.join('\n\n');
    
    // Kopiera till urklipp
    if (navigator.clipboard) {
        navigator.clipboard.writeText(clipboardText)
            .then(() => {
                showNotification('Grupper kopierade till urklipp!', 'success');
            })
            .catch(err => {
                console.error('Kunde inte kopiera till urklipp:', err);
                showNotification('Kunde inte kopiera till urklipp', 'error');
            });
    } else {
        // Fallback för äldre webbläsare
        const textarea = document.createElement('textarea');
        textarea.value = clipboardText;
        textarea.style.position = 'fixed';
        textarea.style.opacity = 0;
        document.body.appendChild(textarea);
        textarea.select();
        
        try {
            const successful = document.execCommand('copy');
            if (successful) {
                showNotification('Grupper kopierade till urklipp!', 'success');
            } else {
                showNotification('Kunde inte kopiera till urklipp', 'error');
            }
        } catch (err) {
            console.error('Kunde inte kopiera till urklipp:', err);
            showNotification('Kunde inte kopiera till urklipp', 'error');
        }
        
        document.body.removeChild(textarea);
    }
}

/**
 * Öppnar en modal med avancerade inställningar
 */
window.openGroupSettings = function(id) {
    // Hämta aktuella inställningar
    fetch(`/api/get-widget-settings.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            const settings = data.settings || {};
            
            // Skapa modal
            const modal = document.createElement('div');
            modal.id = 'group-settings-modal';
            modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center';
            modal.style.zIndex = '10000';
            
            modal.innerHTML = `
                <div class="bg-white p-6 rounded-lg shadow-xl w-96 max-w-[90%]" onclick="event.stopPropagation();">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold">Avancerade inställningar</h3>
                        <button id="close-settings-btn" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Grupperingsmetod</label>
                            <select id="group-method-select" class="w-full p-2 border rounded">
                                <option value="count" ${(settings.groupMethod || 'count') === 'count' ? 'selected' : ''}>Antal grupper</option>
                                <option value="size" ${(settings.groupMethod || 'count') === 'size' ? 'selected' : ''}>Antal per grupp</option>
                            </select>
                        </div>
                        
                        <div id="group-count-container">
                            <label class="block text-sm font-medium mb-1">Antal grupper</label>
                            <input type="number" id="settings-group-count" class="w-full p-2 border rounded" 
                                   value="${settings.groupCount || 2}" min="2">
                        </div>
                        
                        <div id="group-size-container" class="hidden">
                            <label class="block text-sm font-medium mb-1">Antal per grupp</label>
                            <input type="number" id="settings-group-size" class="w-full p-2 border rounded" 
                                   value="${settings.groupSize || 2}" min="2">
                        </div>
                        
                        <div class="flex items-center">
                            <input type="checkbox" id="shuffle-names" class="mr-2" ${settings.shuffle !== false ? 'checked' : ''}>
                            <label for="shuffle-names" class="text-sm">Blanda namn</label>
                        </div>
                        
                        <div class="flex items-center">
                            <input type="checkbox" id="balance-groups" class="mr-2" ${settings.balance !== false ? 'checked' : ''}>
                            <label for="balance-groups" class="text-sm">Balansera gruppstorlekar</label>
                        </div>
                        
                        <div class="pt-4 flex justify-end">
                            <button id="save-settings-btn" class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600">
                                Spara
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // Lägg till i dokumentet
            document.body.appendChild(modal);
            
            // Sätt vår flagga för att förhindra fullscreen exit
            window.modalDialogActive = true;
            
            // Visa/dölj rätt container baserat på metod
            const methodSelect = document.getElementById('group-method-select');
            const countContainer = document.getElementById('group-count-container');
            const sizeContainer = document.getElementById('group-size-container');
            
            methodSelect.addEventListener('change', function() {
                if (this.value === 'count') {
                    countContainer.classList.remove('hidden');
                    sizeContainer.classList.add('hidden');
                } else {
                    countContainer.classList.add('hidden');
                    sizeContainer.classList.remove('hidden');
                }
            });
            
            // Trigga initial ändring för att sätta rätt läge
            const changeEvent = new Event('change');
            methodSelect.dispatchEvent(changeEvent);
            
            // Hantera stängning
            const safeCloseModal = function(save = false) {
                if (save) {
                    // Spara inställningar
                    const method = document.getElementById('group-method-select').value;
                    const groupCount = parseInt(document.getElementById('settings-group-count').value) || 2;
                    const groupSize = parseInt(document.getElementById('settings-group-size').value) || 2;
                    const shuffle = document.getElementById('shuffle-names').checked;
                    const balance = document.getElementById('balance-groups').checked;
                    
                    updateWidgetSettings(id, {
                        groupMethod: method,
                        groupCount: groupCount,
                        groupSize: groupSize,
                        shuffle: shuffle,
                        balance: balance
                    });
                    
                    // VIKTIG ÄNDRING: Uppdatera synligt antal i widgeten
                    const visibleCountInput = document.getElementById(`group-count-${id}`);
                    if (visibleCountInput && method === 'count') {
                        visibleCountInput.value = groupCount;
                    }
                    
                    showNotification('Inställningar sparade!', 'success');
                }
                
                modal.remove();
                window.modalDialogActive = false;
            };
            
            // Hantera stängningsknapp
            document.getElementById('close-settings-btn').addEventListener('click', function() {
                safeCloseModal(false);
            });
            
            // Hantera sparaknapp
            document.getElementById('save-settings-btn').addEventListener('click', function() {
                safeCloseModal(true);
            });
            
            // Hantera klick utanför
            modal.addEventListener('click', function(event) {
                if (event.target === this) {
                    safeCloseModal(false);
                }
            });
            
            // Escape key handler
            const escHandler = function(e) {
                if (e.key === 'Escape') {
                    safeCloseModal(false);
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
        })
        .catch(error => {
            console.error('Fel vid hämtning av inställningar:', error);
            showNotification('Kunde inte öppna inställningar. Försök igen.', 'error');
        });
};

/**
 * Öppnar modal för redigering av namn med förbättrad z-index
 */
window.openGroupEditModal = function(id) {
    console.log("openGroupEditModal called for id:", id);
    
    // Set our flag to prevent fullscreen exit attempts during dialog interactions
    window.modalDialogActive = true;
    
    // Hämta widget-inställningar
    fetch(`/api/get-widget-settings.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            console.log("Settings data received:", data);
            
            // Extrahera namn
            const names = data.settings && data.settings.encodedNames 
                ? decodeURIComponent(escape(window.atob(data.settings.encodedNames))) 
                : '';
            
            // Ta bort eventuell befintlig modal först
            const existingModal = document.getElementById('group-modal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Skapa modal-elementet med högre z-index
            const modal = document.createElement('div');
            modal.id = 'group-modal';
            modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[10000]'; // Mycket högre z-index
            
            // Enklare, renare modal
            modal.innerHTML = `
                <div class="bg-white p-6 rounded-lg shadow-xl w-96 max-w-[90%]" onclick="event.stopPropagation();">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold">Redigera namn</h3>
                        <button id="close-names-btn" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    
                    <p class="text-sm text-gray-600 mb-2">Skriv ett namn per rad:</p>
                    <textarea id="group-names-textarea" class="w-full h-48 p-2 border rounded mb-4" placeholder="Skriv namn, en per rad...">${names}</textarea>
                    
                    <div class="flex justify-end gap-2">
                        <button id="modal-cancel-btn" class="px-4 py-2 bg-gray-200 rounded hover:bg-gray-300">Avbryt</button>
                        <button id="modal-save-btn" class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600">Spara</button>
                    </div>
                </div>
            `;
            
            // Lägg till modalen i body
            document.body.appendChild(modal);
            
            // Function to safely close the modal
            const safeCloseModal = function(save = false) {
                if (save) {
                    const names = document.getElementById('group-names-textarea').value;
                    
                    // Spara inställningarna (behåll befintliga inställningar, ändra bara namn)
                    updateWidgetSettings(id, { 
                        encodedNames: window.btoa(unescape(encodeURIComponent(names)))
                    });
                    
                    // Visa bekräftelse
                    showNotification('Namnen har sparats!', 'success');
                }
                
                modal.remove();
                window.modalDialogActive = false;
            };
            
            // Hantera stängningsknapp
            document.getElementById('close-names-btn').addEventListener('click', function() {
                safeCloseModal(false);
            });
            
            // Hantera stängning vid klick utanför
            modal.addEventListener('click', function(event) {
                if (event.target === this) {
                    safeCloseModal(false);
                }
            });
            
            // Lägg till klickhändelser för knapparna
            document.getElementById('modal-cancel-btn').addEventListener('click', function() {
                safeCloseModal(false);
            });
            
            document.getElementById('modal-save-btn').addEventListener('click', function() {
                safeCloseModal(true);
            });
            
            // Lägg till escape key handler
            const escHandler = function(e) {
                if (e.key === 'Escape') {
                    safeCloseModal(false);
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
            
            // Sätt fokus på textområdet
            setTimeout(() => {
                const textarea = document.getElementById('group-names-textarea');
                if (textarea) textarea.focus();
            }, 100);
        })
        .catch(error => {
            console.error('Fel vid hämtning av inställningar:', error);
            window.modalDialogActive = false;
            showNotification('Kunde inte öppna namnredigeringen. Försök igen.', 'error');
        });
};

/**
 * Stänger modalen och sparar vid behov - kompabilitet med äldre kod
 */
function closeGroupModal(save, id) {
    console.log("closeGroupModal called with save:", save, "id:", id);
    
    if (save && id) {
        // Hämta namn från textarea
        const namesElement = document.getElementById(`temp-names-${id}`) || document.getElementById('group-names-textarea');
        if (!namesElement) {
            console.error('Kunde inte hitta textarean för namn');
            return;
        }
        
        const names = namesElement.value;
        
        // Hämta nya inställningar
        const methodSelect = document.getElementById(`group-method-${id}`);
        const groupCountInput = document.getElementById(`group-count-${id}`);
        const groupSizeInput = document.getElementById(`group-size-${id}`);
        
        const method = methodSelect ? methodSelect.value : 'count';
        const groupCount = groupCountInput ? (parseInt(groupCountInput.value) || 2) : 2;
        const groupSize = groupSizeInput ? (parseInt(groupSizeInput.value) || 2) : 2;
        
        console.log("Saving names:", names.substring(0, 20) + "...");
        
        // Spara inställningarna
        updateWidgetSettings(id, { 
            encodedNames: window.btoa(unescape(encodeURIComponent(names))),
            groupMethod: method,
            groupCount: groupCount,
            groupSize: groupSize
        });
        
        // Visa bekräftelse
        showNotification('Namnen har sparats!', 'success');
    }
    
    // Ta bort modalen
    const modal = document.getElementById('group-modal');
    if (modal) {
        modal.remove();
    }
    
    // Återställ fullscreen state
    window.modalDialogActive = false;
}

function renderQRCode(id, settings) {
    const widget = document.getElementById(`widget-${id}`);
    if (!widget) return;
    
    const container = widget.querySelector('.widget-container');
    if (!container) return;
    
    // Get the widget's dimensions
    const widgetWidth = widget.offsetWidth;
    const widgetHeight = widget.offsetHeight;
    // Calculate the QR size (use the smaller of width/height to maintain aspect ratio)
    const qrSize = Math.min(widgetWidth, widgetHeight) - 40; // Subtract padding
    
    const url = settings.url || '';
    const isHidden = settings.isHidden || false;
    const fullUrl = url.startsWith('http') ? url : 'https://' + url;
    
    container.innerHTML = `
    <div class="widget-header bg-green-500 text-white p-2 rounded-t-lg flex justify-between items-center">
        <span class="text-sm font-medium">QR-kod</span>
        <div class="flex space-x-2">
            <button onclick="saveQRUrl(${id})" class="text-white hover:text-blue-200">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
            </button>
            ${url ? `
            <button onclick="toggleQRVisibility(${id})" class="text-white hover:text-blue-200">
                ${isHidden ? `
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                ` : `
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                    <line x1="1" y1="1" x2="23" y2="23"/>
                </svg>
                `}
            </button>` : ''}
            <button onclick="removeWidget(${id})" class="text-white hover:text-red-200">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
    </div>
    <div class="scalable-content w-full h-full flex flex-col items-center justify-center p-4 relative">
        <div class="qr-container w-full h-full flex items-center justify-center">
            ${url ? `
            <div class="relative cursor-help group w-full h-full flex items-center justify-center">
                <a href="${fullUrl}" target="_blank" class="w-full h-full flex items-center justify-center">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=${qrSize}x${qrSize}&data=${encodeURIComponent(fullUrl)}"
                         class="w-full h-full object-contain ${isHidden ? 'hidden' : ''} pointer-events-none" 
                         alt="QR Code">
                </a>
                <div class="fixed opacity-0 group-hover:opacity-100 transition-opacity bg-black text-white p-2 rounded text-sm whitespace-nowrap pointer-events-none"
                     style="transform: translate(-50%, -100%); left: var(--tooltip-x); top: var(--tooltip-y);">
                    ${fullUrl}
                </div>
            </div>` : ''}
        </div>
    </div>`;
}

// Uppdaterad saveQRUrl-funktion
function saveQRUrl(id) {
    getWidgetSettings(id, function(settings) {
        const currentUrl = settings.url || '';
        showPromptDialog("Ange URL för QR-koden:", currentUrl, function(url) {
            if (url !== null) {
                const settings = { url: url };
                updateWidgetSettings(id, settings);
                renderQRCode(id, settings);
            }
        });
    });
}

function toggleQRVisibility(id) {
    getWidgetSettings(id, function(settings) {
        const isHidden = settings.isHidden || false;
        settings.isHidden = !isHidden;
        updateWidgetSettings(id, settings);
        renderQRCode(id, settings);
    });
}

function renderYouTubeWidget(id, settings) {
    const widget = document.getElementById(`widget-${id}`);
    if (!widget) return;
    
    const container = widget.querySelector('.widget-container');
    if (!container) return;
    
    const url = settings.url || '';
    const isHidden = settings.isHidden || false;
    const videoId = getYouTubeId(url);
    
    container.innerHTML = `
    <div class="widget-header bg-red-500 text-white p-2 rounded-t-lg flex justify-between items-center">
        <span class="text-sm font-medium">YouTube</span>
        <div class="flex space-x-2">
            <button onclick="saveYouTubeUrl(${id})" class="text-white hover:text-blue-200">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
            </button>
            ${url ? `
            <button onclick="toggleYouTubeVisibility(${id})" class="text-white hover:text-blue-200">
                ${isHidden ? `
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                ` : `
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                    <line x1="1" y1="1" x2="23" y2="23"/>
                </svg>
                `}
            </button>` : ''}
            <button onclick="removeWidget(${id})" class="text-white hover:text-red-200">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
    </div>
    <div class="scalable-content w-full h-full flex flex-col items-center justify-center p-4">
        <div class="youtube-container w-full h-full flex items-center justify-center">
            ${videoId ? `
            <div class="relative w-full h-full">
                <div class="${isHidden ? 'hidden' : ''}">
                    <div class="youtube-thumbnail relative cursor-pointer" onclick="playVideo(${id})">
                        <img src="https://img.youtube.com/vi/${videoId}/mqdefault.jpg" 
                             class="w-full h-full object-cover"
                             alt="YouTube Thumbnail">
                        <div class="absolute inset-0 flex items-center justify-center">
                            <svg class="w-12 h-12 text-red-500" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M8 5v14l11-7z"/>
                            </svg>
                        </div>
                    </div>
                    <iframe 
                        class="w-full h-full hidden"
                        src="https://www.youtube.com/embed/${videoId}?enablejsapi=1" 
                        frameborder="0" 
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                        allowfullscreen>
                    </iframe>
                </div>
            </div>` : ''}
        </div>
    </div>`;
}

// Uppdaterad saveYouTubeUrl-funktion
function saveYouTubeUrl(id) {
    getWidgetSettings(id, function(settings) {
        const currentUrl = settings.url || '';
        showPromptDialog("Ange YouTube-länken:", currentUrl, function(url) {
            if (url !== null) {
                const settings = { url: url };
                updateWidgetSettings(id, settings);
                renderYouTubeWidget(id, settings);
            }
        });
    });
}

function toggleYouTubeVisibility(id) {
    getWidgetSettings(id, function(settings) {
        const isHidden = settings.isHidden || false;
        settings.isHidden = !isHidden;
        
        // Stoppa videon om den spelas när den döljs
        if (!isHidden) {
            const iframe = document.querySelector(`#widget-${id} iframe`);
            if (iframe && !iframe.classList.contains('hidden')) {
                iframe.contentWindow.postMessage('{"event":"command","func":"stopVideo","args":""}', '*');
            }
        }
        
        updateWidgetSettings(id, settings);
        renderYouTubeWidget(id, settings);
    });
}

function playVideo(id) {
    const widget = document.getElementById(`widget-${id}`);
    const thumbnail = widget.querySelector('.youtube-thumbnail');
    const iframe = widget.querySelector('iframe');
    
    if (thumbnail && iframe) {
        thumbnail.style.display = 'none';
        iframe.classList.remove('hidden');
        iframe.contentWindow.postMessage('{"event":"command","func":"playVideo","args":""}', '*');
    }
}

function getYouTubeId(url) {
    if (!url) return '';
    
    const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/;
    const match = url.match(regExp);
    
    return (match && match[2].length === 11) ? match[2] : '';
}

// Image widget functions
function saveImageUrl(id) {
    getWidgetSettings(id, function(settings) {
        const currentUrl = settings.url || '';
        showPromptDialog("Ange URL för bilden:", currentUrl, function(url) {
            if (url !== null) {
                const settings = { url: url };
                updateWidgetSettings(id, settings);
                
                // Reload widget content
                loadWidgetContent({
                    id: id,
                    type: 'image'
                });
            }
        });
    });
}

function toggleImageVisibility(id) {
    getWidgetSettings(id, function(settings) {
        const isHidden = settings.isHidden || false;
        settings.isHidden = !isHidden;
        updateWidgetSettings(id, settings);
        
        // Reload widget content
        loadWidgetContent({
            id: id,
            type: 'image'
        });
    });
}

// ---- Inbäddnings-widget (Google Presentation m.m.) ----
function saveEmbedUrl(id) {
    getWidgetSettings(id, function(settings) {
        const currentUrl = settings.url || '';
        showPromptDialog("Klistra in länk att bädda in (t.ex. en Google Presentation):", currentUrl, function(url) {
            if (url !== null) {
                const newSettings = { url: (url || '').trim() };
                updateWidgetSettings(id, newSettings);
                loadWidgetContent({ id: id, type: 'embed' });
            }
        });
    });
}

// ---- PDF-widget (skriva på PDF och spara anteckningar) ----
window.pdfWidgets = window.pdfWidgets || {};
// PDF.js v3.11.174 buntas lokalt (assets/vendor/pdfjs) för on-prem-drift utan internet.
const PDFJS_LIB_URL = '/assets/vendor/pdfjs/pdf.js';
const PDFJS_WORKER_URL = '/assets/vendor/pdfjs/pdf.worker.js';

function loadPdfJs(callback) {
    if (window.pdfjsLib) { callback(); return; }
    if (window._pdfJsLoading) { window._pdfJsLoading.push(callback); return; }
    window._pdfJsLoading = [callback];
    const script = document.createElement('script');
    script.src = PDFJS_LIB_URL;
    script.onload = function() {
        try {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER_URL;
        } catch (e) {}
        const cbs = window._pdfJsLoading || [];
        window._pdfJsLoading = null;
        cbs.forEach(cb => cb());
    };
    script.onerror = function() {
        window._pdfJsLoading = null;
        console.error('Kunde inte ladda PDF.js (assets/vendor/pdfjs/pdf.js).');
    };
    document.head.appendChild(script);
}

function initPdfWidget(id) {
    const root = document.getElementById(`pdf-widget-${id}`);
    if (!root) return;
    const state = window.pdfWidgets[id] || (window.pdfWidgets[id] = {});
    state.id = id;
    state.tool = state.tool || 'pen';
    state.color = state.color || '#1f2937';
    state.penSize = 3;
    state.eraserSize = 24;
    state.highlighterSize = 18;
    state.textSize = 28;
    state.pageNum = state.pageNum || 1;
    state.drawing = false;
    state.currentStroke = null;

    pdfBindDrawing(id);

    getWidgetSettings(id, function(settings) {
        state.pdfUrl = settings.pdfUrl || root.getAttribute('data-pdf-url') || '';
        state.annotations = settings.annotations || {};
        if (Array.isArray(state.annotations)) state.annotations = {};
        pdfUpdateToolUI(id);
        if (state.pdfUrl) {
            pdfLoadDocument(id, state.pdfUrl);
        }
    });
}

function pdfBindDrawing(id) {
    const root = document.getElementById(`pdf-widget-${id}`);
    if (!root) return;
    const draw = root.querySelector('.pdf-draw');
    if (!draw || draw._pdfBound) return;
    draw._pdfBound = true;

    const getPos = (e) => {
        const rect = draw.getBoundingClientRect();
        return {
            x: (e.clientX - rect.left) * (draw.width / rect.width),
            y: (e.clientY - rect.top) * (draw.height / rect.height)
        };
    };

    draw.addEventListener('pointerdown', (e) => {
        const state = window.pdfWidgets[id];
        if (!state || !state.pdfUrl) return;
        e.preventDefault();
        const p = getPos(e);

        // Textverktyg: klicka för att placera text, ingen penseldragning
        if (state.tool === 'text') {
            showPromptDialog("Skriv text:", "", function(txt) {
                if (txt !== null && txt.trim() !== '') {
                    if (!state.annotations[state.pageNum]) state.annotations[state.pageNum] = [];
                    state.annotations[state.pageNum].push({
                        text: txt,
                        x: Math.round(p.x),
                        y: Math.round(p.y),
                        color: state.color,
                        fontSize: state.textSize
                    });
                    pdfRedraw(id);
                    pdfScheduleSave(id);
                }
            });
            return;
        }

        try { draw.setPointerCapture(e.pointerId); } catch (err) {}
        state.drawing = true;
        let size = state.penSize;
        if (state.tool === 'eraser') size = state.eraserSize;
        else if (state.tool === 'highlighter') size = state.highlighterSize;
        state.currentStroke = {
            color: state.color,
            size: size,
            eraser: state.tool === 'eraser',
            highlighter: state.tool === 'highlighter',
            alpha: state.tool === 'highlighter' ? 0.35 : 1,
            points: [[Math.round(p.x), Math.round(p.y)]]
        };
    });

    draw.addEventListener('pointermove', (e) => {
        const state = window.pdfWidgets[id];
        if (!state || !state.drawing || !state.currentStroke) return;
        e.preventDefault();
        const p = getPos(e);
        state.currentStroke.points.push([Math.round(p.x), Math.round(p.y)]);
        const ctx = draw.getContext('2d');
        if (state.currentStroke.highlighter) {
            // Rita om hela draget så att transparensen blir jämn (inga mörka skarvar)
            pdfRedraw(id);
            pdfDrawItem(ctx, state.currentStroke);
        } else {
            pdfDrawLiveSegment(ctx, state.currentStroke);
        }
    });

    const endStroke = () => {
        const state = window.pdfWidgets[id];
        if (!state || !state.drawing) return;
        state.drawing = false;
        if (state.currentStroke && state.currentStroke.points.length) {
            if (!state.annotations[state.pageNum]) state.annotations[state.pageNum] = [];
            state.annotations[state.pageNum].push(state.currentStroke);
            pdfScheduleSave(id);
        }
        state.currentStroke = null;
    };
    draw.addEventListener('pointerup', endStroke);
    draw.addEventListener('pointercancel', endStroke);
}

function pdfDrawLiveSegment(ctx, stroke) {
    const pts = stroke.points;
    ctx.save();
    ctx.globalCompositeOperation = stroke.eraser ? 'destination-out' : 'source-over';
    ctx.strokeStyle = stroke.color;
    ctx.fillStyle = stroke.color;
    ctx.lineWidth = stroke.size;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    if (pts.length < 2) {
        ctx.beginPath();
        ctx.arc(pts[0][0], pts[0][1], stroke.size / 2, 0, Math.PI * 2);
        ctx.fill();
    } else {
        const a = pts[pts.length - 2], b = pts[pts.length - 1];
        ctx.beginPath();
        ctx.moveTo(a[0], a[1]);
        ctx.lineTo(b[0], b[1]);
        ctx.stroke();
    }
    ctx.restore();
}

function pdfDrawItem(ctx, item) {
    // Textannotering
    if (item.text !== undefined) {
        ctx.save();
        ctx.globalCompositeOperation = 'source-over';
        ctx.fillStyle = item.color;
        ctx.font = `${item.fontSize || 28}px Arial, Helvetica, sans-serif`;
        ctx.textBaseline = 'top';
        const lines = String(item.text).split('\n');
        lines.forEach((line, i) => ctx.fillText(line, item.x, item.y + i * (item.fontSize || 28) * 1.2));
        ctx.restore();
        return;
    }

    // Penseldrag (penna / markeringspenna / sudd)
    const pts = item.points;
    if (!pts || !pts.length) return;
    ctx.save();
    ctx.globalCompositeOperation = item.eraser ? 'destination-out' : 'source-over';
    ctx.globalAlpha = item.highlighter ? (item.alpha || 0.35) : 1;
    ctx.strokeStyle = item.color;
    ctx.fillStyle = item.color;
    ctx.lineWidth = item.size;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    if (pts.length === 1) {
        ctx.beginPath();
        ctx.arc(pts[0][0], pts[0][1], item.size / 2, 0, Math.PI * 2);
        ctx.fill();
    } else {
        ctx.beginPath();
        ctx.moveTo(pts[0][0], pts[0][1]);
        for (let i = 1; i < pts.length; i++) ctx.lineTo(pts[i][0], pts[i][1]);
        ctx.stroke();
    }
    ctx.restore();
}

function pdfRedraw(id) {
    const root = document.getElementById(`pdf-widget-${id}`);
    if (!root) return;
    const draw = root.querySelector('.pdf-draw');
    if (!draw) return;
    const ctx = draw.getContext('2d');
    ctx.clearRect(0, 0, draw.width, draw.height);
    const state = window.pdfWidgets[id];
    const items = (state && state.annotations && state.annotations[state.pageNum]) || [];
    items.forEach(item => pdfDrawItem(ctx, item));
}

function pdfLoadDocument(id, url) {
    const root = document.getElementById(`pdf-widget-${id}`);
    if (!root) return;
    const loading = root.querySelector('.pdf-loading');
    const empty = root.querySelector('.pdf-empty');
    const wrap = root.querySelector('.pdf-canvas-wrap');
    if (empty) empty.classList.add('hidden');
    if (loading) loading.classList.remove('hidden');

    loadPdfJs(function() {
        window.pdfjsLib.getDocument(url).promise.then(function(doc) {
            const state = window.pdfWidgets[id];
            if (!state) return;
            state.pdfDoc = doc;
            state.numPages = doc.numPages;
            if (state.pageNum > state.numPages) state.pageNum = 1;
            if (loading) loading.classList.add('hidden');
            if (wrap) wrap.style.display = '';
            pdfRenderPage(id);
        }).catch(function(err) {
            console.error('Fel vid laddning av PDF:', err);
            if (loading) loading.classList.add('hidden');
            if (empty) empty.classList.remove('hidden');
        });
    });
}

function pdfRenderPage(id) {
    const root = document.getElementById(`pdf-widget-${id}`);
    const state = window.pdfWidgets[id];
    if (!root || !state || !state.pdfDoc) return;
    state.pdfDoc.getPage(state.pageNum).then(function(page) {
        const viewport = page.getViewport({ scale: 1.5 });
        const base = root.querySelector('.pdf-base');
        const draw = root.querySelector('.pdf-draw');
        base.width = viewport.width;
        base.height = viewport.height;
        draw.width = viewport.width;
        draw.height = viewport.height;
        const ctx = base.getContext('2d');
        page.render({ canvasContext: ctx, viewport: viewport }).promise.then(function() {
            pdfRedraw(id);
        });
        pdfUpdatePageIndicator(id);
    });
}

function pdfUpdatePageIndicator(id) {
    const root = document.getElementById(`pdf-widget-${id}`);
    const state = window.pdfWidgets[id];
    if (!root || !state) return;
    const el = root.querySelector('.pdf-page-indicator');
    if (el) el.textContent = (state.numPages ? `${state.pageNum} / ${state.numPages}` : '–');
}

function pdfUpdateToolUI(id) {
    const root = document.getElementById(`pdf-widget-${id}`);
    const state = window.pdfWidgets[id];
    if (!root || !state) return;
    root.querySelectorAll('.pdf-tool-btn').forEach(btn => {
        btn.classList.toggle('bg-gray-300', btn.getAttribute('data-tool') === state.tool);
    });
    const colorTool = (state.tool === 'pen' || state.tool === 'highlighter' || state.tool === 'text');
    root.querySelectorAll('.pdf-color-btn').forEach(btn => {
        const active = btn.getAttribute('data-color') === state.color && colorTool;
        btn.style.outline = active ? '2px solid #111827' : 'none';
        btn.style.outlineOffset = '1px';
    });
}

function pdfSetTool(id, tool) {
    const state = window.pdfWidgets[id];
    if (!state) return;
    state.tool = tool;
    const root = document.getElementById(`pdf-widget-${id}`);
    const draw = root && root.querySelector('.pdf-draw');
    if (draw) draw.style.cursor = (tool === 'text') ? 'text' : 'crosshair';
    pdfUpdateToolUI(id);
}

function pdfSetColor(id, color) {
    const state = window.pdfWidgets[id];
    if (!state) return;
    state.color = color;
    // Behåll markeringspenna/text om de är aktiva, byt annars till penna
    if (state.tool === 'eraser') state.tool = 'pen';
    pdfUpdateToolUI(id);
}

function pdfClearPage(id) {
    const state = window.pdfWidgets[id];
    if (!state) return;
    state.annotations[state.pageNum] = [];
    pdfRedraw(id);
    pdfScheduleSave(id);
}

function pdfPrevPage(id) {
    const state = window.pdfWidgets[id];
    if (!state || !state.pdfDoc || state.pageNum <= 1) return;
    state.pageNum--;
    pdfRenderPage(id);
}

function pdfNextPage(id) {
    const state = window.pdfWidgets[id];
    if (!state || !state.pdfDoc || state.pageNum >= state.numPages) return;
    state.pageNum++;
    pdfRenderPage(id);
}

function pdfScheduleSave(id) {
    const state = window.pdfWidgets[id];
    if (!state) return;
    if (state.saveTimer) clearTimeout(state.saveTimer);
    state.saveTimer = setTimeout(function() {
        updateWidgetSettings(id, {
            pdfUrl: state.pdfUrl || '',
            annotations: state.annotations || {}
        });
    }, 700);
}

function pdfUpload(id) {
    const root = document.getElementById(`pdf-widget-${id}`);
    if (!root) return;
    const input = root.querySelector('.pdf-file-input');
    if (!input) return;
    input.value = '';
    input.onchange = function() {
        const file = input.files && input.files[0];
        if (!file) return;
        const formData = new FormData();
        formData.append('pdf', file);
        formData.append('widget_id', id);
        const loading = root.querySelector('.pdf-loading');
        const empty = root.querySelector('.pdf-empty');
        if (empty) empty.classList.add('hidden');
        if (loading) loading.classList.remove('hidden');
        fetch('/api/upload-pdf.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.url) {
                    const state = window.pdfWidgets[id] || (window.pdfWidgets[id] = {});
                    state.pdfUrl = data.url;
                    state.annotations = {};
                    state.pageNum = 1;
                    root.setAttribute('data-pdf-url', data.url);
                    updateWidgetSettings(id, { pdfUrl: data.url, annotations: {} });
                    pdfLoadDocument(id, data.url);
                } else {
                    if (loading) loading.classList.add('hidden');
                    alert('Kunde inte ladda upp PDF: ' + (data.error || 'okänt fel'));
                }
            })
            .catch(err => {
                if (loading) loading.classList.add('hidden');
                console.error(err);
                alert('Kunde inte ladda upp PDF.');
            });
    };
    input.click();
}

// Custom prompt dialog that won't disrupt fullscreen
function showPromptDialog(message, defaultValue, onSubmit, onCancel) {
    // Create the modal element if it doesn't exist yet
    let modal = document.getElementById('custom-prompt-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'custom-prompt-modal';
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[9999]';
        document.body.appendChild(modal);
    }
    
    // Set the content of the modal
    modal.innerHTML = `
        <div class="bg-white p-6 rounded-lg max-w-md w-full shadow-xl">
            <h3 class="text-lg font-bold mb-4">Input</h3>
            <p class="mb-4">${message}</p>
            <input type="text" id="prompt-input" class="w-full p-2 border rounded mb-4" value="${defaultValue || ''}">
            <div class="flex justify-end space-x-4">
                <button id="prompt-cancel-btn" class="px-4 py-2 bg-gray-400 text-white rounded hover:bg-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400">
                    Avbryt
                </button>
                <button id="prompt-ok-btn" class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    OK
                </button>
            </div>
        </div>
    `;
    
    // Show the modal
    modal.style.display = 'flex';
    
    // Focus the input field
    setTimeout(() => {
        const input = document.getElementById('prompt-input');
        if (input) {
            input.focus();
            input.select();
        }
    }, 100);
    
    // Set up button event handlers
    document.getElementById('prompt-cancel-btn').onclick = function() {
        modal.style.display = 'none';
        if (onCancel) onCancel();
    };
    
    document.getElementById('prompt-ok-btn').onclick = function() {
        const value = document.getElementById('prompt-input').value;
        modal.style.display = 'none';
        if (onSubmit) onSubmit(value);
    };
    
    // Add enter key handler for input field
    document.getElementById('prompt-input').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            const value = document.getElementById('prompt-input').value;
            modal.style.display = 'none';
            if (onSubmit) onSubmit(value);
        }
    });
    
    // Add escape key handler
    const escHandler = function(e) {
        if (e.key === 'Escape') {
            modal.style.display = 'none';
            document.removeEventListener('keydown', escHandler);
            if (onCancel) onCancel();
        }
    };
    document.addEventListener('keydown', escHandler);
    
    return modal;
}

// Update CSS for prompt modal
const styleElement = document.createElement('style');
styleElement.textContent = `
#custom-prompt-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}

#custom-prompt-modal .bg-white {
    max-width: 90%;
    width: 400px;
    border-radius: 0.5rem;
    padding: 1.5rem;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    animation: modal-appear 0.3s ease-out;
}

#custom-prompt-modal h3 {
    margin-bottom: 1rem;
    font-weight: 600;
    font-size: 1.125rem;
}

#custom-prompt-modal p {
    margin-bottom: 1.5rem;
    color: #4b5563;
}

#custom-prompt-modal input {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #d1d5db;
    border-radius: 0.375rem;
    margin-bottom: 1rem;
}

#custom-prompt-modal button {
    padding: 0.5rem 1rem;
    border-radius: 0.375rem;
    font-weight: 500;
    transition: all 0.2s;
    outline: none;
}

#custom-prompt-modal button:focus {
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.5);
}

@keyframes modal-appear {
    from {
        opacity: 0;
        transform: scale(0.9);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}
`;
document.head.appendChild(styleElement);

// Add touch event handlers for better mobile support
document.addEventListener('DOMContentLoaded', function() {
    // Prevent default touch behavior when interacting with widgets
    document.addEventListener('touchstart', function(e) {
        if (e.target.closest('.widget')) {
            // Don't prevent default on interactive elements
            if (e.target.tagName.toLowerCase() === 'button' || 
                e.target.tagName.toLowerCase() === 'input' || 
                e.target.tagName.toLowerCase() === 'textarea' ||
                e.target.closest('button') ||
                e.target.closest('input') ||
                e.target.closest('textarea')) {
                return;
            }
            
            // On headers and resize handles, prevent default to allow interact.js to work
            if (e.target.closest('.widget-header') || e.target.closest('.resize-handle')) {
                e.preventDefault();
            }
        }
    }, { passive: false });
    
    // Add global handler for window resize
    let resizeTimeout;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(function() {
            // Update all widgets when window is resized
            document.querySelectorAll('.widget').forEach(function(widget) {
                updateWidgetScaling(widget);
                
                // Handle text widgets specially
                const widgetId = widget.id.replace('widget-', '');
                const textDisplay = document.getElementById(`text-display-${widgetId}`);
                if (textDisplay) {
                    maximizeTextSize(widgetId);
                }
            });
        }, 250);
    });
});

// ============================================================
// Tavelläge – större knappar för pekskärmar/smartboards
// ============================================================
function toggleBoardMode() {
    const on = document.body.classList.toggle('board-mode');
    try { localStorage.setItem('boardMode', on ? '1' : '0'); } catch (e) {}
}

// Återställ sparat läge direkt vid sidladdning
try {
    if (localStorage.getItem('boardMode') === '1') {
        document.body.classList.add('board-mode');
    }
} catch (e) {}

// ============================================================
// Namnsnurra
// ============================================================
window.nameWheels = window.nameWheels || {};

const NAMEWHEEL_COLORS = ['#f43f5e', '#f97316', '#facc15', '#22c55e', '#06b6d4', '#3b82f6', '#8b5cf6', '#ec4899'];

function initNameWheel(id) {
    const root = document.getElementById(`namewheel-widget-${id}`);
    if (!root) return;
    const names = (root.getAttribute('data-names') || '')
        .split('\n').map(s => s.trim()).filter(Boolean);
    window.nameWheels[id] = { names, angle: -Math.PI / 2, spinning: false };
    nameWheelResize(id);
    const wrap = root.querySelector('.namewheel-canvas-wrap');
    if (wrap && 'ResizeObserver' in window && !root.dataset.observed) {
        root.dataset.observed = '1';
        new ResizeObserver(() => nameWheelResize(id)).observe(wrap);
    }
}

function nameWheelResize(id) {
    const root = document.getElementById(`namewheel-widget-${id}`);
    if (!root) return;
    const wrap = root.querySelector('.namewheel-canvas-wrap');
    const canvas = root.querySelector('.namewheel-canvas');
    if (!wrap || !canvas) return;
    const size = Math.max(50, Math.min(wrap.clientWidth, wrap.clientHeight));
    canvas.width = size;
    canvas.height = size;
    canvas.style.width = size + 'px';
    canvas.style.height = size + 'px';
    canvas.style.left = ((wrap.clientWidth - size) / 2) + 'px';
    canvas.style.top = ((wrap.clientHeight - size) / 2) + 'px';
    canvas.style.right = 'auto';
    canvas.style.bottom = 'auto';
    nameWheelDraw(id);
}

function nameWheelDraw(id) {
    const root = document.getElementById(`namewheel-widget-${id}`);
    const state = window.nameWheels[id];
    if (!root || !state) return;
    const canvas = root.querySelector('.namewheel-canvas');
    const ctx = canvas.getContext('2d');
    const size = canvas.width;
    const c = size / 2;
    const radius = c - 8;
    ctx.clearRect(0, 0, size, size);
    const n = state.names.length;
    if (!n) return;

    const seg = (Math.PI * 2) / n;
    for (let i = 0; i < n; i++) {
        const a0 = state.angle + i * seg;
        ctx.beginPath();
        ctx.moveTo(c, c);
        ctx.arc(c, c, radius, a0, a0 + seg);
        ctx.closePath();
        ctx.fillStyle = NAMEWHEEL_COLORS[i % NAMEWHEEL_COLORS.length];
        ctx.fill();
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 2;
        ctx.stroke();

        // Namn längs segmentets mittlinje
        ctx.save();
        ctx.translate(c, c);
        ctx.rotate(a0 + seg / 2);
        ctx.textAlign = 'right';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#ffffff';
        const fontSize = Math.max(9, Math.min(16, radius / 7, (seg * radius) * 0.5));
        ctx.font = `bold ${fontSize}px sans-serif`;
        let label = state.names[i];
        const maxWidth = radius - 24;
        while (label.length > 2 && ctx.measureText(label).width > maxWidth) {
            label = label.slice(0, -2) + '…';
        }
        ctx.fillText(label, radius - 10, 0);
        ctx.restore();
    }

    // Nav
    ctx.beginPath();
    ctx.arc(c, c, Math.max(6, radius * 0.08), 0, Math.PI * 2);
    ctx.fillStyle = '#ffffff';
    ctx.fill();
    ctx.strokeStyle = '#d1d5db';
    ctx.stroke();

    // Pil (pekare) överst
    ctx.beginPath();
    ctx.moveTo(c - 10, 2);
    ctx.lineTo(c + 10, 2);
    ctx.lineTo(c, 22);
    ctx.closePath();
    ctx.fillStyle = '#374151';
    ctx.fill();
}

function nameWheelSpin(id) {
    const root = document.getElementById(`namewheel-widget-${id}`);
    const state = window.nameWheels[id];
    if (!root || !state || state.spinning) return;
    if (!state.names.length) { nameWheelEdit(id); return; }

    state.spinning = true;
    const resultEl = root.querySelector('.namewheel-result');
    if (resultEl) resultEl.textContent = '';

    const start = state.angle;
    const target = start + Math.PI * 2 * (4 + Math.random() * 3);
    const duration = 3500;
    const t0 = performance.now();

    function frame(now) {
        const t = Math.min(1, (now - t0) / duration);
        const ease = 1 - Math.pow(1 - t, 3);
        state.angle = start + (target - start) * ease;
        nameWheelDraw(id);
        if (t < 1) {
            requestAnimationFrame(frame);
        } else {
            state.spinning = false;
            const n = state.names.length;
            const seg = (Math.PI * 2) / n;
            // Pekaren sitter rakt uppåt (-90°); räkna ut vilket segment som ligger där
            const a = ((-Math.PI / 2 - state.angle) % (Math.PI * 2) + Math.PI * 2) % (Math.PI * 2);
            const idx = Math.floor(a / seg) % n;
            if (resultEl) resultEl.textContent = '🎉 ' + state.names[idx];
        }
    }
    requestAnimationFrame(frame);
}

function nameWheelEdit(id) {
    const root = document.getElementById(`namewheel-widget-${id}`);
    const state = window.nameWheels[id] || { names: [] };
    const current = state.names ? state.names.join('\n') : '';

    let modal = document.getElementById('namewheel-edit-modal');
    if (modal) modal.remove();

    modal = document.createElement('div');
    modal.id = 'namewheel-edit-modal';
    modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
    modal.innerHTML = `
        <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4 shadow-xl">
            <h3 class="text-lg font-bold mb-2">Redigera namn</h3>
            <p class="text-sm text-gray-500 mb-3">Ett namn per rad.</p>
            <textarea id="namewheel-edit-textarea" rows="10"
                class="w-full border border-gray-300 rounded-lg p-2 focus:border-pink-500 focus:ring-1 focus:ring-pink-500"
                placeholder="Anna&#10;Bruno&#10;Cesar"></textarea>
            <div class="flex justify-end gap-2 mt-4">
                <button id="namewheel-edit-cancel" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">Avbryt</button>
                <button id="namewheel-edit-save" class="px-4 py-2 bg-pink-500 text-white rounded-lg hover:bg-pink-600">Spara</button>
            </div>
        </div>`;
    // Läggs i fullscreen-elementet om aktivt, annars body
    (document.fullscreenElement || document.body).appendChild(modal);

    const textarea = modal.querySelector('#namewheel-edit-textarea');
    textarea.value = current;
    textarea.focus();

    modal.querySelector('#namewheel-edit-cancel').onclick = () => modal.remove();
    modal.onclick = (e) => { if (e.target === modal) modal.remove(); };
    modal.querySelector('#namewheel-edit-save').onclick = () => {
        const names = textarea.value.split('\n').map(s => s.trim()).filter(Boolean);
        modal.remove();
        if (root) {
            root.setAttribute('data-names', names.join('\n'));
            root.querySelector('.namewheel-empty')?.classList.toggle('hidden', names.length > 0);
            root.querySelector('.namewheel-spin-btn')?.classList.toggle('hidden', names.length === 0);
            const resultEl = root.querySelector('.namewheel-result');
            if (resultEl) resultEl.textContent = '';
        }
        window.nameWheels[id] = { names, angle: -Math.PI / 2, spinning: false };
        nameWheelDraw(id);
        updateWidgetSettings(id, { names: names.join('\n') });
    };
}

// ============================================================
// Tärning
// ============================================================
function initDice(id) {
    diceRender(id, null);
}

function diceRender(id, values) {
    const root = document.getElementById(`dice-widget-${id}`);
    if (!root) return;
    const row = root.querySelector('.dice-row');
    const count = parseInt(root.getAttribute('data-count')) || 2;
    row.innerHTML = '';
    for (let i = 0; i < count; i++) {
        const div = document.createElement('div');
        div.className = 'w-16 h-16 rounded-xl border-2 border-gray-300 bg-white shadow-md flex items-center justify-center text-3xl font-bold text-gray-800';
        div.textContent = values ? values[i] : '?';
        row.appendChild(div);
    }
    const total = root.querySelector('.dice-total');
    if (total) {
        total.textContent = (values && count > 1)
            ? 'Summa: ' + values.reduce((a, b) => a + b, 0)
            : '';
    }
}

function diceRoll(id) {
    const root = document.getElementById(`dice-widget-${id}`);
    if (!root || root.dataset.rolling) return;
    root.dataset.rolling = '1';
    const count = parseInt(root.getAttribute('data-count')) || 2;
    const sides = parseInt(root.getAttribute('data-sides')) || 6;
    let ticks = 10;
    const interval = setInterval(() => {
        const values = Array.from({ length: count }, () => 1 + Math.floor(Math.random() * sides));
        diceRender(id, values);
        if (--ticks <= 0) {
            clearInterval(interval);
            delete root.dataset.rolling;
        }
    }, 80);
}

function diceSetOption(id, key, value) {
    const root = document.getElementById(`dice-widget-${id}`);
    if (!root) return;
    root.setAttribute('data-' + key, value);
    diceRender(id, null);
    updateWidgetSettings(id, { [key]: parseInt(value) });
}

// ============================================================
// Stoppur
// ============================================================
window.stopwatches = window.stopwatches || {};

function stopwatchState(id) {
    return window.stopwatches[id] || (window.stopwatches[id] = {
        elapsed: 0, running: false, startedAt: 0, timer: null, laps: []
    });
}

function stopwatchNow(id) {
    const s = stopwatchState(id);
    return s.elapsed + (s.running ? Date.now() - s.startedAt : 0);
}

function stopwatchFormat(ms) {
    const totalSec = Math.floor(ms / 1000);
    const min = Math.floor(totalSec / 60);
    const sec = totalSec % 60;
    const tenths = Math.floor((ms % 1000) / 100);
    return {
        main: String(min).padStart(2, '0') + ':' + String(sec).padStart(2, '0'),
        tenths: '.' + tenths
    };
}

function stopwatchRender(id) {
    const root = document.getElementById(`stopwatch-widget-${id}`);
    if (!root) return;
    const t = stopwatchFormat(stopwatchNow(id));
    const display = root.querySelector('.stopwatch-display');
    if (display) {
        display.innerHTML = t.main + '<span class="text-2xl text-gray-500">' + t.tenths + '</span>';
    }
}

function stopwatchToggle(id) {
    const s = stopwatchState(id);
    const root = document.getElementById(`stopwatch-widget-${id}`);
    const btn = root?.querySelector('.stopwatch-toggle');
    if (s.running) {
        s.elapsed += Date.now() - s.startedAt;
        s.running = false;
        clearInterval(s.timer);
        s.timer = null;
        if (btn) {
            btn.textContent = 'Starta';
            btn.classList.remove('bg-yellow-500', 'hover:bg-yellow-600');
            btn.classList.add('bg-blue-500', 'hover:bg-blue-600');
        }
    } else {
        s.startedAt = Date.now();
        s.running = true;
        s.timer = setInterval(() => stopwatchRender(id), 100);
        if (btn) {
            btn.textContent = 'Paus';
            btn.classList.remove('bg-blue-500', 'hover:bg-blue-600');
            btn.classList.add('bg-yellow-500', 'hover:bg-yellow-600');
        }
    }
    stopwatchRender(id);
}

function stopwatchLap(id) {
    const s = stopwatchState(id);
    const now = stopwatchNow(id);
    if (now === 0) return;
    s.laps.push(now);
    const root = document.getElementById(`stopwatch-widget-${id}`);
    const list = root?.querySelector('.stopwatch-laps');
    if (list) {
        list.innerHTML = s.laps.map((ms, i) => {
            const t = stopwatchFormat(ms);
            return `<li>Varv ${i + 1}: ${t.main}${t.tenths}</li>`;
        }).reverse().join('');
    }
}

function stopwatchReset(id) {
    const s = stopwatchState(id);
    if (s.timer) clearInterval(s.timer);
    window.stopwatches[id] = { elapsed: 0, running: false, startedAt: 0, timer: null, laps: [] };
    const root = document.getElementById(`stopwatch-widget-${id}`);
    const btn = root?.querySelector('.stopwatch-toggle');
    if (btn) {
        btn.textContent = 'Starta';
        btn.classList.remove('bg-yellow-500', 'hover:bg-yellow-600');
        btn.classList.add('bg-blue-500', 'hover:bg-blue-600');
    }
    const list = root?.querySelector('.stopwatch-laps');
    if (list) list.innerHTML = '';
    stopwatchRender(id);
}
</script>
<script src="/assets/js/center-widgets.js"></script>
</body>
</html>