<?php
session_start();
http_response_code(404);
?>

<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sidan hittades inte - Klassrumsverktyg</title>
    <link href="/assets/vendor/tailwind/tailwind.min.css" rel="stylesheet">
    <script src="/assets/vendor/lucide/lucide.min.js"></script>
</head>
<body class="flex flex-col min-h-screen bg-gray-100">
    <!-- Header -->
    <?php include 'header.php'; ?>

    <!-- Main Content -->
    <main class="flex-grow container mx-auto px-6 py-16">
        <div class="max-w-4xl mx-auto text-center py-12">
            <div class="mb-8 flex justify-center">
                <!-- Whiteboard Icon -->
                <div class="bg-white p-6 rounded-full shadow-lg">
                    <i data-lucide="file-x" class="w-24 h-24 text-blue-600"></i>
                </div>
            </div>
            
            <h1 class="text-5xl font-extrabold text-gray-900 mb-6">
                Oops, whiteboarden hittades inte!
            </h1>
            
            <p class="text-xl text-gray-700 mb-8 max-w-2xl mx-auto">
                Sidan du letar efter verkar ha suddats ut eller så har den aldrig skapats. Kontrollera adressen eller prova något av alternativen nedan.
            </p>
            
            <div class="flex flex-col md:flex-row space-y-4 md:space-y-0 md:space-x-6 justify-center">
                <form method="POST" action="/">
                    <button type="submit" name="create_whiteboard" 
                            class="bg-blue-600 text-white px-8 py-4 rounded-full text-lg font-semibold 
                                   shadow-md hover:bg-blue-700 transition-all duration-300 w-full md:w-auto">
                        Skapa ny whiteboard
                    </button>
                </form>
                
                <a href="/" 
                   class="bg-gray-200 text-gray-800 px-8 py-4 rounded-full text-lg font-semibold 
                          hover:bg-gray-300 transition-all duration-300 flex items-center justify-center">
                    <i data-lucide="home" class="w-5 h-5 mr-2"></i>
                    Tillbaka till startsidan
                </a>
            </div>
        </div>
        
        <!-- Features mini-section -->
        <div class="max-w-3xl mx-auto mt-16">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white rounded-lg shadow-lg p-6 text-center">
                    <h3 class="text-xl font-semibold mb-2">Skapa en ny</h3>
                    <p class="text-gray-600">Börja från början med en helt ny digital whiteboard.</p>
                </div>
                <div class="bg-white rounded-lg shadow-lg p-6 text-center">
                    <h3 class="text-xl font-semibold mb-2">Hjälp & Support</h3>
                    <p class="text-gray-600">Behöver du hjälp? Kontakta vår support för att få assistans.</p>
                </div>
                <div class="bg-white rounded-lg shadow-lg p-6 text-center">
                    <h3 class="text-xl font-semibold mb-2">Utforska funktioner</h3>
                    <p class="text-gray-600">Kolla in alla tillgängliga funktioner i vår tjänst.</p>
                </div>
            </div>
        </div>
    </main>

    <?php include 'footer.php'; ?>

    <script>
        // Initialisera Lucide ikoner
        lucide.createIcons();
    </script>
</body>
</html>