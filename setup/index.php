<?php
// 1) Show PHP errors
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 2) Init logs & flags
$logs      = [];
$hasError  = false;
$skipSetup = false;

// 3) Load version from file
$versionFile = __DIR__ . '/version.txt';
if (file_exists($versionFile)) {
    $setupVersion = trim(file_get_contents($versionFile));
    $logs[] = ['message' => "ℹ️ Loaded setup version from file: {$setupVersion}", 'type' => 'info'];
} else {
    $setupVersion = '0.0.0';
    $logs[] = ['message' => "❌ Version file not found at {$versionFile}, defaulting to {$setupVersion}", 'type' => 'error'];
}

// 4) Load your DB connection (expects: $conn = new mysqli(...))
require_once '../config.php';
if (!isset($conn) || $conn->connect_error) {
    $logs[]   = ['message' => '❌ Database connection failed: ' . ($conn->connect_error ?? 'unknown'), 'type' => 'error'];
    $hasError = true;
}

// 5) Helper functions
function logAndExec($conn, $sql) {
    global $logs;
    $logs[] = ['message' => "🔍 Executing SQL: $sql", 'type' => 'info'];
    $res = $conn->query($sql);
    $logs[] = $res
        ? ['message' => '✅ Success', 'type' => 'success']
        : ['message' => "❌ Error ({$conn->error})", 'type' => 'error'];
    return $res;
}
function logAndPrepare($conn, $sql) {
    global $logs;
    $logs[] = ['message' => "🔍 Preparing statement: $sql", 'type' => 'info'];
    $stmt = $conn->prepare($sql);
    $logs[] = $stmt
        ? ['message' => '✅ Prepared successfully', 'type' => 'success']
        : ['message' => "❌ Prepare error ({$conn->error})", 'type' => 'error'];
    return $stmt;
}

// 6) Ensure setup_status table + semver column exist
if (!$hasError) {
    // a) table
    logAndExec($conn, "
      CREATE TABLE IF NOT EXISTS setup_status (
        id INT PRIMARY KEY,
        executed TINYINT(1) NOT NULL DEFAULT 0,
        executed_at DATETIME DEFAULT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // b) version column (VARCHAR(20))
    $resCol = $conn->query("SHOW COLUMNS FROM setup_status LIKE 'version'");
    if ($resCol && $rowCol = $resCol->fetch_assoc()) {
        if (!preg_match('/varchar/i', $rowCol['Type'])) {
            logAndExec($conn, "
              ALTER TABLE setup_status
              MODIFY COLUMN version VARCHAR(20) NOT NULL DEFAULT '0.0.0'
            ");
        }
    } else {
        logAndExec($conn, "
          ALTER TABLE setup_status
          ADD COLUMN version VARCHAR(20) NOT NULL DEFAULT '0.0.0'
        ");
    }
    // c) fetch or init the single status row
    $stmt = logAndPrepare($conn, "
      SELECT executed, executed_at, version
      FROM setup_status
      WHERE id = ?
    ");
    $currentVersion = '0.0.0';
    if ($stmt) {
        $one = 1;
        $stmt->bind_param('i', $one);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $currentVersion = $row['version'];
            if (version_compare($currentVersion, $setupVersion, '>=') && intval($row['executed']) === 1) {
                $logs[]    = ['message' => "ℹ️ Already at v{$currentVersion} (≥ {$setupVersion}), skipping setup.", 'type' => 'info'];
                $skipSetup = true;
            }
        } else {
            logAndExec($conn, "
              INSERT INTO setup_status (id, executed, executed_at, version)
              VALUES (1, 0, NULL, '0.0.0')
            ");
        }
        $stmt->close();
    }
}

// 7) Table schemas
$schemas = [
  'wishlist' => [
    'create'  => "
      CREATE TABLE IF NOT EXISTS wishlist (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        price DECIMAL(10,2) NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    'columns' => [
      "image_url VARCHAR(255)",
      "product_url VARCHAR(255)",
      "is_favorite BOOLEAN DEFAULT FALSE",
      "position INT NOT NULL DEFAULT 0",
      /* NEU: individuelle Wunsch-Farbe (HEX) */
      "color_hex VARCHAR(7) NULL"
    ]
  ],
  'customization_settings' => [
    'create'  => "
      CREATE TABLE IF NOT EXISTS customization_settings (
        id INT PRIMARY KEY,
        bg_image_enabled TINYINT(1) NOT NULL DEFAULT 0,
        bg_image_url VARCHAR(255) NOT NULL DEFAULT '',
        dark_mode_switch_enabled TINYINT(1) NOT NULL DEFAULT 1,
        default_mode ENUM('light','dark','system') NOT NULL DEFAULT 'system',
        header_title VARCHAR(255) NOT NULL DEFAULT 'Wishlist NAME 🎁',
        favicon_url VARCHAR(255) NOT NULL DEFAULT '',
        errimage_url VARCHAR(255) NOT NULL DEFAULT '',
        bg_blur INT NOT NULL DEFAULT 0,
        preset_name VARCHAR(255) NOT NULL DEFAULT 'Preset NAME',
        active TINYINT(1) NOT NULL DEFAULT 0
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    'columns' => [
      /* NEU: Favoriten-Randfarbe per Preset (HEX, z.B. "#facc15") */
      "favorite_border_hex VARCHAR(7) NOT NULL DEFAULT '#facc15'"
    ]
  ]
];

// 8) Default presets (IDs 1–10)
$defaultData = [];
for ($i = 1; $i <= 10; $i++) {
    $defaultData[] = [
      'id'           => $i,
      'preset_name'  => "Preset-{$i}",
      'active'       => ($i === 1 ? 1 : 0)
    ];
}

// 9) Main setup execution
if (!$skipSetup && !$hasError) {
    // 9.1) Create tables & columns
    foreach ($schemas as $table => $cfg) {
        logAndExec($conn, trim($cfg['create']));
        foreach ($cfg['columns'] as $colDef) {
            $colName = explode(' ', trim($colDef))[0];
            logAndExec($conn, "SHOW COLUMNS FROM {$table} LIKE '{$colName}'");
            if ($conn->affected_rows === 0) {
                logAndExec($conn, "ALTER TABLE {$table} ADD COLUMN {$colDef}");
            } else {
                $logs[] = ['message' => "ℹ️ Column '{$colName}' already exists in '{$table}'.", 'type' => 'info'];
            }
        }
    }

    // 9.2) Insert default presets
    $insertSQL = "
      INSERT INTO customization_settings
        (id,preset_name,active,
         bg_image_enabled,bg_image_url,
         dark_mode_switch_enabled,default_mode,
         header_title,favicon_url,
         errimage_url,bg_blur)
      VALUES (?, ?, ?, 0, '', 1, 'system', 'Wishlist NAME 🎁', '', '', 0)
    ";
    $stmtIns = logAndPrepare($conn, $insertSQL);
    foreach ($defaultData as $row) {
        $stmtChk = logAndPrepare($conn, "SELECT id FROM customization_settings WHERE id = ?");
        if ($stmtChk) {
            $stmtChk->bind_param('i', $row['id']);
            $stmtChk->execute();
            if ($stmtChk->get_result()->num_rows === 0) {
                $stmtIns->bind_param('isi', $row['id'], $row['preset_name'], $row['active']);
                if ($stmtIns->execute()) {
                    $logs[] = ['message' => "✅ Inserted preset {$row['id']}", 'type' => 'success'];
                } else {
                    $logs[] = ['message' => "❌ Insert error ({$stmtIns->error})", 'type' => 'error'];
                }
            } else {
                $logs[] = ['message' => "ℹ️ Preset {$row['id']} already exists.", 'type' => 'info'];
            }
            $stmtChk->close();
        }
    }
    $stmtIns->close();

    // 9.3) Mark executed + record version
    logAndExec($conn, "
      UPDATE setup_status
      SET executed = 1,
          executed_at = NOW(),
          version = '{$setupVersion}'
      WHERE id = 1
    ");

    // 9.4) Final summary
    $logs[] = $conn->error
        ? ['message' => "❌ Final error: {$conn->error}", 'type' => 'error']
        : ['message' => "🎉 Setup v{$setupVersion} completed successfully!", 'type' => 'success'];
    $conn->close();
}

// 10) Render HTML + Tailwind Dark‑Mode
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Database Setup (v<?= htmlspecialchars($setupVersion) ?>)</title>
  <script>window.tailwind=window.tailwind||{};window.tailwind.config={darkMode:'class'}</script>
  <script src="../tail.js"></script>
</head>
<body class="bg-gray-900 text-gray-100">
  <div class="max-w-3xl mx-auto p-6">
    <h1 class="text-3xl font-bold text-center mb-6">🚀 Database Setup Log (v<?= htmlspecialchars($setupVersion) ?>)</h1>
    <div class="bg-gray-800 rounded-lg shadow p-4 overflow-y-auto h-96">
      <ul class="space-y-2 font-mono">
        <?php foreach ($logs as $log):
            $color = match($log['type']) {
              'success' => 'text-green-400',
              'error'   => 'text-red-400',
              default   => 'text-blue-400',
            };
        ?>
          <li class="<?= $color ?>"><?= htmlspecialchars($log['message'], ENT_QUOTES) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</body>
</html>
