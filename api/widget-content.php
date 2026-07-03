<?php
session_start();
require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/board-access.php';

header('Content-Type: text/html');

$type = $_GET['type'] ?? '';
$id = $_GET['id'] ?? '';

// Get widget data from database (kontrollerar även åtkomst)
$db = new Database();
$pdo = $db->getConnection();
$widget = require_widget_access($pdo, $id);
$whiteboard = ['id' => $widget['whiteboard_id']];

$settings = json_decode($widget['settings'] ?? '{}', true) ?? [];

// Common function to render widget wrapper
function outputWidgetWrapper($type, $id, $content, $extraControls = '') {
    $baseType = explode(' ', $type)[0];
    
    $bgColors = [
        'clock' => 'bg-blue-500',
        'analog_clock' => 'bg-blue-500',
        'timer' => 'bg-blue-500',
        'text' => 'bg-purple-500',
        'groups' => 'bg-blue-500',
        'brainbreak' => 'bg-pink-500',
        'qrcode' => 'bg-green-500',
        'youtube' => 'bg-red-500',
        'todo' => 'bg-blue-500',
        'trafficlight' => 'bg-purple-500',
        'poll' => 'bg-indigo-500',
        'image' => 'bg-indigo-500',
        'embed' => 'bg-teal-500',
        'pdf' => 'bg-orange-500',
        'default' => 'bg-gray-500'
    ];
    
    $swedishNames = [
        'clock' => 'Klocka',
        'analog_clock' => 'Analog Klocka',
        'timer' => 'Timer',
        'text' => 'Text',
        'groups' => 'Grupper',
        'brainbreak' => 'Brain Break',
        'qrcode' => 'QR-kod',
        'youtube' => 'YouTube',
        'todo' => 'Att göra',
        'trafficlight' => 'Trafikljus',
        'poll' => 'Omröstning',
        'image' => 'Bild',
        'embed' => 'Inbäddning',
        'pdf' => 'PDF',
        'default' => 'Widget'
    ];
    
    $bgColor = $bgColors[$baseType] ?? $bgColors['default'];
    $displayName = $swedishNames[$baseType] ?? $swedishNames['default'];
    $innerClasses = in_array($baseType, ['timer', 'embed', 'pdf'])
        ? 'h-full w-full flex'
        : 'h-full w-full flex items-center justify-center';
    
    echo '
    <div class="widget-header ' . $bgColor . ' text-white p-2 rounded-t-lg flex justify-between items-center" style="position: relative; z-index: 2; touch-action: none;">
        <span>' . $displayName . ($type !== $baseType ? ' ' . explode(' ', $type)[1] : '') . '</span>
        <div class="flex items-center space-x-2">
            ' . $extraControls . '
            <button onclick="removeWidget(' . $id . ')" class="text-white hover:text-red-200">&times;</button>
        </div>
    </div>
    <div class="widget-content-wrapper h-[calc(100%-3rem)]" style="position: relative; z-index: 1;">
        <div class="widget-scaling-container h-full w-full">
            <div class="scalable-content ' . $innerClasses . '">
                ' . $content . '
            </div>
        </div>
    </div>';
}

// Konvertera vanliga Google-länkar (m.fl.) till inbäddningsbart format
function toEmbedUrl($url) {
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    // Google Presentationer -> /embed
    if (preg_match('#docs\.google\.com/presentation/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
        return 'https://docs.google.com/presentation/d/' . $m[1] . '/embed?start=false&loop=false&delayms=3000';
    }
    // Google Dokument -> /preview
    if (preg_match('#docs\.google\.com/document/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
        return 'https://docs.google.com/document/d/' . $m[1] . '/preview';
    }
    // Google Kalkylark -> /preview
    if (preg_match('#docs\.google\.com/spreadsheets/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
        return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/preview';
    }
    // Google Formulär -> viewform
    if (preg_match('#docs\.google\.com/forms/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
        return 'https://docs.google.com/forms/d/' . $m[1] . '/viewform?embedded=true';
    }

    // Lägg till protokoll om det saknas
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }

    // Släpp bara igenom riktiga http(s)-adresser med giltig värd –
    // stoppar t.ex. javascript:- och data:-URL:er i iframe-src.
    $parts = parse_url($url);
    if ($parts === false
        || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || empty($parts['host'])) {
        return '';
    }
    return $url;
}

switch ($type) {
    case 'clock':
        $content = '
            <div class="scalable-content">
                <div id="clock-' . $id . '" class="clock-display font-bold text-center"></div>
            </div>';
        outputWidgetWrapper($type, $id, $content);
        break;

    case 'analog_clock':
        $content = '
            <div class="scalable-content flex items-center justify-center h-full w-full">
                <div class="analog-clock-container" id="analog-clock-container-' . $id . '">
                    <div class="analog-clock">
                        <!-- Clockface -->
                        <div class="clock-face"></div>
                        
                        <!-- Hour marks and numbers - generated with JavaScript -->
                        <div class="hour-marks" id="hour-marks-' . $id . '"></div>
                        
                        <!-- Hands -->
                        <div id="hour-hand-' . $id . '" class="hand hour-hand"></div>
                        <div id="minute-hand-' . $id . '" class="hand minute-hand"></div>
                        <div id="second-hand-' . $id . '" class="hand second-hand"></div>
                        
                        <!-- Center dot -->
                        <div class="center-dot"></div>
                    </div>
                </div>
            </div>
            <script>
            // Dynamically create hour markings and numbers on load
            document.addEventListener("DOMContentLoaded", function() {
                createClockFace("' . $id . '");
            });
            </script>';
        outputWidgetWrapper($type, $id, $content);
        break;

    case 'timer':
        $minutes = $settings['minutes'] ?? 5;
        $seconds = $settings['seconds'] ?? 0;
        $formattedMinutes = str_pad($minutes, 2, "0", STR_PAD_LEFT);
        $formattedSeconds = str_pad($seconds, 2, "0", STR_PAD_LEFT);
        $extraControls = '
            <button onclick="startTimer(' . $id . ')" 
                    id="timer-toggle-' . $id . '"
                    class="text-white hover:text-blue-200 touch-manipulation" 
                    title="Starta/Pausa">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M8 5v14l11-7z"></path>
                </svg>
            </button>
            <button onclick="resetTimer(' . $id . ')" 
                    class="text-white hover:text-blue-200 touch-manipulation" 
                    title="Återställ">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.05 11a8 8 0 1 1 1.7 4.89M4 4v6h6"></path>
                </svg>
            </button>
        ';
        $content = '
            <div class="scalable-content w-full h-full flex flex-col justify-between">
                <div class="timer-display w-full flex items-center justify-center h-[75%]">
                    <div class="flex items-center timer-inputs font-mono">
                        <input type="number" 
                            id="timer-minutes-' . $id . '" 
                            class="timer-input bg-transparent appearance-none"
                            value="' . $formattedMinutes . '" 
                            min="0"
                            max="99"
                            onkeydown="handleTimeInput(event, this, \'minutes\')"
                            onchange="saveTimerSettings(' . $id . ')">
                        <span class="timer-separator">:</span>
                        <input type="number" 
                            id="timer-seconds-' . $id . '" 
                            class="timer-input bg-transparent appearance-none" 
                            value="' . $formattedSeconds . '" 
                            min="0"
                            max="59"
                            onkeydown="handleTimeInput(event, this, \'seconds\')"
                            onchange="saveTimerSettings(' . $id . ')">
                    </div>
                </div>
            </div>';
        outputWidgetWrapper($type, $id, $content, $extraControls);
        break;

        case 'text':
            $content = $settings['content'] ?? '';
            $content = '
                <div class="text-widget-container w-full h-full" id="text-container-' . $id . '">
                    <!-- Display mode -->
                    <div class="text-display-wrapper" id="text-display-wrapper-' . $id . '">
                        <div class="text-display" id="text-display-' . $id . '">' . nl2br(htmlspecialchars($content)) . '</div>
                    </div>
                    
                    <!-- Edit mode (hidden by default) -->
                    <textarea 
                        id="text-editor-' . $id . '" 
                        class="text-editor hidden"
                        placeholder="Skriv din text här..."
                        oninput="updateTextPreview(' . $id . ')"
                        onblur="finishEditing(' . $id . ')">' . htmlspecialchars($content) . '</textarea>
                </div>
                <script>
                    // Initialize text widget when loaded
                    setTimeout(() => initTextWidget(' . $id . '), 50);
                </script>';
            outputWidgetWrapper($type, $id, $content, '<button onclick="toggleTextEdit(' . $id . ')" class="text-white hover:text-blue-200 edit-text-btn touch-manipulation"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></button>');
            break;
    
            case 'groups':
                $groupCount = $settings['groupCount'] ?? 2;
                
                $content = '
                    <div class="widget-content-wrapper h-full w-full">
                        <div class="flex flex-col h-full w-full p-4">
                            <!-- Centrera knappen och gör den större -->
                            <div class="flex justify-center mb-4">
                                <button onclick="generateGroups(' . $id . ')" 
                                        class="bg-blue-500 hover:bg-blue-600 text-white py-3 px-6 rounded-md shadow-sm text-base font-medium flex items-center justify-center touch-manipulation">
                                    <svg class="w-5 h-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                                        <circle cx="9" cy="7" r="4"></circle>
                                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                    </svg>
                                    Skapa grupper
                                </button>
                            </div>
                            
                            <!-- Resultatområde med mindre padding för bättre utrymmesanvändning -->
                            <div id="groups-result-' . $id . '" class="flex-grow w-full overflow-auto"></div>
                        </div>
                    </div>';
                
                // Definiera knappar för headern som matchar din design
                $extraControls = '
                    <span class="text-white font-medium flex items-center">
                        Antal: 
                        <input type="number" 
                            id="group-count-' . $id . '"
                            value="' . $groupCount . '"
                            min="2" 
                            class="ml-2 w-12 p-1 border rounded text-center text-base"
                            style="background-color: rgba(255, 255, 255, 0.2); border-color: rgba(255, 255, 255, 0.3); color: white;"
                            onchange="saveGroupSettings(' . $id . ')"
                        >
                    </span>
                    
                    <button id="edit-names-btn-' . $id . '" onclick="window.openGroupEditModal(' . $id . ')" class="text-white hover:text-blue-200 ml-2 touch-manipulation">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                        </svg>
                    </button>
                    
                    <button onclick="window.openGroupSettings(' . $id . ')" class="text-white hover:text-blue-200 ml-2 touch-manipulation">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </button>
                    
                    <div class="relative group ml-2">
                        <button class="text-white hover:text-blue-200 touch-manipulation">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
                            </svg>
                        </button>
                        <div class="absolute right-0 top-6 mt-2 w-48 bg-white rounded shadow-lg z-50 hidden group-hover:block">
                            <div class="py-1">
                                <a href="#" onclick="clearGroupResults(' . $id . '); return false;" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    Rensa grupper
                                </a>
                                <a href="#" onclick="saveGroupsToClipboard(' . $id . '); return false;" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    Kopiera till urklipp
                                </a>
                            </div>
                        </div>
                    </div>
                ';
                
                outputWidgetWrapper($type, $id, $content, $extraControls);
                break;

                case 'brainbreak':
                    // Definiera extra kontroller för rubriken
                    $extraControls = '';
if (isset($_SESSION['user_id'])) {
    $extraControls .= '
    <button onclick="addNewBreak(' . $id . ')" class="text-white hover:text-blue-200 mr-2 touch-manipulation" title="Lägg till ny aktivitet">
    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
    </svg>
    </button>
    <button onclick="openBreakLibrary(' . $id . ')" class="text-white hover:text-blue-200 mr-2 touch-manipulation" title="Hantera aktiviteter">
    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <path stroke-linecap="round" stroke-linejoin="round"
    d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
    </svg>
    </button>';
}

// Lägg till "Inställningar"-knappen oavsett inloggningsstatus
$extraControls .= '
<button onclick="openBreakSettings(' . $id . ')" class="text-white hover:text-blue-200 touch-manipulation" title="Inställningar">
<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
<path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924-1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
<path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
</svg>
</button>';

// Huvudinnehåll
$content = '
<div class="scalable-content w-full h-full flex flex-col items-center justify-center p-2">
<div id="brainbreak-display-' . $id . '"
class="w-full h-full flex flex-col items-center justify-center text-center">
<button onclick="randomBreak(' . $id . ')"
class="bg-pink-500 text-white rounded-full px-6 py-3 hover:bg-pink-600 transform hover:scale-105 transition-all shadow-lg touch-manipulation"
style="font-size: calc(0.05 * min(var(--widget-width), var(--widget-height)));">
<span class="flex items-center gap-2">
<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
</svg>
Slumpa aktivitet
</span>
</button>
</div>
</div>';
outputWidgetWrapper($type, $id, $content, $extraControls);
break;
        
            case 'qrcode':
                $url = $settings['url'] ?? '';
                $isHidden = $settings['isHidden'] ?? false;
            
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM widgets WHERE whiteboard_id = ? AND type = 'qrcode' AND id <= ?");
                $stmt->execute([$whiteboard['id'], $id]);
                $number = $stmt->fetchColumn();
            
                // Define extra controls for the header
                $extraControls = '
                    <button onclick="saveQRUrl(' . $id . ')" class="text-white hover:text-blue-200 touch-manipulation">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
                    </button>
                    <button onclick="toggleQRVisibility(' . $id . ')" class="text-white hover:text-blue-200 touch-manipulation">
                        ' . ($isHidden ? '
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        ' : '
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                            <line x1="1" y1="1" x2="23" y2="23"/>
                        </svg>
                        ') . '
                    </button>';
            
                    $content = '
                    <div class="qr-container w-full h-full flex items-center justify-center p-4">
                        ' . ($url ? '
                        <div class="relative cursor-help group w-full h-full flex items-center justify-center">
                            <a href="' . (strpos($url, 'http') === 0 ? '' : 'https://') . htmlspecialchars($url) . '" 
                               target="_blank" class="w-full h-full flex items-center justify-center">
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=1000x1000&data=' . urlencode((strpos($url, 'http') === 0 ? '' : 'https://') . $url) . '" 
                                     class="qr-code w-full h-full object-contain ' . ($isHidden ? 'hidden' : '') . ' pointer-events-none" 
                                     alt="QR Code">
                            </a>
                            <div class="tooltip fixed opacity-0 group-hover:opacity-100 transition-opacity bg-black text-white p-2 rounded text-sm whitespace-nowrap pointer-events-none"
                                 style="transform: translate(-50%, -100%); left: var(--tooltip-x); top: var(--tooltip-y);">
                                ' . htmlspecialchars((strpos($url, 'http') === 0 ? '' : 'https://') . $url) . '
                            </div>
                        </div>' : '') . '
                    </div>';
                
            
                outputWidgetWrapper($type . ' ' . $number, $id, $content, $extraControls);
                break;

                case 'youtube':
                    $url = $settings['url'] ?? '';
                    $isHidden = $settings['isHidden'] ?? false;
                    $videoId = '';
                    
                    // Extract video ID from different YouTube URL formats
                    if ($url) {
                        if (preg_match('/(?:youtube\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $matches)) {
                            $videoId = $matches[1];
                        }
                    }
                    
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM widgets WHERE whiteboard_id = ? AND type = 'youtube' AND id <= ?");
                    $stmt->execute([$whiteboard['id'], $id]);
                    $number = $stmt->fetchColumn();
                
                    // Define extra controls for the header
                    $extraControls = '
                        <button onclick="saveYouTubeUrl(' . $id . ')" class="text-white hover:text-blue-200 touch-manipulation">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                        </button>
                        ' . ($videoId ? '
                        <button onclick="toggleYouTubeVisibility(' . $id . ')" class="text-white hover:text-blue-200 touch-manipulation">
                            ' . ($isHidden ? '
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            ' : '
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                                <line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                            ') . '
                        </button>' : '');
                
                    $content = '
                    <div class="youtube-container w-full h-full flex items-center justify-center">
                        ' . ($videoId ? '
                        <div class="relative w-full h-full">
                            <div class="' . ($isHidden ? 'hidden' : '') . '">
                                <div class="youtube-thumbnail relative cursor-pointer" onclick="playVideo(' . $id . ')">
                                    <img src="https://img.youtube.com/vi/' . htmlspecialchars($videoId) . '/mqdefault.jpg" 
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
                                    src="https://www.youtube.com/embed/' . htmlspecialchars($videoId) . '?enablejsapi=1" 
                                    frameborder="0" 
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                                    allowfullscreen>
                                </iframe>
                            </div>
                        </div>' : '') . '
                    </div>';
                
                    outputWidgetWrapper($type . ' ' . $number, $id, $content, $extraControls);
                    break;
            
                    case 'todo':
                        $tasks = $settings['tasks'] ?? [];
                        
                        $content = '
                        <div class="todo-widget-container w-full h-full flex flex-col p-3">
                            <div class="flex-grow overflow-auto" id="todo-list-' . $id . '">
                                <ul class="space-y-2 todo-list">';
                        
                        if (empty($tasks)) {
                            $content .= '
                                <li class="text-gray-500 text-center p-4 italic" 
                                    style="font-size: calc(0.08 * min(var(--widget-width), var(--widget-height)));">
                                    Inga uppgifter än. Lägg till en uppgift nedan.
                                </li>';
                        } else {
                            foreach ($tasks as $index => $task) {
                                $content .= '
                                    <li class="flex items-center p-2 rounded-lg transition-all ' . 
                                    ($task['completed'] ? 'bg-gray-50' : 'bg-white') . ' border border-gray-200 hover:bg-gray-50 group" 
                                        draggable="true" 
                                        data-index="' . $index . '">
                                        <div class="flex items-center flex-1 min-w-0 h-full">
                                            <div onclick="toggleTask(' . $id . ', ' . $index . ')" 
                                                class="flex-shrink-0 mr-2 cursor-pointer border-2 rounded ' . 
                                                ($task['completed'] 
                                                    ? 'border-blue-500 bg-blue-500 relative after:content-[\'\'] after:block after:absolute after:left-1/2 after:top-1/2 after:-translate-x-1/2 after:-translate-y-1/2 after:w-3 after:h-2 after:border-white after:border-b-2 after:border-r-2 after:rotate-45' 
                                                    : 'border-gray-300 hover:border-gray-400') . '"
                                                style="width: calc(0.07 * min(var(--widget-width), var(--widget-height)) + 10px); 
                                                       height: calc(0.07 * min(var(--widget-width), var(--widget-height)) + 10px);
                                                       min-width: 16px; min-height: 16px; max-width: 24px; max-height: 24px;">
                                            </div>
                                            <span class="flex-grow truncate ' . 
                                            ($task['completed'] ? 'line-through text-gray-500' : 'text-gray-800') . '"
                                                style="font-size: clamp(12px, calc(0.07 * min(var(--widget-width), var(--widget-height)) + 6px), 18px);
                                                       word-break: break-word; display: block; width: 100%;">
                                                ' . htmlspecialchars($task['text']) . '
                                            </span>
                                        </div>
                                        <button onclick="deleteTask(' . $id . ', ' . $index . ')" 
                                            class="ml-2 text-gray-400 hover:text-red-500 opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity touch-manipulation flex-shrink-0"
                                            style="width: calc(0.07 * min(var(--widget-width), var(--widget-height)) + 6px); 
                                                   height: calc(0.07 * min(var(--widget-width), var(--widget-height)) + 6px);
                                                   min-width: 16px; min-height: 16px; max-width: 22px; max-height: 22px;">
                                            <svg class="w-full h-full" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </li>';
                            }
                        }
                    
                        $content .= '
                                </ul>
                            </div>
                            <div class="mt-3 flex gap-2 flex-col sm:flex-row">
                                <div class="w-full flex">
                                    <input type="text" id="new-task-' . $id . '" placeholder="Ny uppgift..." 
                                        class="flex-grow border border-gray-300 rounded-l-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                        style="font-size: clamp(12px, calc(0.05 * min(var(--widget-width), var(--widget-height)) + 6px), 16px);">
                                    <button onclick="addNewTask(' . $id . ')" 
                                        class="bg-blue-500 text-white px-3 py-2 rounded-r-lg hover:bg-blue-600 flex items-center justify-center"
                                        style="min-width: calc(0.15 * min(var(--widget-width), var(--widget-height)));">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                        </svg>
                                    </button>
                                </div>
                                <button onclick="clearCompletedTasks(' . $id . ')" 
                                    class="bg-gray-100 text-gray-700 px-3 py-2 rounded-lg hover:bg-gray-200 touch-manipulation text-center"
                                    style="font-size: clamp(12px, calc(0.05 * min(var(--widget-width), var(--widget-height)) + 4px), 14px);">
                                    Rensa avklarade
                                </button>
                            </div>
                        </div>
                        
                        <script>
                        // Sätt upp draghantering för todo-listan
                        document.addEventListener("DOMContentLoaded", function() {
                            setupTodoDrag(' . $id . ');
                        });
                        
                        function setupTodoDrag(widgetId) {
                            const list = document.getElementById("todo-list-" + widgetId);
                            if (!list) return;
                            
                            let items = list.querySelectorAll(".todo-list li[draggable=true]");
                            let draggedItem = null;
                            
                            items.forEach(item => {
                                item.addEventListener("dragstart", function() {
                                    draggedItem = this;
                                    setTimeout(() => this.classList.add("opacity-50"), 0);
                                });
                                
                                item.addEventListener("dragend", function() {
                                    draggedItem = null;
                                    this.classList.remove("opacity-50");
                                });
                                
                                item.addEventListener("dragover", function(e) {
                                    e.preventDefault();
                                });
                                
                                item.addEventListener("dragenter", function(e) {
                                    e.preventDefault();
                                    if (this !== draggedItem) this.classList.add("bg-blue-50");
                                });
                                
                                item.addEventListener("dragleave", function() {
                                    if (this !== draggedItem) this.classList.remove("bg-blue-50");
                                });
                                
                                item.addEventListener("drop", function(e) {
                                    e.preventDefault();
                                    if (this !== draggedItem) {
                                        const fromIndex = parseInt(draggedItem.dataset.index);
                                        const toIndex = parseInt(this.dataset.index);
                                        reorderTasks(widgetId, fromIndex, toIndex);
                                        this.classList.remove("bg-blue-50");
                                    }
                                });
                            });
                        }
                        
                        function addNewTask(widgetId) {
                            const input = document.getElementById("new-task-" + widgetId);
                            const text = input.value.trim();
                            
                            if (text) {
                                addTask(widgetId, text);
                                input.value = "";
                                input.focus();
                            }
                        }
                        
                        // Hantera Enter-tangenten för att lägga till uppgift
                        document.addEventListener("DOMContentLoaded", function() {
                            const input = document.getElementById("new-task-" + ' . $id . ');
                            if (input) {
                                input.addEventListener("keypress", function(e) {
                                    if (e.key === "Enter") {
                                        e.preventDefault();
                                        addNewTask(' . $id . ');
                                    }
                                });
                            }
                        });
                        </script>';
                        
                        outputWidgetWrapper($type, $id, $content);
                        break;
            
                case 'trafficlight':
                    $currentState = $settings['state'] ?? 'green';
                    $states = [
                        'red' => [
                            'color' => 'bg-red-500',
                            'text' => 'Tystnad',
                            'icon' => '🤐'
                        ],
                        'yellow' => [
                            'color' => 'bg-yellow-400',
                            'text' => 'Viskningar',
                            'icon' => '🤫'
                        ],
                        'green' => [
                            'color' => 'bg-green-500',
                            'text' => 'Fritt att prata',
                            'icon' => '🗣️'
                        ]
                    ];
                    
                    $content = '
                        <div class="scalable-content w-full h-full flex flex-col items-center justify-center" 
                             style="padding: calc(min(var(--widget-width), var(--widget-height)) * 0.05);">
                            <div class="flex flex-col items-center w-full" 
                                 style="gap: calc(min(var(--widget-width), var(--widget-height)) * 0.03);">
                                ' . implode('', array_map(function($state, $stateData) use ($currentState, $id) {
                                    $isActive = $state === $currentState;
                                    return '
                                    <button 
                                        onclick="setTrafficLight(' . $id . ', \'' . $state . '\')" 
                                        class="w-full rounded-full transition-all touch-manipulation ' . 
                                        ($isActive ? $stateData['color'] . ' ring-2 ring-offset-1' : 'bg-gray-200' . ' opacity-50') . ' 
                                        hover:opacity-90 flex items-center justify-center"
                                        style="height: calc(min(var(--widget-width), var(--widget-height)) * 0.2);
                                               gap: calc(min(var(--widget-width), var(--widget-height)) * 0.03);">
                                        <span style="font-size: calc(min(var(--widget-width), var(--widget-height)) * 0.08);">' . 
                                            $stateData['icon'] . 
                                        '</span>
                                        <span class="' . ($isActive ? 'text-white' : 'text-gray-600') . '"
                                              style="font-size: calc(min(var(--widget-width), var(--widget-height)) * 0.06);">' . 
                                            $stateData['text'] . 
                                        '</span>
                                    </button>';
                                }, array_keys($states), $states)) . '
                            </div>
                        </div>';
                    
                    outputWidgetWrapper($type, $id, $content);
                    break;

                    case 'poll':
                        $settings = json_decode($widget['settings'], true) ?? [];
                        $isHidden = $settings['isHidden'] ?? false;
                    
                        $stmt = $pdo->prepare("
                            SELECT p.*, o.text, o.votes
                            FROM polls p
                            LEFT JOIN poll_options o ON p.id = o.poll_id
                            WHERE p.widget_id = ?
                            ORDER BY o.id
                        ");
                        $stmt->execute([$id]);
                        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                        if ($results) {
                            $poll = $results[0];
                            $voteUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/vote.php?poll_code=' . urlencode($poll['poll_code']);
                    
                            $content = '
                            <div class="poll-widget-content w-full h-full relative p-0 m-0">
                                <div class="poll-content absolute inset-0 transition-all duration-300 ' .
                                    ($isHidden ? 'invisible opacity-0' : 'visible opacity-100') . '">
                                    <div class="w-full h-full flex flex-col items-center p-0 m-0">
                                        <div class="w-full flex-none p-0 m-0">
                                            <p class="poll-question font-medium text-center text-sm leading-tight p-0 m-0">' .
                                                htmlspecialchars($poll['question']) .
                                            '</p>
                                        </div>
                                        <div class="qr-wrapper mt-1 flex-1 flex items-center justify-center w-3/5 aspect-square">
                                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($voteUrl) . '"
                                                 alt="QR-kod" class="w-full h-full object-contain">
                                        </div>
                                        <div class="mt-1 text-center flex-none">
                                            <p class="text-s text-gray-600">Använd koden:</p>
                                            <p class="poll-code font-bold">' . htmlspecialchars($poll['poll_code']) . '</p>
                                            <p class="text-s text-gray-600">rosta.klassrumsverktyg.se</p>
                                        </div>
                                    </div>
                                </div>
                    
                                <div class="poll-hidden-message absolute inset-0 flex items-center justify-center transition-all duration-300 ' .
                                    (!$isHidden ? 'invisible opacity-0' : 'visible opacity-100') . '">
                                    <p class="text-gray-500 text-sm">Klicka på ögat för att visa omröstningen</p>
                                </div>
                            </div>';
                        } else {
                            $content = '
                            <div class="h-full flex items-center justify-center">
                                <p class="text-gray-500 text-sm">Klicka på redigera-ikonen för att skapa din omröstning.</p>
                            </div>';
                        }
                    
                        $extraControls = '
                            <button onclick="openPollResults(' . $id . ')" class="text-white hover:text-blue-200 mr-2 touch-manipulation">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M18 20V10M12 20V4M6 20v-6" />
                                </svg>
                            </button>
                            <button onclick="openPollEditor(' . $id . ')" class="text-white hover:text-blue-200 mr-2 touch-manipulation">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                </svg>
                            </button>
                            <button onclick="togglePollCodeVisibility(' . $id . ')" class="text-white hover:text-blue-200 touch-manipulation">
                                ' . ($isHidden ? '
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                ' : '
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                                    <line x1="1" y1="1" x2="23" y2="23"/>
                                </svg>
                                ') . '
                            </button>';
                    
                        outputWidgetWrapper($type, $id, $content, $extraControls);
                        break;

                        case 'image':
                            $url = $settings['url'] ?? '';
                            $isHidden = $settings['isHidden'] ?? false;
                        
                            // Define extra controls for the header
                            $extraControls = '
                                <button onclick="saveImageUrl(' . $id . ')" class="text-white hover:text-blue-200 touch-manipulation">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                    </svg>
                                </button>
                                ' . ($url ? '
                                <button onclick="toggleImageVisibility(' . $id . ')" class="text-white hover:text-blue-200 touch-manipulation">
                                    ' . ($isHidden ? '
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                        <circle cx="12" cy="12" r="3"/>
                                    </svg>
                                    ' : '
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                                        <line x1="1" y1="1" x2="23" y2="23"/>
                                    </svg>
                                    ') . '
                                </button>' : '');
                        
                            $content = '
                                <div class="image-container w-full h-full flex items-center justify-center p-4">
                                    ' . ($url ? '
                                    <div class="relative w-full h-full flex items-center justify-center">
                                        <img src="' . htmlspecialchars($url) . '" 
                                             class="max-w-full max-h-full object-contain ' . ($isHidden ? 'hidden' : '') . '" 
                                             alt="Bild" />
                                        ' . (!$isHidden ? '' : '
                                        <div class="absolute inset-0 flex items-center justify-center">
                                            <span class="text-gray-400">Bilden är dold</span>
                                        </div>') . '
                                    </div>' : '
                                    <div class="text-gray-400 flex flex-col items-center justify-center text-center">
                                        <svg class="h-12 w-12 mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                            <polyline points="21 15 16 10 5 21"></polyline>
                                        </svg>
                                        <span>Klicka på redigera-ikonen för att lägga till en bild-URL</span>
                                    </div>') . '
                                </div>';
                        
                            outputWidgetWrapper('image', $id, $content, $extraControls);
                            break;
                
                    case 'embed':
                        $url = $settings['url'] ?? '';
                        $embedUrl = toEmbedUrl($url);

                        $extraControls = '
                            <button onclick="saveEmbedUrl(' . $id . ')" class="text-white hover:text-blue-200 touch-manipulation" title="Ange länk">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                </svg>
                            </button>
                            ' . ($embedUrl ? '
                            <a href="' . htmlspecialchars($embedUrl) . '" target="_blank" rel="noopener" class="text-white hover:text-blue-200 touch-manipulation" title="Öppna i ny flik">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                    <polyline points="15 3 21 3 21 9"/>
                                    <line x1="10" y1="14" x2="21" y2="3"/>
                                </svg>
                            </a>' : '');

                        $content = '
                            <div class="embed-container w-full h-full">
                                ' . ($embedUrl ? '
                                <iframe src="' . htmlspecialchars($embedUrl) . '"
                                        class="w-full h-full border-0"
                                        allowfullscreen
                                        referrerpolicy="no-referrer-when-downgrade"
                                        allow="autoplay; fullscreen"></iframe>' : '
                                <div class="w-full h-full flex flex-col items-center justify-center text-center text-gray-400 p-4">
                                    <svg class="h-12 w-12 mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                                        <line x1="8" y1="21" x2="16" y2="21"></line>
                                        <line x1="12" y1="17" x2="12" y2="21"></line>
                                    </svg>
                                    <span class="mb-3">Klistra in en länk till t.ex. en Google Presentation</span>
                                    <button onclick="saveEmbedUrl(' . $id . ')" class="px-4 py-2 bg-teal-500 text-white rounded-lg hover:bg-teal-600 flex items-center gap-2">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        Ange länk
                                    </button>
                                </div>') . '
                            </div>';

                        outputWidgetWrapper('embed', $id, $content, $extraControls);
                        break;

                    case 'pdf':
                        $pdfUrl = $settings['pdfUrl'] ?? '';

                        $extraControls = '
                            <button onclick="pdfUpload(' . $id . ')" class="text-white hover:text-blue-200 touch-manipulation" title="Ladda upp PDF">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                    <polyline points="17 8 12 3 7 8"/>
                                    <line x1="12" y1="3" x2="12" y2="15"/>
                                </svg>
                            </button>';

                        $content = '
                            <div class="pdf-widget w-full h-full flex flex-col" id="pdf-widget-' . $id . '" data-pdf-url="' . htmlspecialchars($pdfUrl) . '">
                                <div class="pdf-toolbar flex items-center gap-1 p-1 bg-gray-100 border-b border-gray-200 flex-wrap" style="touch-action: none;">
                                    <button onclick="pdfSetTool(' . $id . ', \'pen\')" data-tool="pen" class="pdf-tool-btn p-1 rounded hover:bg-gray-200" title="Penna">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="M2 2l7.586 7.586"/><circle cx="11" cy="11" r="2"/></svg>
                                    </button>
                                    <button onclick="pdfSetTool(' . $id . ', \'highlighter\')" data-tool="highlighter" class="pdf-tool-btn p-1 rounded hover:bg-gray-200" title="Markeringspenna">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 11-6 6v3h9l3-3"/><path d="m22 12-4.6 4.6a2 2 0 0 1-2.8 0l-5.2-5.2a2 2 0 0 1 0-2.8L14 4"/></svg>
                                    </button>
                                    <button onclick="pdfSetTool(' . $id . ', \'text\')" data-tool="text" class="pdf-tool-btn p-1 rounded hover:bg-gray-200" title="Text (klicka för att placera)">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>
                                    </button>
                                    <button onclick="pdfSetTool(' . $id . ', \'eraser\')" data-tool="eraser" class="pdf-tool-btn p-1 rounded hover:bg-gray-200" title="Sudd">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 20H7L3 16a2 2 0 0 1 0-3l9-9a2 2 0 0 1 3 0l5 5a2 2 0 0 1 0 3l-7 7"/></svg>
                                    </button>
                                    <span class="w-px h-5 bg-gray-300 mx-1"></span>
                                    <button onclick="pdfSetColor(' . $id . ', \'#1f2937\')" data-color="#1f2937" class="pdf-color-btn w-5 h-5 rounded-full border border-gray-300" style="background:#1f2937" title="Svart"></button>
                                    <button onclick="pdfSetColor(' . $id . ', \'#ef4444\')" data-color="#ef4444" class="pdf-color-btn w-5 h-5 rounded-full border border-gray-300" style="background:#ef4444" title="Röd"></button>
                                    <button onclick="pdfSetColor(' . $id . ', \'#2563eb\')" data-color="#2563eb" class="pdf-color-btn w-5 h-5 rounded-full border border-gray-300" style="background:#2563eb" title="Blå"></button>
                                    <button onclick="pdfSetColor(' . $id . ', \'#16a34a\')" data-color="#16a34a" class="pdf-color-btn w-5 h-5 rounded-full border border-gray-300" style="background:#16a34a" title="Grön"></button>
                                    <button onclick="pdfSetColor(' . $id . ', \'#facc15\')" data-color="#facc15" class="pdf-color-btn w-5 h-5 rounded-full border border-gray-300" style="background:#facc15" title="Gul"></button>
                                    <span class="w-px h-5 bg-gray-300 mx-1"></span>
                                    <button onclick="pdfClearPage(' . $id . ')" class="p-1 rounded hover:bg-gray-200 text-gray-600" title="Rensa sidan">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    </button>
                                    <span class="flex-grow"></span>
                                    <button onclick="pdfUpload(' . $id . ')" class="p-1 rounded hover:bg-blue-100 text-blue-600 font-medium flex items-center gap-1" title="Ladda upp PDF">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                        <span class="text-xs">Ladda upp</span>
                                    </button>
                                    <span class="w-px h-5 bg-gray-300 mx-1"></span>
                                    <button onclick="pdfPrevPage(' . $id . ')" class="p-1 rounded hover:bg-gray-200 text-gray-600" title="Föregående sida">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                                    </button>
                                    <span class="pdf-page-indicator text-xs text-gray-600 min-w-[3rem] text-center">–</span>
                                    <button onclick="pdfNextPage(' . $id . ')" class="p-1 rounded hover:bg-gray-200 text-gray-600" title="Nästa sida">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                                    </button>
                                </div>
                                <div class="pdf-stage flex-1 overflow-auto bg-gray-50 flex items-start justify-center p-2">
                                    <div class="pdf-canvas-wrap" style="position:relative; line-height:0; ' . ($pdfUrl ? '' : 'display:none;') . '">
                                        <canvas class="pdf-base" style="display:block; max-width:100%; height:auto;"></canvas>
                                        <canvas class="pdf-draw" style="position:absolute; top:0; left:0; width:100%; height:100%; touch-action:none; cursor:crosshair;"></canvas>
                                    </div>
                                    <div class="pdf-empty ' . ($pdfUrl ? 'hidden' : '') . ' flex flex-col items-center justify-center text-center text-gray-400 h-full p-4">
                                        <svg class="h-12 w-12 mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                        <span>Ladda upp en PDF med upp-ikonen för att skriva på den.</span>
                                    </div>
                                    <div class="pdf-loading hidden flex items-center justify-center text-gray-400 h-full">Laddar PDF…</div>
                                </div>
                                <input type="file" accept="application/pdf,.pdf" class="pdf-file-input hidden">
                            </div>';

                        outputWidgetWrapper('pdf', $id, $content, $extraControls);
                        break;

                    default:
                        echo '<div class="p-4 text-center">Ogiltig widget-typ</div>';
                }
                ?>