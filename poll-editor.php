<?php
require_once __DIR__ . '/src/Config/Database.php';

$db = new Database();
$pdo = $db->getConnection();

$widgetId = $_GET['widget_id'] ?? null;
$boardCode = $_GET['board'] ?? '';

// Om det är en POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Debug information
        error_log('Received POST request');
        error_log('Widget ID: ' . $widgetId);
        error_log('POST data: ' . print_r($_POST, true));

        // Skapa poll med show_results
        $pollCode = strtoupper(substr(md5(uniqid()), 0, 6));
        $stmt = $pdo->prepare("INSERT INTO polls (widget_id, question, poll_code, show_results) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $widgetId, 
            $_POST['question'], 
            $pollCode,
            isset($_POST['show_results']) ? 1 : 0
        ]);
        
        $pollId = $pdo->lastInsertId();
        error_log('Created poll with ID: ' . $pollId);

        // Lägg till alternativ
        $stmt = $pdo->prepare("INSERT INTO poll_options (poll_id, text) VALUES (?, ?)");
        foreach ($_POST['options'] as $option) {
            if (!empty($option)) {
                $stmt->execute([$pollId, $option]);
                error_log('Added option: ' . $option);
            }
        }

        $pdo->commit();
        http_response_code(200);
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Error creating poll: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <title>Skapa omröstning</title>
    <link rel="stylesheet" href="/assets/vendor/tailwind/tailwind.min.css">
</head>
<body>
    <div class="bg-white rounded-lg shadow-lg p-6 max-w-lg mx-auto">
        <form method="POST" class="space-y-4">
            <input type="hidden" name="widget_id" value="<?php echo htmlspecialchars($widgetId); ?>">
            <input type="hidden" name="board" value="<?php echo htmlspecialchars($boardCode); ?>">
            
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Fråga:</label>
                <input type="text" name="question" required 
                       class="w-full p-2 border border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
            </div>

            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Alternativ:</label>
                <div id="optionsContainer" class="space-y-2">
                    <div class="flex gap-2">
                        <input type="text" name="options[]" required 
                               class="flex-grow p-2 border border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div class="flex gap-2">
                        <input type="text" name="options[]" required 
                               class="flex-grow p-2 border border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    </div>
                </div>
                <button type="button" onclick="addOption()" 
                        class="mt-4 px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 transition-colors">
                    Lägg till alternativ
                </button>
            </div>

            <div class="flex items-center">
        <input type="checkbox" 
               name="show_results" 
               id="show_results" 
               class="h-4 w-4 text-blue-600 border-gray-300 rounded"
               checked>
        <label for="show_results" class="ml-2 text-gray-700">
            Visa resultat direkt för deltagarna
        </label>
    </div>

            <div class="flex justify-end gap-2 pt-4 border-t">
                <button type="button" onclick="closePollEditor()" 
                        class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors">
                    Avbryt
                </button>
                <button type="submit" 
                        class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 transition-colors">
                    Spara
                </button>
            </div>
        </form>
    </div>

    <script>
    function addOption() {
        const container = document.getElementById('optionsContainer');
        if (!container) return;
        
        const div = document.createElement('div');
        div.className = 'flex gap-2';
        div.innerHTML = `
            <input type="text" name="options[]" required 
                   class="flex-grow p-2 border border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
            <button type="button" 
                    class="text-red-500 hover:text-red-700" 
                    onclick="this.parentElement.remove()">Ta bort</button>
        `;
        container.appendChild(div);
    }

    // Debug formulärdata
    document.querySelector('form').addEventListener('submit', function(e) {
        e.preventDefault(); // Stoppa normal submit
        console.log('Form data:', {
            widget_id: this.elements['widget_id'].value,
            board: this.elements['board'].value,
            question: this.elements['question'].value,
            options: Array.from(this.elements['options[]']).map(el => el.value)
        });
        
        // Skicka formuläret manuellt
        this.submit();
    });
    </script>
</body>
</html>