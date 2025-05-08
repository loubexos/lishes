<?php
// items.php
// -------------------
// Wishlist management with comprehensive protection.
session_start();
// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// CSRF check
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(400);
        exit('Invalid CSRF Token');
    }
}

require '../config.php'; // $conn = new mysqli(...);

function sanitizeInput(string $data): string {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Settings from the DB
$errImageUrl    = 'about:blank';
$faviconUrl     = './fav.svg';
$bgImageUrl     = '';
$bgImageEnabled = false;
$bgBlur         = 0;
if ($stmtC = $conn->prepare("
    SELECT errimage_url, favicon_url, bg_image_url, bg_image_enabled, bg_blur
      FROM customization_settings
     WHERE active = 1
     LIMIT 1
")) {
    $stmtC->execute();
    $stmtC->bind_result($dbErrUrl, $dbFaviconUrl, $dbBgUrl, $dbBgEnabled, $dbBgBlur);
    if ($stmtC->fetch()) {
        if (filter_var($dbErrUrl, FILTER_VALIDATE_URL))    $errImageUrl    = $dbErrUrl;
        if (filter_var($dbFaviconUrl, FILTER_VALIDATE_URL)) $faviconUrl     = $dbFaviconUrl;
        if ((int)$dbBgEnabled === 1 && filter_var($dbBgUrl, FILTER_VALIDATE_URL)) {
            $bgImageUrl     = $dbBgUrl;
            $bgImageEnabled = true;
            if (is_numeric($dbBgBlur) && (int)$dbBgBlur >= 0) {
                $bgBlur = (int)$dbBgBlur;
            }
        }
    }
    $stmtC->close();
}

// 1) Save drag & drop order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reorder') {
    $ids     = json_decode($_POST['ids'] ?? '[]', true);
    $section = $_POST['section'] ?? '';
    if (is_array($ids)) {
        $offset = 0;
        if ($section === 'others-list') {
            $favCountRes = $conn->query("SELECT COUNT(*) AS cnt FROM wishlist WHERE is_favorite=1");
            $offset      = (int)$favCountRes->fetch_assoc()['cnt'];
        }
        $upd = $conn->prepare("UPDATE wishlist SET position = ? WHERE id = ?");
        foreach ($ids as $i => $id) {
            $pos = $offset + (int)$i;
            $upd->bind_param('ii', $pos, $id);
            $upd->execute();
        }
        $upd->close();
    }
    exit;
}

// 2) CRUD & toggle_favorite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'reorder') {
    $action = $_POST['action'];
    if ($action === 'add') {
        $name        = sanitizeInput($_POST['name']);
        $priceRaw    = $_POST['price'];
        $price       = filter_var($priceRaw, FILTER_VALIDATE_FLOAT) !== false
                       ? number_format((float)$priceRaw, 2, '.', '') : '0.00';
        $image_url   = filter_var($_POST['image_url'], FILTER_VALIDATE_URL)
                       ? sanitizeInput($_POST['image_url']) : '';
        $product_url = filter_var($_POST['product_url'], FILTER_VALIDATE_URL)
                       ? sanitizeInput($_POST['product_url']) : '';
        $maxPosRes = $conn->query("SELECT COALESCE(MAX(position), -1) AS maxpos FROM wishlist");
        $newPos    = (int)$maxPosRes->fetch_assoc()['maxpos'] + 1;
        $stmt = $conn->prepare("
            INSERT INTO wishlist (name, price, image_url, product_url, is_favorite, position)
            VALUES (?, ?, ?, ?, FALSE, ?)
        ");
        $stmt->bind_param('ssssi', $name, $price, $image_url, $product_url, $newPos);
        $stmt->execute();
        $stmt->close();

    } elseif ($action === 'edit') {
        $id          = filter_var($_POST['id'], FILTER_VALIDATE_INT);
        $name        = sanitizeInput($_POST['name']);
        $price       = filter_var($_POST['price'], FILTER_VALIDATE_FLOAT) !== false
                       ? number_format((float)$_POST['price'], 2, '.', '') : '0.00';
        $image_url   = filter_var($_POST['image_url'], FILTER_VALIDATE_URL)
                       ? sanitizeInput($_POST['image_url']) : '';
        $product_url = filter_var($_POST['product_url'], FILTER_VALIDATE_URL)
                       ? sanitizeInput($_POST['product_url']) : '';
        $stmt = $conn->prepare("
            UPDATE wishlist
               SET name = ?, price = ?, image_url = ?, product_url = ?
             WHERE id = ?
        ");
        $stmt->bind_param('ssssi', $name, $price, $image_url, $product_url, $id);
        $stmt->execute();
        $stmt->close();

    } elseif ($action === 'delete') {
        $id   = filter_var($_POST['id'], FILTER_VALIDATE_INT);
        $stmt = $conn->prepare("DELETE FROM wishlist WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

    } elseif ($action === 'toggle_favorite') {
        $id   = filter_var($_POST['id'], FILTER_VALIDATE_INT);
        $stmt = $conn->prepare("
            UPDATE wishlist SET is_favorite = NOT is_favorite WHERE id = ?
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// 3) Load all items
$stmt = $conn->prepare("SELECT * FROM wishlist ORDER BY is_favorite DESC, position ASC");
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Wishlist Config</title>
  <link rel="icon" href="<?= htmlspecialchars($faviconUrl, ENT_QUOTES) ?>" type="image/x-icon">
  <script>tailwind.config = { darkMode: 'class' }</script>
  <script src="../tail.js"></script>
</head>
<body class="bg-gray-900 text-gray-100 overflow-x-hidden">

  <?php if ($bgImageEnabled): ?>
  <!-- Fixed, blurred background -->
  <div
    class="fixed inset-0 z-0 bg-cover bg-center pointer-events-none"
    style="
      background-image: url('<?= htmlspecialchars($bgImageUrl, ENT_QUOTES) ?>');
      filter: blur(<?= $bgBlur ?>px);
      background-attachment: fixed;
    ">
  </div>
  <?php endif; ?>

  <div class="relative z-10 container mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <!-- Header -->
    <h1 class="text-3xl sm:text-4xl font-bold mb-8 text-center whitespace-nowrap overflow-x-auto">
      🎁 Wishlist Management
    </h1>

    <!-- Add New Product -->
    <div class="bg-gray-800 border border-gray-700 rounded-lg shadow-md p-4 sm:p-6 mb-8">
      <form method="POST" class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-4">
        <input name="name" required placeholder="Product Name"
               class="bg-gray-700 placeholder-gray-400 text-gray-100 border border-gray-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"/>
        <input name="price" required type="number" step="0.01" placeholder="Price (€)"
               class="bg-gray-700 placeholder-gray-400 text-gray-100 border border-gray-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"/>
        <input name="image_url" type="url" placeholder="Image URL"
               class="bg-gray-700 placeholder-gray-400 text-gray-100 border border-gray-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"/>
        <input name="product_url" type="url" placeholder="Product URL"
               class="bg-gray-700 placeholder-gray-400 text-gray-100 border border-gray-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"/>
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <button type="submit"
                class="sm:col-span-2 md:col-span-4 w-full bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-lg">
          Add Product
        </button>
      </form>
    </div>

    <!-- Favorites -->
    <div class="bg-gray-800 border border-gray-700 rounded-lg p-4 sm:p-6 mb-8">
      <h2 class="text-2xl font-semibold text-gray-100 mb-4">⭐ Favorites</h2>
      <div id="favorites-list" class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
        <?php foreach ($items as $row): 
          if ((int)$row['is_favorite'] === 1): ?>
        <div class="wish-card relative flex flex-col bg-gray-700 rounded-lg shadow-md overflow-hidden transition transform hover:-translate-y-1"
             data-id="<?= $row['id'] ?>">
          <div class="drag-handle absolute top-2 left-2 p-2 cursor-move text-gray-400 hover:text-gray-200">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="currentColor">
              <path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
          </div>
          <img src="<?= htmlspecialchars($row['image_url'], ENT_QUOTES) ?>"
               alt="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>"
               class="w-full h-40 object-cover"
               onerror="this.src='<?= htmlspecialchars($errImageUrl, ENT_QUOTES) ?>';">
          <div class="p-3 flex-1 flex flex-col">
            <h3 class="text-lg font-bold text-gray-100 truncate"><?= htmlspecialchars($row['name'], ENT_QUOTES) ?></h3>
            <p class="mt-1 text-gray-300">€<?= number_format($row['price'], 2, '.', '') ?></p>
            <a href="<?= htmlspecialchars($row['product_url'], ENT_QUOTES) ?>" target="_blank"
               class="mt-2 mb-2 text-center bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600">
              View Wish
            </a>
            <div class="space-y-2 mt-auto">
              <form method="POST">
                <input type="hidden" name="action" value="toggle_favorite">
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <button type="submit" class="w-full bg-yellow-500 hover:bg-yellow-600 text-white py-2 rounded-lg">
                  Remove from Favorites
                </button>
              </form>
              <button onclick='openEditModal(<?= $row['id'] ?>, <?= json_encode($row['name']) ?>, <?= json_encode(number_format($row['price'], 2, ".", "")) ?>, <?= json_encode($row['image_url']) ?>, <?= json_encode($row['product_url']) ?>)'
                      class="w-full bg-green-600 hover:bg-green-700 text-white py-2 rounded-lg">
                Edit
              </button>
              <form method="POST" onsubmit="return confirm('Delete?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white py-2 rounded-lg">
                  Delete
                </button>
              </form>
            </div>
          </div>
        </div>
        <?php endif; endforeach; ?>
      </div>
    </div>

    <!-- Other Wishes -->
    <div class="bg-gray-800 border border-gray-700 rounded-lg p-4 sm:p-6 mb-8">
      <h2 class="text-2xl font-semibold text-gray-100 mb-4">Other Wishes</h2>
      <div id="others-list" class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
        <?php foreach ($items as $row): 
          if ((int)$row['is_favorite'] === 0): ?>
        <div class="wish-card relative flex flex-col bg-gray-700 rounded-lg shadow-md overflow-hidden transition transform hover:-translate-y-1"
             data-id="<?= $row['id'] ?>">
          <div class="drag-handle absolute top-2 left-2 p-2 cursor-move text-gray-400 hover:text-gray-200">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="currentColor">
              <path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
          </div>
          <img src="<?= htmlspecialchars($row['image_url'], ENT_QUOTES) ?>"
               alt="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>"
               class="w-full h-40 object-cover"
               onerror="this.src='<?= htmlspecialchars($errImageUrl, ENT_QUOTES) ?>';">
          <div class="p-3 flex-1 flex flex-col">
            <h3 class="text-lg font-bold text-gray-100 truncate"><?= htmlspecialchars($row['name'], ENT_QUOTES) ?></h3>
            <p class="mt-1 text-gray-300">€<?= number_format($row['price'], 2, '.', '') ?></p>
            <a href="<?= htmlspecialchars($row['product_url'], ENT_QUOTES) ?>" target="_blank"
               class="mt-2 mb-2 text-center bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600">
              View Wish
            </a>
            <div class="space-y-2 mt-auto">
              <form method="POST">
                <input type="hidden" name="action" value="toggle_favorite">
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <button type="submit" class="w-full bg-yellow-500 hover:bg-yellow-600 text-white py-2 rounded-lg">
                  Set as Favorite
                </button>
              </form>
              <button onclick='openEditModal(<?= $row['id'] ?>, <?= json_encode($row['name']) ?>, <?= json_encode(number_format($row['price'], 2, ".", "")) ?>, <?= json_encode($row['image_url']) ?>, <?= json_encode($row['product_url']) ?>)'
                      class="w-full bg-green-600 hover:bg-green-700 text-white py-2 rounded-lg">
                Edit
              </button>
              <form method="POST" onsubmit="return confirm('Delete?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white py-2 rounded-lg">
                  Delete
                </button>
              </form>
            </div>
          </div>
        </div>
        <?php endif; endforeach; ?>
      </div>
    </div>

    <!-- Footer -->
    <div class="bg-gray-800 border border-gray-700 rounded-lg shadow-md p-4 text-center text-gray-400 text-sm">
      © <?= date('Y') ?> 
      <a href="https://www.hocunity.net" target="_blank" rel="noopener"
         class="text-blue-400 hover:underline">HocunityNET</a>. All rights reserved.
    </div>

    <!-- Scroll-to-Top Button -->
    <button id="scrollToTopBtn"
            class="fixed bottom-6 right-6 bg-blue-600 hover:bg-blue-700 text-white p-3 rounded-full shadow-lg transition-opacity duration-300 opacity-0 pointer-events-none"
            aria-label="Scroll to top">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
           viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M5 15l7-7 7 7"/>
      </svg>
    </button>

  </div>

  <!-- Edit Modal Popup – responsive for small screens -->
  <div id="editModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4">
      <div class="fixed inset-0 bg-gray-900 opacity-50"></div>
      <div class="bg-gray-800 relative z-10 rounded-lg shadow-lg p-6 w-full max-w-md sm:max-w-lg">
        <h3 class="text-2xl mb-4 text-gray-100">Edit Wish</h3>
        <form id="editForm" method="POST" class="space-y-3">
          <input type="hidden" name="action" value="edit">
          <input type="hidden" name="id" id="edit-id" value="">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
          <input type="text" name="name" id="edit-name" placeholder="Name"
                 class="w-full bg-gray-600 border border-gray-500 rounded p-2 text-gray-100" required>
          <input type="number" step="0.01" name="price" id="edit-price" placeholder="Price (€)"
                 class="w-full bg-gray-600 border border-gray-500 rounded p-2 text-gray-100" required>
          <input type="url" name="image_url" id="edit-image_url" placeholder="Image URL"
                 class="w-full bg-gray-600 border border-gray-500 rounded p-2 text-gray-100">
          <input type="url" name="product_url" id="edit-product_url" placeholder="Product URL"
                 class="w-full bg-gray-600 border border-gray-500 rounded p-2 text-gray-100">
          <div class="flex gap-2">
            <button type="submit" class="flex-1 bg-green-600 hover:bg-green-700 text-white py-2 rounded-lg">
              Save
            </button>
            <button type="button" onclick="closeEditModal()"
                    class="flex-1 bg-gray-600 hover:bg-gray-700 text-white py-2 rounded-lg">
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Script Section: Drag & Drop, Scroll Position Persistence, and Modal Functionality -->
  <script>
    const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
    document.addEventListener('DOMContentLoaded', () => {
      // Initialize Sortable for both lists
      ['favorites-list','others-list'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        Sortable.create(el, {
          animation: 150,
          handle: '.drag-handle',
          onEnd: () => {
            const ids = Array.from(el.querySelectorAll('.wish-card')).map(c => c.dataset.id);
            const params = new URLSearchParams({
              action: 'reorder',
              ids: JSON.stringify(ids),
              section: id,
              csrf_token: CSRF_TOKEN
            });
            fetch('', { method: 'POST', body: params });
          }
        });
      });

      // Scroll-to-Top Button
      const scrollBtn = document.getElementById('scrollToTopBtn');
      window.addEventListener('scroll', () => {
        if (window.scrollY >= 300) {
          scrollBtn.classList.replace('opacity-0', 'opacity-100');
          scrollBtn.classList.replace('pointer-events-none', 'pointer-events-auto');
        } else {
          scrollBtn.classList.replace('opacity-100', 'opacity-0');
          scrollBtn.classList.replace('pointer-events-auto', 'pointer-events-none');
        }
      });
      scrollBtn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });

    // Opens the edit modal and fills the fields
    function openEditModal(id, name, price, image_url, product_url) {
      document.getElementById('edit-id').value = id;
      document.getElementById('edit-name').value = name;
      document.getElementById('edit-price').value = price;
      document.getElementById('edit-image_url').value = image_url;
      document.getElementById('edit-product_url').value = product_url;
      document.getElementById('editModal').classList.remove('hidden');
    }

    // Closes the edit modal
    function closeEditModal() {
      document.getElementById('editModal').classList.add('hidden');
    }

    // Saves the current scroll position before leaving the page
    window.addEventListener('beforeunload', function() {
      sessionStorage.setItem("scrollpos", window.scrollY);
    });

    // Restores the saved scroll position when the page loads
    document.addEventListener('DOMContentLoaded', function() {
      const scrollPos = sessionStorage.getItem("scrollpos");
      if (scrollPos !== null) {
        window.scrollTo(0, parseInt(scrollPos));
        sessionStorage.removeItem("scrollpos");
      }
    });
  </script>
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
</body>
</html>
