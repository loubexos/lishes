<?php
include 'config.php';

// Aktives Preset abrufen (verwende den Datensatz, bei dem active = 1 gesetzt ist)
$resultActive = $conn->query("SELECT * FROM customization_settings WHERE active = 1 LIMIT 1");
if ($resultActive && $resultActive->num_rows > 0) {
    $activePreset = $resultActive->fetch_assoc();
} else {
    // Fallback: Falls kein aktives Preset existiert, nutze den Datensatz mit id = 1
    $resultFallback = $conn->query("SELECT * FROM customization_settings WHERE id = 1 LIMIT 1");
    $activePreset = $resultFallback->fetch_assoc();
}

// Dark Mode Einstellungen aus dem Preset
$darkModeSwitchEnabled = (int)$activePreset['dark_mode_switch_enabled'];
$defaultMode           = $activePreset['default_mode'];

$headerTitle    = htmlspecialchars($activePreset['header_title'], ENT_QUOTES, 'UTF-8');
$faviconUrl     = $activePreset['favicon_url'];
$errimage_url   = $activePreset['errimage_url'];
$bgImageEnabled = $activePreset['bg_image_enabled'];
$bgImageUrl     = $activePreset['bg_image_url'];
$bg_blur        = (int)$activePreset['bg_blur'];

// Wunschlistenprodukte abrufen – Favoriten sollen oben gelistet werden
$stmt = $conn->prepare("SELECT * FROM wishlist ORDER BY is_favorite DESC, position ASC, id ASC");
$stmt->execute();
$wishlistResult = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo $headerTitle; ?></title>
  <!-- Favicon dynamisch setzen -->
  <?php if (!empty($faviconUrl)): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8'); ?>" type="image/x-icon">
  <?php else: ?>
    <link rel="icon" href="fav.svg" type="image/x-icon">
  <?php endif; ?>

  <script src="tail.js"></script>
  <script>
    tailwind.config = { darkMode: 'class' };
  </script>

  <style>
    /* Globale Transitions */
    .transition-all { transition: all 0.3s ease-in-out; }

    /* Wunschlisten-Favoriten-Rahmen */
    .wishlist-item[data-favorite="1"] {
      border: 4px solid #facc15;
      border-radius: 0.75rem;
    }

    /* Hintergrund-Overlay für das Hintergrundbild */
    #background-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-size: cover;
      background-repeat: no-repeat;
      z-index: -1;
    }

    /* Smooth Scroll-To-Top Button */
    #scrollToTop {
      position: fixed;
      bottom: 1.5rem; /* entspricht bottom-6 */
      right: 1.5rem;  /* entspricht right-6 */
      opacity: 0;
      transform: translateY(20px);
      transition: opacity 0.3s ease, transform 0.3s ease;
      z-index: 50;
    }
    #scrollToTop.visible {
      opacity: 1;
      transform: translateY(0);
    }
  </style>
</head>
<body class="bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition-colors">
  <?php if ($bgImageEnabled == 1 && !empty($bgImageUrl)): ?>
    <div id="background-overlay" style="
         background-image: url('<?php echo htmlspecialchars($bgImageUrl, ENT_QUOTES, 'UTF-8'); ?>');
         filter: blur(<?php echo $bg_blur; ?>px);
         ">
    </div>
  <?php endif; ?>

  <!-- Header-Bereich -->
  <header class="sticky top-0 z-50 bg-white/80 dark:bg-gray-900/80 shadow">
    <div class="max-w-5xl mx-auto px-4 py-8">
      <div class="flex flex-row items-center justify-between gap-4">
        <h1 class="text-3xl font-bold flex-1"><?php echo $headerTitle; ?></h1>
        <button id="toggle-theme"
                class="bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-white px-3 py-1 rounded-md whitespace-nowrap">
          🌓
        </button>
      </div>

      <!-- Such- und Filtersection -->
      <div class="mt-6 flex flex-col md:flex-row md:items-center gap-4">
        <input type="text" id="search" placeholder="🔍 Suche nach Wunsch..."
               class="flex-1 border rounded px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
               oninput="filterList()">
        <button id="toggle-filter"
                class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-md">
          Filter anzeigen
        </button>
      </div>

      <!-- Ausklappbares Filter-Menü -->
      <div id="filter-menu" class="hidden mt-4 bg-gray-100 dark:bg-gray-800 p-6 rounded-xl shadow-lg">
        <h2 class="text-xl font-bold mb-4 text-blue-800 dark:text-blue-200">Filter Optionen</h2>
        <!-- Sortierung -->
        <div class="mb-4">
          <label for="sort" class="block font-medium text-blue-800 dark:text-blue-200 mb-1">Sortierung</label>
          <select id="sort"
                  class="w-full border border-blue-300 rounded px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
                  onchange="sortList()">
            <option value="none">Keine Sortierung</option>
            <option value="asc">Preis ↑ Aufsteigend</option>
            <option value="desc">Preis ↓ Absteigend</option>
          </select>
        </div>
        <!-- Preis-Filter -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
          <div>
            <label for="min-price" class="block font-medium text-blue-800 dark:text-blue-200 mb-1">Min €</label>
            <input type="number" id="min-price"
                   class="w-full border rounded px-2 py-1 bg-white dark:bg-gray-700 text-gray-800 dark:text-white">
          </div>
          <div>
            <label for="max-price" class="block font-medium text-blue-800 dark:text-blue-200 mb-1">Max €</label>
            <input type="number" id="max-price"
                   class="w-full border rounded px-2 py-1 bg-white dark:bg-gray-700 text-gray-800 dark:text-white">
          </div>
        </div>
        <!-- Nur Favoriten -->
        <div class="mb-4">
          <label class="inline-flex items-center">
            <input type="checkbox" id="favorite-only" class="h-5 w-5 text-blue-600" onchange="filterList()">
            <span class="ml-2 font-medium text-blue-800 dark:text-blue-200">Nur Favoriten</span>
          </label>
        </div>
        <!-- Buttons -->
        <div class="flex gap-4">
          <button onclick="filterList()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex-1">
            Filtern
          </button>
          <button onclick="resetFilter()"
                  class="bg-gray-400 hover:bg-gray-500 dark:bg-gray-600 dark:hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex-1">
            Zurücksetzen
          </button>
        </div>
      </div>
    </div>
  </header>

  <!-- Hauptbereich: Wunschliste -->
  <main class="max-w-5xl mx-auto px-4 py-8">
    <div id="wishlist" class="space-y-4">
      <?php if ($wishlistResult && $wishlistResult->num_rows > 0): ?>
        <?php while ($row = $wishlistResult->fetch_assoc()):
          $price       = htmlspecialchars($row['price'], ENT_QUOTES, 'UTF-8');
          $name        = htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8');
          $image_url   = htmlspecialchars($row['image_url'], ENT_QUOTES, 'UTF-8');
          $product_url = htmlspecialchars($row['product_url'], ENT_QUOTES, 'UTF-8');
          $isFavorite  = (isset($row['is_favorite']) && $row['is_favorite']) ? '1' : '0';
        ?>
          <div class="wishlist-item relative bg-white dark:bg-gray-800 p-4 rounded-xl shadow flex flex-wrap items-center gap-4"
               data-price="<?php echo $price; ?>"
               data-name="<?php echo strtolower($name); ?>"
               data-favorite="<?php echo $isFavorite; ?>">
            <img src="<?php echo $image_url; ?>"
                 alt="<?php echo $name; ?>"
                 class="w-24 h-24 object-cover rounded-lg"
                 onerror="this.src='<?php echo htmlspecialchars($errimage_url, ENT_QUOTES, 'UTF-8'); ?>';">
            <div class="flex-1">
              <h2 class="text-xl font-semibold"><?php echo $name; ?></h2>
              <p class="text-gray-600 dark:text-gray-300">Preis: €<?php echo $price; ?></p>
              <a href="<?php echo $product_url; ?>"
                 class="text-blue-600 dark:text-blue-400 hover:underline">Zum Wunsch</a>
            </div>
            <?php if ($isFavorite === '1'): ?>
              <div class="w-12 flex items-center justify-end">
                <span class="text-yellow-500 text-4xl">★</span>
              </div>
            <?php endif; ?>
          </div>
        <?php endwhile; ?>
      <?php else: ?>
        <p class="text-gray-600 dark:text-gray-300">Die Wunschliste ist derzeit leer.</p>
      <?php endif; ?>
    </div>
  </main>

  <!-- Scroll-to-top Button ohne `hidden` -->
  <button id="scrollToTop"
          class="bg-blue-500 text-white rounded-full p-3 shadow-lg hover:bg-blue-600 transition">
    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
         viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M5 15l7-7 7 7" />
    </svg>
  </button>

  <script>
    // Theme-Handling
    const root = document.documentElement;
    const themeToggle = document.getElementById('toggle-theme');
    const darkSwitchEnabled = <?php echo json_encode($darkModeSwitchEnabled); ?>;
    const defaultMode = '<?php echo $defaultMode; ?>';
    let themeToApply;

    if (!darkSwitchEnabled) {
      if (defaultMode === 'system') {
        themeToApply = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      } else {
        themeToApply = defaultMode;
      }
      themeToggle.style.display = 'none';
    } else {
      const savedTheme = localStorage.getItem('theme');
      themeToApply = savedTheme
        ? savedTheme
        : (defaultMode === 'system'
            ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
            : defaultMode);
    }

    localStorage.setItem('theme', themeToApply);
    document.cookie = "theme=" + themeToApply + "; path=/; max-age=2592000";
    if (themeToApply === 'dark') root.classList.add('dark');
    else root.classList.remove('dark');

    if (darkSwitchEnabled) {
      themeToggle.addEventListener('click', () => {
        const isDark = root.classList.toggle('dark');
        const newTheme = isDark ? 'dark' : 'light';
        localStorage.setItem('theme', newTheme);
        document.cookie = "theme=" + newTheme + "; path=/; max-age=2592000";
      });
    }

    // Toggle Filter-Menü
    const toggleFilterBtn = document.getElementById('toggle-filter');
    const filterMenu = document.getElementById('filter-menu');
    toggleFilterBtn.addEventListener('click', () => {
      filterMenu.classList.toggle('hidden');
      toggleFilterBtn.textContent = filterMenu.classList.contains('hidden')
        ? 'Filter anzeigen'
        : 'Filter ausblenden';
    });

    // Filter-, Such- & Sortierfunktionen
    function filterList() {
      const min = parseFloat(document.getElementById('min-price').value) || 0;
      const max = parseFloat(document.getElementById('max-price').value) || Infinity;
      const search = document.getElementById('search').value.toLowerCase();
      const favoriteOnly = document.getElementById('favorite-only').checked;
      document.querySelectorAll('.wishlist-item').forEach(item => {
        const price = parseFloat(item.getAttribute('data-price'));
        const name = item.getAttribute('data-name');
        const isFavorite = item.getAttribute('data-favorite') === '1';
        const matchesPrice = (price >= min && price <= max);
        const matchesSearch = name.includes(search);
        const matchesFavorite = !favoriteOnly || isFavorite;
        item.style.display = (matchesPrice && matchesSearch && matchesFavorite) ? 'flex' : 'none';
      });
    }

    function resetFilter() {
      document.getElementById('min-price').value = '';
      document.getElementById('max-price').value = '';
      document.getElementById('search').value = '';
      document.getElementById('sort').value = 'none';
      document.getElementById('favorite-only').checked = false;
      document.querySelectorAll('.wishlist-item').forEach(item => {
        item.style.display = 'flex';
      });
    }

    function sortList() {
      const sortValue = document.getElementById('sort').value;
      const container = document.getElementById('wishlist');
      let items = Array.from(container.querySelectorAll('.wishlist-item'));
      items.sort((a, b) => {
        const priceA = parseFloat(a.getAttribute('data-price'));
        const priceB = parseFloat(b.getAttribute('data-price'));
        return sortValue === 'asc' ? priceA - priceB : priceB - priceA;
      });
      items.forEach(item => container.appendChild(item));
    }

    // Scroll-to-top Funktionalität
    const scrollBtn = document.getElementById('scrollToTop');
    window.addEventListener('scroll', () => {
      if (window.scrollY > 200) {
        scrollBtn.classList.add('visible');
      } else {
        scrollBtn.classList.remove('visible');
      }
    });
    scrollBtn.addEventListener('click', () => {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  </script>
</body>
</html>
