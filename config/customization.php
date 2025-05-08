<?php
include '../config.php';

// Disable error display (in production)
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Function to sanitize inputs (guards against XSS)
function sanitize_input($input) {
    return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
}

// Ensure the preset blocks (IDs 1 to 10) exist.
// Using a prepared statement here to secure the insert operation.
$stmtInsert = $conn->prepare("INSERT INTO customization_settings (id, preset_name, active, bg_image_enabled, bg_image_url, dark_mode_switch_enabled, default_mode, header_title, favicon_url, errimage_url, bg_blur)
                               VALUES (?, ?, ?, 0, '', 1, 'system', 'Wishlist NAME 🎁', '', '', 0)");
for ($i = 1; $i <= 10; $i++) {
    // Check if this preset already exists
    $stmtCheck = $conn->prepare("SELECT id FROM customization_settings WHERE id = ?");
    $stmtCheck->bind_param("i", $i);
    $stmtCheck->execute();
    $resultCheck = $stmtCheck->get_result();
    if ($resultCheck->num_rows === 0) {
        $defaultPresetName = "Preset-" . $i;
        $active = ($i === 1) ? 1 : 0;
        $stmtInsert->bind_param("isi", $i, $defaultPresetName, $active);
        $stmtInsert->execute();
    }
    $stmtCheck->close();
}
$stmtInsert->close();

// By default, load preset 1 if no GET parameter is provided
$preset_id = isset($_GET['preset_id']) ? (int)$_GET['preset_id'] : 1;

$message = '';
// Form processing: apply changes only when "Save" is clicked
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $preset_id                = (int) $_POST['preset_id'];
    $bg_image_enabled         = isset($_POST['bg_image_enabled']) ? 1 : 0;
    $bg_image_url             = sanitize_input($_POST['bg_image_url'] ?? '');
    $bg_blur                  = isset($_POST['bg_blur']) ? (int)$_POST['bg_blur'] : 0;
    $dark_mode_switch_enabled = isset($_POST['dark_mode_switch_enabled']) ? 1 : 0;
    $default_mode             = in_array($_POST['default_mode'] ?? 'system', ['light', 'dark', 'system']) ? $_POST['default_mode'] : 'system';
    $header_title             = sanitize_input($_POST['header_title'] ?? '');
    $favicon_url              = sanitize_input($_POST['favicon_url'] ?? '');
    $errimage_url             = sanitize_input($_POST['errimage_url'] ?? '');
    $preset_name              = sanitize_input($_POST['preset_name'] ?? '');
    $active                   = isset($_POST['active']) ? 1 : 0;
    
    // If this preset is marked active, deactivate all others (IDs 1 to 10)
    if ($active === 1) {
        $conn->query("UPDATE customization_settings SET active = 0 WHERE id BETWEEN 1 AND 10");
    }

    // Update preset data via prepared statement
    $stmt = $conn->prepare("UPDATE customization_settings 
                            SET bg_image_enabled = ?, bg_image_url = ?, bg_blur = ?, dark_mode_switch_enabled = ?, 
                                default_mode = ?, header_title = ?, favicon_url = ?, errimage_url = ?, preset_name = ?, active = ? 
                            WHERE id = ?");
    $stmt->bind_param("isissssssii",
                      $bg_image_enabled,
                      $bg_image_url,
                      $bg_blur,
                      $dark_mode_switch_enabled,
                      $default_mode,
                      $header_title,
                      $favicon_url,
                      $errimage_url,
                      $preset_name,
                      $active,
                      $preset_id);
    $stmt->execute();
    $stmt->close();
    
    // Store success message
    $message = "Preset settings saved successfully.";
}

// Load current preset data
$stmt = $conn->prepare("SELECT * FROM customization_settings WHERE id = ?");
$stmt->bind_param("i", $preset_id);
$stmt->execute();
$presetData = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch all presets (IDs 1 to 10) for the dropdown
$result = $conn->query("SELECT id, preset_name FROM customization_settings WHERE id BETWEEN 1 AND 10 ORDER BY id ASC");
$allPresets = $result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Settings</title>

  <!-- Favicon dynamically set -->
  <?php if (!empty($favicon_url)): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($favicon_url, ENT_QUOTES, 'UTF-8'); ?>" type="image/x-icon">
  <?php else: ?>
    <link rel="icon" href="../fav.svg" type="image/x-icon">
  <?php endif; ?>

  <script src="../tail.js"></script>
  <script>
    // Enable dark mode
    document.documentElement.classList.add('dark');
    
    // Switch displayed preset when selected
    function changePreset(selectObj) {
      var presetId = selectObj.value;
      window.location.href = "customization.php?preset_id=" + presetId;
    }
    
    // Update the displayed blur value
    function updateBlurValue(val) {
      document.getElementById('blurValue').innerText = val + " px";
    }
    
    // Fade out the success message after 3 seconds
    window.addEventListener('DOMContentLoaded', function() {
      var successMessage = document.getElementById('successMessage');
      if (successMessage) {
        setTimeout(function() {
          successMessage.style.opacity = "0";
          setTimeout(function() {
            successMessage.style.display = "none";
          }, 500);
        }, 3000);
      }
    });
  </script>
  <style>
    /* Additional transition styles for the success message */
    #successMessage {
      transition: opacity 0.5s ease;
    }
  </style>
</head>
<body class="bg-gray-900 text-gray-100">
  <div class="min-h-screen flex items-center justify-center p-6">
    <div class="w-full max-w-3xl">
      
      <!-- Heading and success message -->
      <div class="mb-8 text-center">
        <h1 class="text-4xl font-extrabold mb-2">Wish list design settings</h1>
        <?php if ($message): ?>
          <div id="successMessage" class="mx-auto max-w-md bg-green-600 text-white p-4 rounded shadow">
            <?php echo htmlspecialchars($message, ENT_QUOTES, "UTF-8"); ?>
          </div>
        <?php endif; ?>
      </div>
      
      <!-- Preset selection dropdown -->
      <div class="bg-gray-800 p-6 rounded-lg shadow-md mb-6">
        <label for="preset_selector" class="block text-lg font-semibold mb-2">Select preset</label>
        <select id="preset_selector" class="w-full p-3 rounded bg-gray-700 text-gray-100" onchange="changePreset(this)">
          <?php foreach ($allPresets as $preset): ?>
            <option value="<?= $preset['id']; ?>" <?= ($preset['id'] == $presetData['id']) ? 'selected' : ''; ?>>
              <?= htmlspecialchars($preset['preset_name'], ENT_QUOTES, 'UTF-8'); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <!-- Form for editing settings -->
      <form action="customization.php?preset_id=<?= $presetData['id']; ?>" method="POST">
        <input type="hidden" name="preset_id" value="<?= $presetData['id']; ?>">
        
        <!-- General settings -->
        <div class="bg-gray-800 p-6 rounded-lg shadow-md mb-6">
          <h2 class="text-2xl font-bold mb-4 border-b border-gray-700 pb-2">General settings</h2>
          <div class="mb-4">
            <label for="preset_name" class="block text-lg font-medium mb-1">Preset Name</label>
            <input type="text" id="preset_name" name="preset_name" value="<?= htmlspecialchars($presetData['preset_name'], ENT_QUOTES, 'UTF-8'); ?>" class="w-full p-3 rounded bg-gray-700 text-gray-100">
          </div>
          <div class="mb-4">
            <label for="header_title" class="block text-lg font-medium mb-1">Header Titel</label>
            <input type="text" id="header_title" name="header_title" value="<?= htmlspecialchars($presetData['header_title'], ENT_QUOTES, 'UTF-8'); ?>" class="w-full p-3 rounded bg-gray-700 text-gray-100">
          </div>
          <div class="mb-4">
            <label for="favicon_url" class="block text-lg font-medium mb-1">Favicon URL</label>
            <input type="text" id="favicon_url" name="favicon_url" value="<?= htmlspecialchars($presetData['favicon_url'], ENT_QUOTES, 'UTF-8'); ?>" class="w-full p-3 rounded bg-gray-700 text-gray-100">
          </div>
          <div class="mb-4">
            <label for="errimage_url" class="block text-lg font-medium mb-1">Error Image URL</label>
            <input type="text" id="errimage_url" name="errimage_url" value="<?= htmlspecialchars($presetData['errimage_url'], ENT_QUOTES, 'UTF-8'); ?>" class="w-full p-3 rounded bg-gray-700 text-gray-100">
          </div>
          <div class="flex items-center">
            <input type="checkbox" id="active" name="active" class="h-5 w-5 text-blue-500" <?= ($presetData['active'] == 1) ? 'checked' : ''; ?>>
            <label for="active" class="ml-2 text-lg font-medium">Activate this preset</label>
          </div>
        </div>
        
        <!-- Background settings -->
        <div class="bg-gray-800 p-6 rounded-lg shadow-md mb-6">
          <h2 class="text-2xl font-bold mb-4 border-b border-gray-700 pb-2">Background Settings</h2>
          <div class="flex items-center mb-4">
            <input type="checkbox" id="bg_image_enabled" name="bg_image_enabled" class="h-5 w-5 text-blue-500" <?= ($presetData['bg_image_enabled'] == 1) ? 'checked' : ''; ?>>
            <label for="bg_image_enabled" class="ml-2 text-lg">Enable background image</label>
          </div>
          <div class="mb-4">
            <label for="bg_image_url" class="block text-lg font-medium mb-1">Background image URL</label>
            <input type="text" id="bg_image_url" name="bg_image_url" placeholder="https://example.com/background.jpg" 
                   value="<?= htmlspecialchars($presetData['bg_image_url'], ENT_QUOTES, 'UTF-8'); ?>" class="w-full p-3 rounded bg-gray-700 text-gray-100">
          </div>
          <div class="mb-4">
            <label for="bg_blur" class="block text-lg font-medium mb-1">Background blur</label>
            <input type="range" id="bg_blur" name="bg_blur" min="0" max="20" step="1" 
                   value="<?= htmlspecialchars($presetData['bg_blur'], ENT_QUOTES, 'UTF-8'); ?>" oninput="updateBlurValue(this.value)" class="w-full">
            <p class="mt-2 text-sm text-gray-400">Current blur value: <span id="blurValue"><?= htmlspecialchars($presetData['bg_blur'], ENT_QUOTES, 'UTF-8'); ?> px</span></p>
          </div>
        </div>
        
        <!-- Design options -->
        <div class="bg-gray-800 p-6 rounded-lg shadow-md mb-6">
          <h2 class="text-2xl font-bold mb-4 border-b border-gray-700 pb-2">Design Options</h2>
          <div class="flex items-center mb-4">
            <input type="checkbox" id="dark_mode_switch_enabled" name="dark_mode_switch_enabled" class="h-5 w-5 text-blue-500" <?= ($presetData['dark_mode_switch_enabled'] == 1) ? 'checked' : ''; ?>>
            <label for="dark_mode_switch_enabled" class="ml-2 text-lg">Show dark mode switch</label>
          </div>
          <div class="mb-4">
            <label for="default_mode" class="block text-lg font-medium mb-1">Default mode</label>
            <select id="default_mode" name="default_mode" class="w-full p-3 rounded bg-gray-700 text-gray-100">
              <option value="light" <?= ($presetData['default_mode'] === 'light') ? 'selected' : ''; ?>>Light</option>
              <option value="dark" <?= ($presetData['default_mode'] === 'dark') ? 'selected' : ''; ?>>Dark</option>
              <option value="system" <?= ($presetData['default_mode'] === 'system') ? 'selected' : ''; ?>>System</option>
            </select>
          </div>
        </div>
        
        <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 rounded-lg text-xl font-semibold">
          Save
        </button>
      </form>
    </div>
  </div>

  <!-- Footer with dynamic year and linked text -->
  <footer class="text-center text-xs sm:text-sm py-4">
    © <span id="year"></span> <a href="https://www.hocunity.net" target="_blank" rel="noopener noreferrer" class="hover:underline">HocunityNET</a>. All rights reserved.
  </footer>
  
  <!-- Script to auto-set the current year -->
  <script>
    document.getElementById('year').textContent = new Date().getFullYear();
  </script>

</body>
</html>
