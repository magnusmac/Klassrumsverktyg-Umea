<?php
session_start();
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Whiteboard Funktioner - Klassrumsverktyg</title>
    <link href="/assets/vendor/tailwind/tailwind.min.css" rel="stylesheet">
    <script src="/assets/vendor/lucide/lucide.min.js"></script>
</head>
<body class="flex flex-col min-h-screen bg-gray-100">
  <?php include '../header.php'; ?>

<main class="flex-grow container mx-auto px-6 py-12">
    <div class="max-w-5xl mx-auto">
        <h1 class="text-4xl font-bold text-gray-900 mb-8">Whiteboard Funktioner</h1>

        <div class="bg-white rounded-lg shadow-md p-8">
            <p class="text-gray-700 leading-relaxed mb-6">
                Utforska de olika verktyg och funktioner som finns tillgängliga på din digitala whiteboard:
            </p>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">Widgets</h2>
                <p class="text-gray-700 leading-relaxed mb-4">
                    Klicka på en widget för att lägga till den på din whiteboard:
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="clock" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Klocka</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Visa aktuell tid</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="clock-5" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Analog klocka</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">En visuell analog klocka</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="timer" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Timer</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Nedräkningstimer</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="type" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Text</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Lägg till textrutor</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="check-square" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Att göra</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Skapa en att-göra-lista</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="users" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Grupper</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Dela in i grupper</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="qr-code" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">QR-kod</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Generera QR-kod</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="video" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">YouTube</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Bädda in YouTube-video</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="brain" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Brain Break</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Starta en pausaktivitet</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="ellipsis-vertical" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Trafikljus</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Visuell indikator</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="bar-chart" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Omröstning</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Skapa en omröstning</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="image" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Bild</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Visa en bild</span>
                    </div>
                </div>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">Övriga Funktioner</h2>
                <p class="text-gray-700 leading-relaxed mb-4">
                    Ytterligare funktioner som förbättrar din whiteboard-upplevelse:
                </p>
                <ul class="list-disc list-inside text-gray-700 leading-relaxed space-y-2">
                    <li><strong>Ändra bakgrund:</strong> Anpassa utseendet på din whiteboard med en färg eller bild.</li>
                    <li><strong>Hjälp:</strong> Få tillgång till information och guider för att använda whiteboarden effektivt.</li>
                    <li><strong>Spara whiteboard:</strong> Spara dina whiteboards (kräver inloggning) för att komma åt dem senare.</li>
                    <li><strong>Dela:</strong> Dela en länk till din whiteboard så att andra kan se den.</li>
                </ul>
            </section>

            <div class="mt-10 border-t border-gray-200 pt-6">
                <p class="text-gray-500 text-sm">
                    Senast uppdaterad: <?php echo date('j F Y'); ?>
                </p>
            </div>
        </div>
    </div>
</main>

<?php include '../footer.php'; ?>

<script>
  lucide.createIcons();
</script>

</body>
</html>