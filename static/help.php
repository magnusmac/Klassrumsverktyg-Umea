<?php
session_start();
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hjälp - Klassrumsverktyg</title>
    <link href="/assets/vendor/tailwind/tailwind.min.css" rel="stylesheet">
    <script src="/assets/vendor/lucide/lucide.min.js"></script>
</head>
<body class="flex flex-col min-h-screen bg-gray-100">
  <?php include '../header.php'; ?>

<main class="flex-grow container mx-auto px-6 py-12">
    <div class="max-w-5xl mx-auto">
        <h1 class="text-4xl font-bold text-gray-900 mb-8">Hjälp och Support</h1>

        <div class="bg-white rounded-lg shadow-md p-8">
            <p class="text-gray-700 leading-relaxed mb-6">
                Här hittar du information och instruktioner om hur du använder Klassrumsverktygs digitala whiteboard och dess olika funktioner.
            </p>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">Widgets</h2>
                <p class="text-gray-700 leading-relaxed mb-4">
                    Klicka på en widget för att lägga till den på din whiteboard. Nedan hittar du mer information om hur du använder varje widget:
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="clock" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Klocka</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Visar aktuell tid.</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="clock-5" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Analog klocka</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">En visuell analog klocka.</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="timer" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Timer</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Ställ in en nedräkning.</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="type" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Text</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Skriv direkt på whiteboarden.</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="check-square" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Att göra</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Skapa en checklista.</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="users" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Grupper</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Dela in elever i grupper.</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="qr-code" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">QR-kod</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Generera en QR-kod.</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="video" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">YouTube</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Bädda in en YouTube-video.</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="brain" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Brain Break</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Korta pausövningar.</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="ellipsis-vertical" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Trafikljus</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Visuell indikator för ljudnivå.</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="bar-chart" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Omröstning</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Skapa en snabb omröstning.</span>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm p-4 flex flex-col items-center justify-center border border-gray-200">
                        <i data-lucide="image" class="h-8 w-8 mb-2 text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700 text-center">Bild</span>
                        <span class="text-xs text-gray-500 mt-1 text-center">Ladda upp och visa en bild.</span>
                    </div>
                </div>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">Detaljerad användning av Widgets</h2>

                <div class="mb-6">
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">Klocka</h3>
                    <p class="text-gray-700 leading-relaxed">
                        Klockwidgeten visar den aktuella tiden. Du kan placera den var du vill på whiteboarden genom att klicka och dra.
                    </p>
                </div>

                <div class="mb-6">
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">Analog klocka</h3>
                    <p class="text-gray-700 leading-relaxed">
                        Den analoga klockan visar tiden med visare. Detta kan vara ett bra verktyg för att hjälpa elever att lära sig klockan. Klicka och dra för att flytta den.
                    </p>
                </div>

                <div class="mb-6">
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">Timer</h3>
                    <p class="text-gray-700 leading-relaxed">
                        Med timer-widgeten kan du ställa in en nedräkningstimer. Klicka på widgeten för att ange antal minuter och sekunder och tryck sedan på start.
                    </p>
                </div>

                <div class="mb-6">
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">Text</h3>
                    <p class="text-gray-700 leading-relaxed">
                        För att lägga till text, klicka på text-widgeten i menyn och sedan på den plats på whiteboarden där du vill skriva. En textruta skapas där du kan börja skriva. Du kan justera storlek och färg på texten (om dessa funktioner finns).
                    </p>
                </div>

                <div class="mb-6">
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">Att göra</h3>
                    <p class="text-gray-700 leading-relaxed">
                        Använd "Att göra"-widgeten för att skapa en lista med uppgifter. Klicka på widgeten och sedan på "Lägg till uppgift" för att skriva in en ny punkt. Du kan bocka av uppgifter när de är klara.
                    </p>
                </div>

                <div class="mb-6">
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">Grupper</h3>
                    <p class="text-gray-700 leading-relaxed">
                        Med grupper-widgeten kan du enkelt dela in dina elever i olika grupper. Ange antalet grupper eller antalet elever per grupp.
                    </p>
                </div>

                <div class="mb-6">
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">QR-kod</h3>
                    <p class="text-gray-700 leading-relaxed">
                        QR-kods-widgeten låter dig generera en QR-kod från en text eller en webbadress. Klicka på widgeten och ange sedan den text eller URL du vill omvandla till en QR-kod.
                    </p>
                </div>

                <div class="mb-6">
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">YouTube</h3>
                    <p class="text-gray-700 leading-relaxed">
                        För att bädda in en YouTube-video, klicka på YouTube-widgeten och ange sedan URL:en till videon. Videon kommer att visas direkt på din whiteboard.
                    </p>
                </div>

                <div class="mb-6">
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">Brain Break</h3>
                    <p class="text-gray-700 leading-relaxed">
                        Brain Break-widgeten startar en kort, ofta slumpmässig, pausaktivitet. Klicka på widgeten för att starta aktiviteten.
                    </p>
                </div>

                <div class="mb-6">
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">Trafikljus</h3>
                    <p class="text-gray-700 leading-relaxed">
                        Trafikljus-widgeten kan användas som en visuell indikator, till exempel för att visa ljudnivån i klassrummet (grönt för tyst, gult för okej, rött för för högt). Klicka på widgeten för att ändra färg.
                    </p>
                </div>

                <div class="mb-6">
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">Omröstning</h3>
                    <p class="text-gray-700 leading-relaxed">
                        Med omröstnings-widgeten kan du ställa en fråga och låta eleverna rösta. Klicka på widgeten för att skriva din fråga och ange svarsalternativ.
                    </p>
                </div>

                <div class="mb-6">
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">Bild</h3>
                    <p class="text-gray-700 leading-relaxed">
                        För att visa en bild på whiteboarden, klicka på bild-widgeten och välj sedan en bildfil från din enhet att ladda upp.
                    </p>
                </div>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">Övriga Funktioner</h2>
                <ul class="list-disc list-inside text-gray-700 leading-relaxed space-y-2">
                    <li><strong>Ändra bakgrund:</strong> Klicka på "Ändra bakgrund" i menyn för att välja en annan bakgrundsfärg eller ladda upp en egen bild.</li>
                    <li><strong>Hjälp:</strong> Klicka på "Hjälp" i menyn för att komma till denna sida.</li>
                
                    <li><strong>Dela:</strong> Klicka på "Dela" i menyn för att generera en länk som du kan dela med andra för att bjuda in dem till din whiteboard.</li>
                </ul>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">Vanliga frågor (FAQ)</h2>
                <ul class="list-disc list-inside text-gray-700 leading-relaxed space-y-2">
                    <li><strong>Fråga:</strong> Behöver jag ett konto för att använda whiteboarden? <strong>Svar:</strong> Nej, du kan använda whiteboarden utan att skapa ett konto. Om du vill spara dina whiteboards längre än tre dagar behöver du dock logga in.</li>
                    </ul>
            </section>

            <p class="text-gray-700 leading-relaxed mt-8">
                Hittar du inte svaret på din fråga? Kontakta oss gärna via vår <a href="/static/contact.php" class="text-blue-600 hover:text-blue-800 underline">kontaktsida</a>.
            </p>

            <div class="mt-10 border-t border-gray-200 pt-6">
                <p class="text-gray-500 text-sm">
                    Senast uppdaterad: 8 april 2025>
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