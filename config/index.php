<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Settings Selection</title>
  <!-- Tailwind CSS via CDN -->
  <script src="../tail.js"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          container: {
            center: true,
            padding: '1rem',
          },
        },
      },
    }
  </script>
</head>
<body class="bg-gray-900 text-white min-h-screen flex flex-col">
  <!-- Navbar with Home Button -->
  <nav class="container mx-auto p-4 flex items-center justify-between max-w-3xl">
    <a href="../" class="flex items-center space-x-2 bg-gray-800 hover:bg-gray-700 px-4 py-2 rounded-lg transition">
      <span>🏠</span>
      <span class="font-medium">Home</span>
    </a>
    <span class="text-lg sm:text-xl font-semibold">Settings</span>
  </nav>

  <!-- Main container -->
  <div class="flex-1 container mx-auto p-4 max-w-3xl">
    <h1 class="text-3xl sm:text-4xl font-bold text-center mb-8">Choose your settings</h1>
    <div class="grid grid-cols-1 gap-6 sm:grid-cols-1 md:grid-cols-3">
      <!-- Design Settings -->
      <a href="customization.php" class="block bg-gray-800 hover:bg-gray-700 transition-colors p-6 rounded-lg border border-gray-700 shadow-md">
        <h2 class="text-xl sm:text-2xl font-semibold mb-2">Design Settings</h2>
        <p class="text-gray-300 text-sm sm:text-base">Customize the look of your wish list.</p>
      </a>
      <!-- Wishlist Settings -->
      <a href="items.php" class="block bg-gray-800 hover:bg-gray-700 transition-colors p-6 rounded-lg border border-gray-700 shadow-md">
        <h2 class="text-xl sm:text-2xl font-semibold mb-2">Wish list settings</h2>
        <p class="text-gray-300 text-sm sm:text-base">Manage your wish list entries.</p>
      </a>
      <!-- Database Setup -->
      <a href="../setup" class="block bg-gray-800 hover:bg-gray-700 transition-colors p-6 rounded-lg border border-gray-700 shadow-md">
        <h2 class="text-xl sm:text-2xl font-semibold mb-2">Database Setup</h2>
        <p class="text-gray-300 text-sm sm:text-base">Initialize or migrate the database structure.</p>
      </a>
    </div>
  </div>
  
  <!-- Footer -->
  <footer class="text-center text-xs sm:text-sm py-4">
    © <span id="year"></span> <a href="https://www.hocunity.net" target="_blank" rel="noopener noreferrer" class="hover:underline">HocunityNET</a>. All rights reserved.
  </footer>
  
  <script>
    document.getElementById('year').textContent = new Date().getFullYear();
  </script>
</body>
</html>
