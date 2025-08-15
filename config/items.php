<?php
// items.php - Updated: img_fit support (0 = cover (default), 1 = contain)
session_start();
// CSRF-Token erzeugen
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// CSRF-Prüfung
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(400);
        exit('Ungültiger CSRF-Token');
    }
}

require '../config.php'; // $conn = new mysqli(...);

function sanitizeInput(string $data): string {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}
function isValidHex(?string $hex): bool {
    if ($hex === null) return false;
    $hex = trim($hex);
    return (bool) preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $hex);
}
function redirect_back() {
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Einstellungen aus DB (inkl. favorite_border_hex)
$errImageUrl    = 'about:blank';
$faviconUrl     = './fav.svg';
$bgImageUrl     = '';
$bgImageEnabled = false;
$bgBlur         = 0;
$favBorderHex   = '#facc15'; // Default

if ($stmtC = $conn->prepare("
    SELECT errimage_url, favicon_url, bg_image_url, bg_image_enabled, bg_blur, favorite_border_hex
      FROM customization_settings
     WHERE active = 1
     LIMIT 1
")) {
    $stmtC->execute();
    $stmtC->bind_result($dbErrUrl,$dbFaviconUrl,$dbBgUrl,$dbBgEnabled,$dbBgBlur,$dbFavHex);
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
        if (isValidHex($dbFavHex)) $favBorderHex = strtoupper($dbFavHex);
    }
    $stmtC->close();
}

// Initialize flash/session helpers for errors & old_input
if (!isset($_SESSION['form_errors'])) $_SESSION['form_errors'] = [];
if (!isset($_SESSION['old_input'])) $_SESSION['old_input'] = [];

/**
 * 1) Drag-&-Drop Reihenfolge speichern
 */
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

/**
 * 2) CRUD & toggle_favorite (inkl. color_hex und img_fit speichern)
 *    -> Serverseitige Validierung
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'reorder') {
    $action = $_POST['action'];
    // Reset session flashes
    $_SESSION['form_errors'] = [];
    $_SESSION['old_input'] = [];

    if ($action === 'add') {
        // Sammele Eingaben (roh)
        $nameRaw     = $_POST['name'] ?? '';
        $priceRaw    = $_POST['price'] ?? '';
        $imageRaw    = $_POST['image_url'] ?? '';
        $productRaw  = $_POST['product_url'] ?? '';
        $color_raw   = $_POST['color_hex'] ?? null;
        $clear_color = (isset($_POST['clear_color']) && $_POST['clear_color'] === '1');
        // img_fit checkbox: 1 = contain, 0 = cover
        $img_fit_raw = $_POST['img_fit'] ?? null;
        $img_fit = ($img_fit_raw === '1') ? 1 : 0;

        // Serverseitige Validierung (Name required, Price required & numeric)
        $errors = [];
        $name = trim($nameRaw);
        if ($name === '') $errors[] = 'Name darf nicht leer sein.';
        if ($priceRaw === '' || !is_numeric($priceRaw)) {
            $errors[] = 'Bitte einen gültigen Preis angeben.';
        } else {
            $price = number_format((float)$priceRaw, 2, '.', '');
            if ((float)$price < 0) $errors[] = 'Preis darf nicht negativ sein.';
        }
        // Bild- & Wunsch-URL optional, mache einfache Validierung
        $image_url   = filter_var($imageRaw, FILTER_VALIDATE_URL) ? sanitizeInput($imageRaw) : '';
        $product_url = filter_var($productRaw, FILTER_VALIDATE_URL) ? sanitizeInput($productRaw) : '';

        // preserve old input for repopulation (color preserved only if not cleared)
        $_SESSION['old_input'] = [
            'name' => $name,
            'price' => $priceRaw,
            'image_url' => $imageRaw,
            'product_url' => $productRaw,
            'color_hex' => $color_raw ?? '',
            'img_fit' => $img_fit
        ];

        if (!empty($errors)) {
            $_SESSION['form_errors'] = $errors;
            redirect_back();
        }

        // color handling
        if ($clear_color) {
            $color_hex = null;
        } else {
            $color_hex = isValidHex($color_raw) ? strtoupper(trim($color_raw)) : null;
        }

        // Insert only if validation passed
        $maxPosRes = $conn->query("SELECT COALESCE(MAX(position), -1) AS maxpos FROM wishlist");
        $newPos    = (int)$maxPosRes->fetch_assoc()['maxpos'] + 1;

        $stmt = $conn->prepare("
            INSERT INTO wishlist (name, price, image_url, product_url, is_favorite, position, color_hex, img_fit)
            VALUES (?, ?, ?, ?, FALSE, ?, ?, ?)
        ");
        // types: name s, price s, image_url s, product_url s, position i, color_hex s, img_fit i
        $stmt->bind_param('ssssisi', $name, $price, $image_url, $product_url, $newPos, $color_hex, $img_fit);
        $stmt->execute();
        $stmt->close();

        // clear old_input on success
        $_SESSION['old_input'] = [];
    } elseif ($action === 'edit') {
        $idRaw       = $_POST['id'] ?? '';
        $nameRaw     = $_POST['name'] ?? '';
        $priceRaw    = $_POST['price'] ?? '';
        $imageRaw    = $_POST['image_url'] ?? '';
        $productRaw  = $_POST['product_url'] ?? '';
        $color_raw   = $_POST['color_hex'] ?? null;
        $clear_color = (isset($_POST['clear_color']) && $_POST['clear_color'] === '1');
        $img_fit_raw = $_POST['img_fit'] ?? null;
        $img_fit = ($img_fit_raw === '1') ? 1 : 0;

        $errors = [];
        $id = filter_var($idRaw, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) $errors[] = 'Ungültige ID.';
        $name = trim($nameRaw);
        if ($name === '') $errors[] = 'Name darf nicht leer sein.';
        if ($priceRaw === '' || !is_numeric($priceRaw)) {
            $errors[] = 'Bitte einen gültigen Preis angeben.';
        } else {
            $price = number_format((float)$priceRaw, 2, '.', '');
            if ((float)$price < 0) $errors[] = 'Preis darf nicht negativ sein.';
        }
        $image_url   = filter_var($imageRaw, FILTER_VALIDATE_URL) ? sanitizeInput($imageRaw) : '';
        $product_url = filter_var($productRaw, FILTER_VALIDATE_URL) ? sanitizeInput($productRaw) : '';

        // preserve old input for repopulation if needed
        $_SESSION['old_input'] = [
            'id' => $id,
            'name' => $name,
            'price' => $priceRaw,
            'image_url' => $imageRaw,
            'product_url' => $productRaw,
            'color_hex' => $color_raw ?? '',
            'img_fit' => $img_fit
        ];

        if (!empty($errors)) {
            $_SESSION['form_errors'] = $errors;
            redirect_back();
        }

        if ($clear_color) {
            $color_hex = null;
        } else {
            $color_hex = isValidHex($color_raw) ? strtoupper(trim($color_raw)) : null;
        }

        $stmt = $conn->prepare("
            UPDATE wishlist
               SET name = ?, price = ?, image_url = ?, product_url = ?, color_hex = ?, img_fit = ?
             WHERE id = ?
        ");
        // types: name s, price s, image_url s, product_url s, color_hex s, img_fit i, id i
        $stmt->bind_param('sssssii', $name, $price, $image_url, $product_url, $color_hex, $img_fit, $id);
        $stmt->execute();
        $stmt->close();

        $_SESSION['old_input'] = [];
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

    // After handling POST, redirect back to avoid resubmission (and show errors if any)
    redirect_back();
}

/**
 * 3) Lade alle Items (inkl. color_hex, img_fit)
 */
$stmt = $conn->prepare("SELECT * FROM wishlist ORDER BY is_favorite DESC, position ASC");
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="de" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Wunsch-Config</title>
  <link rel="icon" href="<?= htmlspecialchars($faviconUrl ?? './fav.svg', ENT_QUOTES) ?>" type="image/x-icon">
  <script>tailwind.config = { darkMode: 'class' }</script>
  <script src="../tail.js"></script>
  <style>
    .wish-card { transition: transform 0.3s ease, opacity 0.3s ease; border: 4px solid transparent; border-radius: 0.75rem; overflow: hidden; }
    .sortable-ghost { opacity: 0.5; }
    .color-badge { position: absolute; top: 8px; right: 8px; width: 14px; height: 14px; border-radius: 9999px; border: 2px solid rgba(0,0,0,0.12); box-shadow: 0 0 0 1px rgba(255,255,255,0.6) inset; }
    .form-error { background:#391b1b; color:#ffd2d2; padding:8px; border-radius:6px; margin-bottom:12px; font-family:monospace; }
  </style>
</head>
<body class="bg-gray-900 text-gray-100 overflow-x-hidden">

  <?php if ($bgImageEnabled): ?>
  <div class="fixed inset-0 z-0 bg-cover bg-center pointer-events-none"
       style="background-image: url('<?= htmlspecialchars($bgImageUrl, ENT_QUOTES) ?>'); filter: blur(<?= $bgBlur ?>px); background-attachment: fixed;"></div>
  <?php endif; ?>

  <div class="relative z-10 container mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <!-- BACK BUTTON + TITLE IN ONE ROW (responsive, mobile-friendly) -->
    <div class="mb-8">
      <div class="flex items-center gap-4">
        <!-- Back button: bleibt links, shrink-0 damit er nicht zuschiebt -->
        <button type="button"
                onclick="window.history.back()"
                aria-label="Zurück"
                class="shrink-0 inline-flex items-center gap-2 bg-gray-600 hover:bg-gray-700 text-gray-100 py-2 px-3 rounded-lg shadow-sm">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
          <span class="font-medium text-sm">Zurück</span>
        </button>

        <!-- Titel: flex-1 sorgt dafür, dass er den verfügbaren Raum nutzt -->
        <!-- text-center hält die Überschrift zentriert, auch wenn Button links ist -->
        <h1 class="flex-1 text-2xl sm:text-3xl md:text-4xl font-bold text-center">🎁 Wunsch-Verwaltung</h1>
      </div>
    </div>

    <!-- Show errors from previous submit (if any) -->
    <?php if (!empty($_SESSION['form_errors'])): ?>
      <div class="form-error">
        <strong>Fehler:</strong>
        <ul>
          <?php foreach ($_SESSION['form_errors'] as $err): ?>
            <li><?= htmlspecialchars($err, ENT_QUOTES) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php
        // keep errors visible for single request only
        $_SESSION['form_errors'] = [];
      ?>
    <?php endif; ?>

    <!-- Neuen Wunsch hinzufügen -->
    <div class="bg-gray-800 border border-gray-700 rounded-lg shadow-md p-4 sm:p-6 mb-8">
      <?php
        // repopulate old values if available
        $old = $_SESSION['old_input'] ?? [];
        $oldName = htmlspecialchars($old['name'] ?? '', ENT_QUOTES);
        $oldPrice = htmlspecialchars($old['price'] ?? '', ENT_QUOTES);
        $oldImage = htmlspecialchars($old['image_url'] ?? '', ENT_QUOTES);
        $oldProduct = htmlspecialchars($old['product_url'] ?? '', ENT_QUOTES);
        $oldColor = htmlspecialchars($old['color_hex'] ?? '#ffffff', ENT_QUOTES);
        $oldImgFit = isset($old['img_fit']) && (int)$old['img_fit'] === 1 ? 1 : 0;
        // clear old_input so it doesn't persist unnecessarily
        $_SESSION['old_input'] = [];
      ?>
      <form id="addForm" method="POST" class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-6">
        <input name="name" required placeholder="Wunschname" value="<?= $oldName ?>"
               class="bg-gray-700 placeholder-gray-400 text-gray-100 border border-gray-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"/>
        <input name="price" required type="number" step="0.01" placeholder="Preis (€)" value="<?= $oldPrice ?>"
               class="bg-gray-700 placeholder-gray-400 text-gray-100 border border-gray-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"/>
        <input name="image_url" type="url" placeholder="Bild-URL" value="<?= $oldImage ?>"
               class="bg-gray-700 placeholder-gray-400 text-gray-100 border border-gray-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"/>
        <input name="product_url" type="url" placeholder="Wunsch-URL" value="<?= $oldProduct ?>"
               class="bg-gray-700 placeholder-gray-400 text-gray-100 border border-gray-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"/>
        <!-- Farbwahl: color input (sichtbar) + hidden input (echter submit-Wert) -->
        <div class="flex items-center gap-2">
          <input id="add-color-picker" type="color" value="<?= ($oldColor ?: '#ffffff') ?>" class="bg-gray-700 border border-gray-600 rounded-lg p-1 w-12" />
          <input id="add-color-hidden" name="color_hex" type="hidden" value="<?= ($oldColor ?: '#ffffff') ?>" />
          <input id="add-clear-color" name="clear_color" type="hidden" value="0" />
          <button type="button" onclick="clearAddColor()" class="bg-red-600 hover:bg-red-700 text-white py-2 px-3 rounded-lg">Keine Farbe</button>
        </div>

        <!-- IMG_FIT Checkbox: 1 = contain (Ganzes Bild sehen), 0 = cover (Kasten füllen) -->
        <label class="flex items-center gap-2">
          <input type="checkbox" name="img_fit" value="1" <?= $oldImgFit ? 'checked' : '' ?> />
          <span class="text-sm text-gray-300">Ganzes Bild anzeigen</span>
        </label>

        <input type="hidden" name="action" value="add">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <button type="submit" class="sm:col-span-2 md:col-span-6 w-full bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-lg">Hinzufügen</button>
      </form>
      <p class="mt-2 text-sm text-gray-400">Farbe wird als Rahmenfarbe angezeigt. Favoriten verwenden die Preset-Farbe in den design settings.</p>
    </div>

    <!-- Favoriten -->
    <div class="bg-gray-800 border border-gray-700 rounded-lg p-4 sm:p-6 mb-8">
      <h2 class="text-2xl font-semibold text-gray-100 mb-4">⭐ Favoriten</h2>
      <div id="favorites-list" class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
        <?php foreach ($items as $row):
          if ((int)$row['is_favorite'] === 1):
            $itemColor = isValidHex($row['color_hex'] ?? null) ? strtoupper($row['color_hex']) : null;
            $borderColor = $favBorderHex;
            $badgeColor  = $itemColor ?: $favBorderHex;
            $imgFitFlag  = (isset($row['img_fit']) && (int)$row['img_fit'] === 1) ? 1 : 0;
        ?>
        <div class="wish-card relative flex flex-col bg-gray-700 rounded-lg shadow-md transition transform hover:-translate-y-1"
             data-id="<?= $row['id'] ?>" style="border-color: <?= htmlspecialchars($borderColor, ENT_QUOTES) ?>;">
          <div class="drag-handle absolute top-2 left-2 p-2 cursor-move text-gray-400 hover:text-gray-200">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="currentColor">
              <path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
          </div>
          <?php if ($badgeColor): ?>
            <span class="color-badge" style="background: <?= htmlspecialchars($badgeColor, ENT_QUOTES) ?>;"></span>
          <?php endif; ?>

          <!-- Bild: object-fit steuert cover/contain -->
          <img src="<?= htmlspecialchars($row['image_url'], ENT_QUOTES) ?>"
               alt="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>"
               class="w-full h-40 <?= $imgFitFlag ? 'object-contain' : 'object-cover' ?>"
               onerror="this.src='<?= htmlspecialchars($errImageUrl, ENT_QUOTES) ?>';">

          <div class="p-3 flex-1 flex flex-col">
            <h3 class="text-lg font-bold text-gray-100 truncate" title="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>"><?=htmlspecialchars($row['name'],ENT_QUOTES) ?></h3>
            <p class="mt-1 text-gray-300">€<?= number_format($row['price'], 2, '.', '') ?></p>
            <a href="<?= htmlspecialchars($row['product_url'], ENT_QUOTES) ?>" target="_blank" class="mt-2 mb-2 text-center bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600">Zum Wunsch</a>
            <div class="space-y-2 mt-auto">
              <form method="POST">
                <input type="hidden" name="action" value="toggle_favorite">
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <button type="submit" class="w-full bg-yellow-500 hover:bg-yellow-600 text-white py-2 rounded-lg">Favorit entfernen</button>
              </form>
              <button onclick='openEditModal(<?= $row['id'] ?>, <?= json_encode($row['name']) ?>, <?= json_encode(number_format($row['price'], 2, '.', '')) ?>, <?= json_encode($row['image_url']) ?>, <?= json_encode($row['product_url']) ?>, <?= json_encode($row['color_hex'] ?? '') ?>, <?= json_encode((int)($row['img_fit'] ?? 0)) ?>)'
                      class="w-full bg-green-600 hover:bg-green-700 text-white py-2 rounded-lg">Bearbeiten</button>
              <form method="POST" onsubmit="return confirm('Löschen?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white py-2 rounded-lg">Löschen</button>
              </form>
            </div>
          </div>
        </div>
        <?php endif; endforeach; ?>
      </div>
    </div>

    <!-- Weitere Wünsche -->
    <div class="bg-gray-800 border border-gray-700 rounded-lg p-4 sm:p-6 mb-8">
      <h2 class="text-2xl font-semibold text-gray-100 mb-4">Weitere Wünsche</h2>
      <div id="others-list" class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
        <?php foreach ($items as $row):
          if ((int)$row['is_favorite'] === 0):
            $itemColor = isValidHex($row['color_hex'] ?? null) ? strtoupper($row['color_hex']) : null;
            $borderColor = $itemColor ?: 'transparent';
            $badgeColor  = $itemColor;
            $imgFitFlag  = (isset($row['img_fit']) && (int)$row['img_fit'] === 1) ? 1 : 0;
        ?>
        <div class="wish-card relative flex flex-col bg-gray-700 rounded-lg shadow-md transition transform hover:-translate-y-1" data-id="<?= $row['id'] ?>" style="border-color: <?= htmlspecialchars($borderColor, ENT_QUOTES) ?>;">
          <div class="drag-handle absolute top-2 left-2 p-2 cursor-move text-gray-400 hover:text-gray-200">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="currentColor"><path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
          </div>
          <?php if ($badgeColor): ?><span class="color-badge" style="background: <?= htmlspecialchars($badgeColor, ENT_QUOTES) ?>;"></span><?php endif; ?>

          <!-- Bild: object-fit steuert cover/contain -->
          <img src="<?= htmlspecialchars($row['image_url'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>" class="w-full h-40 <?= $imgFitFlag ? 'object-contain' : 'object-cover' ?>" onerror="this.src='<?= htmlspecialchars($errImageUrl, ENT_QUOTES) ?>';">

          <div class="p-3 flex-1 flex flex-col">
            <h3 class="text-lg font-bold text-gray-100 truncate" title="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>"><?=htmlspecialchars($row['name'],ENT_QUOTES) ?></h3>
            <p class="mt-1 text-gray-300">€<?= number_format($row['price'], 2, '.', '') ?></p>
            <a href="<?= htmlspecialchars($row['product_url'], ENT_QUOTES) ?>" target="_blank" class="mt-2 mb-2 text-center bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600">Zum Wunsch</a>
            <div class="space-y-2 mt-auto">
              <form method="POST">
                <input type="hidden" name="action" value="toggle_favorite">
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <button type="submit" class="w-full bg-yellow-500 hover:bg-yellow-600 text-white py-2 rounded-lg">Als Favorit setzen</button>
              </form>
              <button onclick='openEditModal(<?= $row['id'] ?>, <?= json_encode($row['name']) ?>, <?= json_encode(number_format($row['price'], 2, '.', '')) ?>, <?= json_encode($row['image_url']) ?>, <?= json_encode($row['product_url']) ?>, <?= json_encode($row['color_hex'] ?? '') ?>, <?= json_encode((int)($row['img_fit'] ?? 0)) ?>)'
                      class="w-full bg-green-600 hover:bg-green-700 text-white py-2 rounded-lg">Bearbeiten</button>
              <form method="POST" onsubmit="return confirm('Löschen?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white py-2 rounded-lg">Löschen</button>
              </form>
            </div>
          </div>
        </div>
        <?php endif; endforeach; ?>
      </div>
    </div>

    <div class="bg-gray-800 border border-gray-700 rounded-lg shadow-md p-4 text-center text-gray-400 text-sm">
      © <?= date('Y') ?> <a href="https://www.hocunity.net" target="_blank" rel="noopener" class="text-blue-400 hover:underline">HocunityNET</a>. All rights reserved.
    </div>

    <button id="scrollToTopBtn" class="fixed bottom-6 right-6 bg-blue-600 hover:bg-blue-700 text-white p-3 rounded-full shadow-lg transition-opacity duration-300 opacity-0 pointer-events-none" aria-label="Nach oben scrollen">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
    </button>

  </div>

  <!-- Edit Modal -->
  <div id="editModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4">
      <div class="fixed inset-0 bg-gray-900 opacity-50"></div>
      <div class="bg-gray-800 relative z-10 rounded-lg shadow-lg p-6 w-full max-w-md sm:max-w-lg">
        <h3 class="text-2xl mb-4 text-gray-100">Wunsch bearbeiten</h3>
        <form id="editForm" method="POST" class="space-y-3">
          <input type="hidden" name="action" value="edit">
          <input type="hidden" name="id" id="edit-id" value="">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
          <input type="text" name="name" id="edit-name" placeholder="Name" class="w-full bg-gray-600 border border-gray-500 rounded p-2 text-gray-100" required>
          <input type="number" step="0.01" name="price" id="edit-price" placeholder="Preis (€)" class="w-full bg-gray-600 border border-gray-500 rounded p-2 text-gray-100" required>
          <input type="url" name="image_url" id="edit-image_url" placeholder="Bild-URL" class="w-full bg-gray-600 border border-gray-500 rounded p-2 text-gray-100">
          <input type="url" name="product_url" id="edit-product_url" placeholder="Wunsch-URL" class="w-full bg-gray-600 border border-gray-500 rounded p-2 text-gray-100">

          <div class="flex gap-2 items-center">
            <label for="edit-color" class="text-sm text-gray-300">Farbe:</label>
            <!-- Visible picker + text -->
            <input type="color" id="edit-color-picker" class="w-12 h-9 p-0 border border-gray-500 rounded" />
            <input type="text" id="edit-color-text" class="flex-1 bg-gray-600 border border-gray-500 rounded p-2 text-gray-100" placeholder="#RRGGBB">
            <!-- Hidden real submit field + clear flag -->
            <input type="hidden" id="edit-color-hidden" name="color_hex" value="#ffffff">
            <input type="hidden" id="edit-clear-color" name="clear_color" value="0">
            <button type="button" onclick="clearEditColor()" class="bg-red-600 hover:bg-red-700 text-white py-2 px-3 rounded-lg">Keine Farbe</button>
          </div>

          <!-- IMG_FIT Checkbox in Edit Modal -->
          <label class="flex items-center gap-2">
            <input type="checkbox" id="edit-img-fit" name="img_fit" value="1" />
            <span class="text-sm text-gray-300">Ganzes Bild anzeigen</span>
          </label>

          <div class="flex gap-2">
            <button type="submit" class="flex-1 bg-green-600 hover:bg-green-700 text-white py-2 rounded-lg">Speichern</button>
            <button type="button" onclick="closeEditModal()" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white py-2 rounded-lg">Abbrechen</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';

    document.addEventListener('DOMContentLoaded', () => {
      // Sync add color picker -> hidden input
      const addPicker = document.getElementById('add-color-picker');
      const addHidden = document.getElementById('add-color-hidden');
      if (addPicker && addHidden) {
        addPicker.addEventListener('input', () => {
          addHidden.value = addPicker.value.toUpperCase();
          document.getElementById('add-clear-color').value = '0';
        });
        // initialize
        addHidden.value = addPicker.value.toUpperCase();
      }

      // Sync edit color picker <-> text <-> hidden
      const editPicker = document.getElementById('edit-color-picker');
      const editText   = document.getElementById('edit-color-text');
      const editHidden = document.getElementById('edit-color-hidden');

      if (editPicker && editText && editHidden) {
        editPicker.addEventListener('input', () => {
          editText.value = editPicker.value.toUpperCase();
          editHidden.value = editPicker.value.toUpperCase();
          document.getElementById('edit-clear-color').value = '0';
        });
        editText.addEventListener('input', () => {
          const v = editText.value.trim();
          const short = /^#([A-Fa-f0-9]{3})$/.exec(v);
          if (short) {
            const s = short[1];
            const normalized = '#' + s[0]+s[0] + s[1]+s[1] + s[2]+s[2];
            editPicker.value = normalized;
            editHidden.value = normalized;
            document.getElementById('edit-clear-color').value = '0';
          } else {
            const long = /^#([A-Fa-f0-9]{6})$/.exec(v);
            if (long) {
              const normalized = '#' + long[1];
              editPicker.value = normalized;
              editHidden.value = normalized;
              document.getElementById('edit-clear-color').value = '0';
            }
          }
        });
      }

      // Sortable init (favorites & others)
      ['favorites-list','others-list'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        Sortable.create(el, {
          animation: 300,
          handle: '.drag-handle',
          ghostClass: 'sortable-ghost',
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

      // Scroll-to-top button behavior
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
      scrollBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

      // restore scrollpos
      const scrollPos = sessionStorage.getItem("scrollpos");
      if (scrollPos !== null) {
        window.scrollTo(0, parseInt(scrollPos));
        sessionStorage.removeItem("scrollpos");
      }
    });

    // Open edit modal and populate fields (jetzt inkl. img_fit)
    function openEditModal(id, name, price, image_url, product_url, color_hex, img_fit) {
      document.getElementById('edit-id').value = id;
      document.getElementById('edit-name').value = name;
      document.getElementById('edit-price').value = price;
      document.getElementById('edit-image_url').value = image_url;
      document.getElementById('edit-product_url').value = product_url;

      const hex = (color_hex && color_hex !== '') ? color_hex : '#ffffff';
      document.getElementById('edit-color-picker').value = hex;
      document.getElementById('edit-color-text').value   = (color_hex && color_hex !== '') ? color_hex : '';
      document.getElementById('edit-color-hidden').value = (color_hex && color_hex !== '') ? color_hex : '';
      document.getElementById('edit-clear-color').value  = '0';

      // img_fit handling
      const editImgFit = document.getElementById('edit-img-fit');
      if (editImgFit) {
        editImgFit.checked = (parseInt(img_fit, 10) === 1);
      }

      document.getElementById('editModal').classList.remove('hidden');
    }

    function closeEditModal() {
      document.getElementById('editModal').classList.add('hidden');
    }

    /**
     * Clear color for add form:
     * - validate the form (like the save button would)
     * - if valid -> set clear flag + submit (use requestSubmit when available to trigger validation)
     */
    function clearAddColor() {
      const form = document.getElementById('addForm');
      if (!form) return;
      // check validity (this triggers the same checks as the browser would do on submit)
      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }
      document.getElementById('add-clear-color').value = '1';
      document.getElementById('add-color-hidden').value = '';
      // requestSubmit triggers constraint validation (safer than form.submit())
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
    }

    /**
     * Clear color for edit form:
     * - validate the edit form before submitting
     */
    function clearEditColor() {
      const form = document.getElementById('editForm');
      if (!form) return;
      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }
      document.getElementById('edit-clear-color').value = '1';
      document.getElementById('edit-color-hidden').value = '';
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
    }

    // Save scrollpos on unload
    window.addEventListener('beforeunload', function() {
      sessionStorage.setItem("scrollpos", window.scrollY);
    });
  </script>
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
</body>
</html>
